<?php

namespace App\Services\Receptions;

use App\Enums\Course;
use App\Models\MealOccasion;
use App\Models\PlannedMeal;
use App\Models\Reminder;
use App\Services\Planning\PrepReminderPlanner;
use App\Support\Duration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Réceptions (module 21) : le menu d'un repas avec invités et son rétroplanning.
 *
 * Le rétroplanning ne se saisit pas : il se déduit des plats du menu — leurs temps de
 * préparation, de cuisson et de repos, les rappels de préparation anticipée (R21), l'heure
 * du repas et la place de chaque plat (l'entrée est servie avant le dessert).
 *
 *   début d'un plat = moment où il est servi − repos − cuisson − préparation
 *
 * Les heures sont indicatives : c'est une liste de choses à ne pas oublier, pas un minutage.
 */
class ReceptionPlanner
{
    public const BUCKETS = [
        'before' => 'Les jours d\'avant',
        'eve' => 'La veille',
        'morning' => 'Le jour même, le matin',
        'afternoon' => 'Le jour même, ensuite',
        'last' => 'La dernière heure',
        'during' => 'Pendant le repas',
    ];

    /** Une cuisson plus courte ne mérite pas sa propre ligne. */
    private const COOK_TASK_MINUTES = 15;

    /** Les tâches prévues au moins une demi-heure avant le repas deviennent des rappels (cloche, téléphone). */
    public const REMINDER_LEAD_MINUTES = 30;

    public function __construct(private readonly PrepReminderPlanner $prep) {}

    /** Une case avec des invités ou une occasion nommée est une réception. */
    public function isReception(MealOccasion $occasion): bool
    {
        return $occasion->guestCount() > 0 || filled($occasion->title);
    }

    /**
     * Plats de la case, rangés par place dans le menu.
     *
     * @return Collection<int, array{course: Course|null, label: string, meals: Collection<int, PlannedMeal>}>
     */
    public function courses(MealOccasion $occasion): Collection
    {
        $meals = $occasion->meals();

        $groups = collect(Course::ordered())
            ->map(fn (Course $course) => ['course' => $course, 'label' => $course->label(), 'meals' => $meals->filter(fn (PlannedMeal $m) => $m->course === $course)->values()])
            ->filter(fn (array $group) => $group['meals']->isNotEmpty());

        $unplaced = $meals->filter(fn (PlannedMeal $m) => $m->course === null)->values();

        if ($unplaced->isNotEmpty()) {
            $groups->push(['course' => null, 'label' => $groups->isEmpty() ? 'Au menu' : 'Autres plats', 'meals' => $unplaced]);
        }

        return $groups->values();
    }

    /** Moment où un plat est servi. */
    public function momentOf(PlannedMeal $meal, MealOccasion $occasion): Carbon
    {
        return $occasion->serveAt()->addMinutes(($meal->course ?? Course::Main)->serveOffset());
    }

    /**
     * Rétroplanning complet, du plus tôt au plus tard.
     *
     * @param  array<int, string>|null  $frozen  ingrédients uniquement au congélateur (R21)
     * @return Collection<int, array{key: string, at: Carbon, title: string, detail: string|null, kind: string, bucket: string, meal_id: int|null, done: bool}>
     */
    public function timeline(MealOccasion $occasion, ?array $frozen = null): Collection
    {
        $frozen ??= $this->prep->frozenIngredients();
        $serve = $occasion->serveAt();
        $meals = $occasion->meals()->filter(fn (PlannedMeal $m) => $m->eatenRecipe() !== null);
        $tasks = collect();

        if ($meals->isEmpty()) {
            return $tasks;
        }

        $firstMealId = $meals->first()->id;

        // Les courses, deux jours avant (assez tôt pour un oubli, assez tard pour le frais).
        if ($meals->contains(fn (PlannedMeal $m) => $m->isRecipe() && ! $m->isPrepared())) {
            $tasks->push($this->task('shopping', $serve->copy()->subDays(2)->setTime(18, 0), 'Faire les courses de la réception',
                'Vérifiez la liste de courses de la semaine : les plats du menu y sont.', 'shopping', $firstMealId));
        }

        foreach ($meals as $meal) {
            $recipe = $meal->eatenRecipe();
            $moment = $this->momentOf($meal, $occasion);
            $name = $recipe->title;

            // Rappels de préparation anticipée du plat (R21) : décongeler, tremper, mariner, pâte à faire la veille…
            foreach ($this->prep->expectedFor($meal, $frozen, withReception: false) as $key => $reminder) {
                $tasks->push($this->task('r21:'.$meal->id.':'.$key, $reminder['due_at'], $reminder['title'], $reminder['detail'], 'r21', $meal->id));
            }

            // Plat cuisiné à l'avance (14.8) ou restes : il n'y a plus qu'à réchauffer.
            if ($meal->isPrepared() || $meal->isLeftover()) {
                $tasks->push($this->task('reheat:'.$meal->id, $moment->copy()->subMinutes(20), 'Réchauffer : '.$name,
                    'Plat préparé à l\'avance.', 'reheat', $meal->id));

                continue;
            }

            $prep = (int) $recipe->prep_minutes;
            $cook = (int) $recipe->cook_minutes;
            $rest = (int) $recipe->rest_minutes;

            // Un repos long est déjà un rappel R21 (« préparer la veille ») : on ne le compte pas deux fois.
            $longRest = $rest >= PrepReminderPlanner::LONG_REST_MINUTES;
            $restBefore = $longRest ? 0 : $rest;
            $total = $prep + $cook + $restBefore;

            if ($longRest) {
                $tasks->push($this->task('serve:'.$meal->id, $moment->copy()->subMinutes(15), 'Dresser : '.$name,
                    'Préparé à l\'avance (repos de '.Duration::format($rest).').', 'start', $meal->id));

                continue;
            }

            if ($total === 0) {
                $tasks->push($this->task('start:'.$meal->id, $serve->copy()->subHour(), 'Préparer : '.$name,
                    'Durées non renseignées dans la recette : heure indicative.', 'start', $meal->id));

                continue;
            }

            $details = array_filter([
                $prep ? 'préparation '.Duration::format($prep) : null,
                $cook ? 'cuisson '.Duration::format($cook) : null,
                $restBefore ? 'repos '.Duration::format($restBefore) : null,
            ]);

            // Ce qui demande d'avoir les mains dans la cuisine se fait avant l'arrivée des invités ;
            // seules la cuisson et le repos peuvent se dérouler pendant l'apéritif ou l'entrée.
            $start = $moment->copy()->subMinutes($total)->min($serve->copy()->subMinutes($prep));

            $tasks->push($this->task('start:'.$meal->id, $start, 'Commencer : '.$name,
                ucfirst(implode(', ', $details)).' — à servir vers '.$moment->format('H:i').'.', 'start', $meal->id));

            // Heure de mise en cuisson : seulement quand elle est sans ambiguïté (sans repos, on ne sait pas
            // si le repos vient avant la cuisson — une pâte — ou après — un gâteau qui refroidit).
            if ($cook >= self::COOK_TASK_MINUTES && $prep > 0 && $restBefore === 0) {
                $tasks->push($this->task('cook:'.$meal->id, $moment->copy()->subMinutes($cook), 'Lancer la cuisson : '.$name,
                    Duration::format($cook).' de cuisson.', 'cook', $meal->id));
            }
        }

        // Le fromage se sert à température ambiante.
        if ($cheese = $meals->first(fn (PlannedMeal $m) => $m->course === Course::Cheese)) {
            $tasks->push($this->task('cheese', $this->momentOf($cheese, $occasion)->subHour(), 'Sortir le fromage du réfrigérateur', null, 'cheese', $cheese->id));
        }

        if ($meals->contains(fn (PlannedMeal $m) => $m->course === Course::Aperitif)) {
            $tasks->push($this->task('drinks', $serve->copy()->subHours(4), 'Mettre les boissons au frais', null, 'table', $firstMealId));
        }

        $tasks->push($this->task('table', $serve->copy()->subHour(), 'Mettre la table', $occasion->guestCount() > 0 ? $this->diners($occasion).' couverts.' : null, 'table', $firstMealId));

        $done = $this->doneKeys($occasion);

        return $tasks
            ->map(fn (array $task) => [...$task, 'bucket' => $this->bucket($task['at'], $serve), 'done' => in_array($task['key'], $done, true)])
            ->sortBy(fn (array $task) => $task['at']->timestamp.'-'.$task['key'])
            ->values();
    }

    /** @return Collection<string, Collection<int, array>> tâches par période */
    public function grouped(MealOccasion $occasion): Collection
    {
        $timeline = $this->timeline($occasion)->groupBy('bucket');

        return collect(self::BUCKETS)
            ->filter(fn ($label, $bucket) => $timeline->has($bucket))
            ->map(fn ($label, $bucket) => $timeline->get($bucket));
    }

    public function toggle(MealOccasion $occasion, string $key): bool
    {
        $done = $occasion->timeline_done ?? [];
        $isDone = in_array($key, $done, true);
        $done = $isDone ? array_values(array_diff($done, [$key])) : [...$done, $key];

        $occasion->update(['timeline_done' => $done ?: null]);

        // Le rappel correspondant (cloche, téléphone) suit.
        if (str_starts_with($key, 'r21:')) {
            [, $mealId, $reminderKey] = explode(':', $key, 3);
            $mealIds = [(int) $mealId];
        } else {
            $reminderKey = 'reception:'.$key;
            $mealIds = $occasion->meals()->pluck('id')->all();
        }

        Reminder::query()
            ->whereIn('planned_meal_id', $mealIds)
            ->where('key', $reminderKey)
            ->update(['status' => $isDone ? Reminder::PENDING : Reminder::DONE, 'handled_at' => $isDone ? null : now()]);

        return ! $isDone;
    }

    /**
     * Tâches de la réception qui deviennent des rappels pour un plat donné (R21, ligne « réception »).
     * Les rappels R21 du plat existent déjà ; on n'ajoute que le reste, et seulement ce qui se prépare
     * assez tôt pour être oublié.
     *
     * @param  array<int, string>  $frozen
     * @return array<string, array{type: string, title: string, detail: string|null, due_at: Carbon}>
     */
    public function reminderTasks(PlannedMeal $meal, MealOccasion $occasion, array $frozen): array
    {
        if (! $this->isReception($occasion) || ! $occasion->serve_time) {
            return [];
        }

        $limit = $occasion->serveAt()->subMinutes(self::REMINDER_LEAD_MINUTES);
        $label = $occasion->title ?: 'Réception';

        return $this->timeline($occasion, $frozen)
            ->filter(fn (array $task) => $task['meal_id'] === $meal->id && $task['kind'] !== 'r21' && ! $task['done'] && $task['at']->lte($limit))
            ->mapWithKeys(fn (array $task) => ['reception:'.$task['key'] => [
                'type' => \App\Enums\ReminderType::Reception->value,
                'title' => $task['title'],
                'detail' => $label.' · '.$occasion->serveAt()->locale('fr')->isoFormat('dddd D MMMM [à] HH[h]mm'),
                'due_at' => $task['at'],
            ]])
            ->all();
    }

    /* ================================================================ Carte de menu (21.3) */

    /** Menu en texte, à envoyer aux invités. */
    public function menuText(MealOccasion $occasion): string
    {
        $lines = [];
        $lines[] = ($occasion->title ?: 'Menu').' — '.$occasion->serveAt()->locale('fr')->isoFormat('dddd D MMMM YYYY').($occasion->serve_time ? ' à '.str_replace(':', ' h ', $occasion->serve_time) : '');
        $lines[] = '';

        foreach ($this->courses($occasion) as $group) {
            $lines[] = $group['label'];

            foreach ($group['meals'] as $meal) {
                $lines[] = '  · '.($meal->eatenRecipe()?->title ?? $meal->label());
            }
        }

        if ($occasion->menu_message) {
            $lines[] = '';
            $lines[] = $occasion->menu_message;
        }

        return implode("\n", $lines);
    }

    /* ================================================================ Outils */

    /** Couverts : nombre de personnes à table (lot 32 : les portions, elles, suivent l'appétit). */
    public function diners(MealOccasion $occasion): int
    {
        return app(\App\Services\Planning\OccasionService::class)->people($occasion);
    }

    /** @return list<string> */
    private function doneKeys(MealOccasion $occasion): array
    {
        $done = $occasion->timeline_done ?? [];

        // Un rappel marqué fait depuis la cloche coche aussi la tâche.
        $fromReminders = Reminder::query()
            ->whereIn('planned_meal_id', $occasion->meals()->pluck('id'))
            ->where('status', Reminder::DONE)
            ->get(['planned_meal_id', 'key'])
            ->map(fn (Reminder $r) => str_starts_with($r->key, 'reception:')
                ? substr($r->key, strlen('reception:'))
                : 'r21:'.$r->planned_meal_id.':'.$r->key)
            ->all();

        return [...$done, ...$fromReminders];
    }

    private function bucket(Carbon $at, Carbon $serve): string
    {
        $days = $at->copy()->startOfDay()->diffInDays($serve->copy()->startOfDay(), false);

        return match (true) {
            $days >= 2 => 'before',
            $days >= 1 => 'eve',
            $at->gte($serve) => 'during',
            $at->gte($serve->copy()->subHour()) => 'last',
            $at->hour < 12 => 'morning',
            default => 'afternoon',
        };
    }

    /** @return array{key: string, at: Carbon, title: string, detail: string|null, kind: string, meal_id: int|null} */
    private function task(string $key, Carbon $at, string $title, ?string $detail, string $kind, ?int $mealId): array
    {
        return ['key' => $key, 'at' => $at->copy()->second(0), 'title' => $title, 'detail' => $detail, 'kind' => $kind, 'meal_id' => $mealId];
    }
}
