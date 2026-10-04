<?php

namespace App\Services\Planning;

use App\Enums\Course;
use App\Models\MealOccasion;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Services\Receptions\ReceptionPlanner;
use App\Services\Recipes\StepTimers;
use App\Support\Duration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cuisiner un repas complet (lot 31, 31.3) : les étapes de tous les plats d'une case,
 * **entrelacées** pour que tout soit prêt à l'heure.
 *
 * Même principe que le rétroplanning des réceptions (lot 20) : on part du moment où chaque plat
 * est servi et on remonte le temps. Ici, on descend jusqu'aux **étapes** :
 *
 *  - une étape qui annonce une durée (« cuire 25 min ») dure ce temps-là ;
 *  - le reste du temps de la recette (préparation + cuisson + repos, moins ces durées) se partage
 *    entre les autres étapes, 3 minutes au moins chacune ;
 *  - la dernière étape d'un plat se termine quand il est servi.
 *
 * Moment où un plat est servi : pour un repas ordinaire, l'entrée à l'heure du repas, le plat
 * 15 minutes après, le fromage à 35, le dessert à 45. Pour une réception (lot 20), les écarts des
 * réceptions. Restes et plats cuisinés à l'avance : une seule étape, « Réchauffer », 20 minutes.
 *
 * Les heures sont indicatives, comme dans le rétroplanning.
 */
class MealCookPlan
{
    /** Minutes après l'heure du repas où chaque plat est servi, pour un repas ordinaire. */
    public const FAMILY_OFFSETS = ['aperitif' => 0, 'entree' => 0, 'plat' => 15, 'fromage' => 35, 'dessert' => 45];

    public const MIN_STEP_MINUTES = 3;

    public const DEFAULT_STEP_MINUTES = 5;

    public const REHEAT_MINUTES = 20;

    public function __construct(
        private readonly StepTimers $timers,
        private readonly ReceptionPlanner $receptions,
    ) {}

    /** @return Collection<int, PlannedMeal> plats de la case (recettes et restes), dans l'ordre du menu */
    public function meals(Carbon|string $date, MealSlot|int $slot): Collection
    {
        $slotId = $slot instanceof MealSlot ? $slot->id : $slot;

        return PlannedMeal::query()
            ->whereDate('date', Carbon::parse($date)->toDateString())
            ->where('meal_slot_id', $slotId)
            ->whereIn('type', ['recipe', 'leftover'])
            ->with(['recipe.steps', 'recipe.photos', 'leftoverOf.recipe.steps', 'slot', 'cook:id,name'])
            ->orderBy('position')
            ->get()
            ->filter(fn (PlannedMeal $meal) => $meal->eatenRecipe() !== null)
            ->sortBy(fn (PlannedMeal $meal) => [($meal->course ?? Course::Main)->order(), $meal->position])
            ->values();
    }

    /** Heure du repas : celle de la réception si elle est notée, sinon l'heure habituelle du créneau. */
    public function serveAt(Carbon|string $date, MealSlot $slot, ?MealOccasion $occasion = null): Carbon
    {
        if ($occasion) {
            return $occasion->serveAt();
        }

        [$hour, $minute] = MealOccasion::defaultTime($slot->name);

        return Carbon::parse($date)->setTime($hour, $minute);
    }

    /** Moment où un plat est servi. */
    public function momentOf(PlannedMeal $meal, Carbon $serve, bool $reception): Carbon
    {
        $course = $meal->course ?? Course::Main;
        $offset = $reception ? $course->serveOffset() : (self::FAMILY_OFFSETS[$course->value] ?? 15);

        return $serve->copy()->addMinutes($offset);
    }

    /**
     * Les étapes de tous les plats, de la première à la dernière.
     *
     * @param  Collection<int, PlannedMeal>  $meals
     * @return array{tasks: Collection<int, array>, dishes: Collection<int, array>}
     */
    public function build(Collection $meals, Carbon $serve, bool $reception = false): array
    {
        $tasks = collect();
        $dishes = collect();

        foreach ($meals->values() as $index => $meal) {
            $recipe = $meal->eatenRecipe();
            $moment = $this->momentOf($meal, $serve, $reception);
            $dishTasks = $meal->isLeftover() || $meal->isPrepared()
                ? $this->reheat($meal, $moment)
                : $this->steps($meal, $moment);

            $dishes->push([
                'meal' => $meal,
                'recipe' => $recipe,
                'index' => $index,
                'course' => $meal->course?->label(),
                'moment' => $moment,
                'start' => $dishTasks->min('start') ?? $moment,
                'reheat' => $meal->isLeftover() || $meal->isPrepared(),
            ]);

            $tasks = $tasks->merge($dishTasks->map(fn (array $task) => [...$task, 'dish' => $index]));
        }

        return [
            // À égalité d'heure, l'ordre du menu puis celui des étapes.
            'tasks' => $tasks->sortBy(fn (array $task) => [$task['start']->timestamp, $task['dish'], $task['number']])->values(),
            'dishes' => $dishes,
        ];
    }

    /** @return Collection<int, array> */
    private function steps(PlannedMeal $meal, Carbon $moment): Collection
    {
        $recipe = $meal->eatenRecipe();
        $steps = $recipe->steps->values();

        if ($steps->isEmpty()) {
            return collect([$this->task($meal, 1, 1, 'Préparer : '.$recipe->title.' (aucune étape dans la recette).',
                $moment->copy()->subMinutes(max(self::DEFAULT_STEP_MINUTES, (int) $recipe->total_minutes ?: 30)), max(self::DEFAULT_STEP_MINUTES, (int) $recipe->total_minutes ?: 30), [])]);
        }

        $timers = $steps->map(fn ($step) => $this->timers->extract($step->instruction));
        // Une étape qui annonce une durée dure la plus longue qu'elle cite (« 10 min puis 25 min » : on garde 25).
        $timed = $timers->map(fn (array $list) => $list === [] ? null : max(array_column($list, 'minutes')));
        $total = (int) $recipe->prep_minutes + (int) $recipe->cook_minutes + (int) $recipe->rest_minutes;
        $untimedCount = $timed->filter(fn ($minutes) => $minutes === null)->count();
        $remaining = max(0, $total - (int) $timed->sum());
        $share = $untimedCount === 0 ? 0
            : ($total > 0 ? max(self::MIN_STEP_MINUTES, (int) round($remaining / $untimedCount)) : self::DEFAULT_STEP_MINUTES);

        // On remonte le temps depuis le moment où le plat est servi.
        $end = $moment->copy();
        $tasks = [];

        foreach ($steps->reverse() as $i => $step) {
            $minutes = $timed[$i] ?? $share;
            $start = $end->copy()->subMinutes($minutes);
            $tasks[] = $this->task($meal, $i + 1, $steps->count(), $step->instruction, $start, $minutes, $timers[$i], $step->group_name);
            $end = $start;
        }

        return collect(array_reverse($tasks));
    }

    /** @return Collection<int, array> */
    private function reheat(PlannedMeal $meal, Carbon $moment): Collection
    {
        $title = $meal->eatenRecipe()->title;
        $text = $meal->isLeftover() ? 'Réchauffer les restes : '.$title.'.' : 'Réchauffer : '.$title.' (cuisiné à l\'avance).';

        return collect([$this->task($meal, 1, 1, $text, $moment->copy()->subMinutes(self::REHEAT_MINUTES), self::REHEAT_MINUTES,
            [['minutes' => self::REHEAT_MINUTES, 'label' => Duration::format(self::REHEAT_MINUTES)]])]);
    }

    private function task(PlannedMeal $meal, int $number, int $count, string $text, Carbon $start, int $minutes, array $timers, ?string $group = null): array
    {
        return [
            'key' => $meal->id.'-'.$number,
            'meal_id' => $meal->id,
            'title' => $meal->eatenRecipe()->title,
            'number' => $number,
            'count' => $count,
            'group' => $group,
            'text' => $text,
            'start' => $start,
            'minutes' => $minutes,
            'timers' => $timers,
        ];
    }
}
