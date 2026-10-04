<?php

namespace App\Services\Planning;

use App\Enums\MealType;
use App\Models\PlannedMeal;
use App\Models\WeekTemplate;
use App\Models\WeekTemplateMeal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Semaines types (14.3, règle R16).
 *
 * Une semaine type retient, pour chaque jour de la semaine et chaque créneau, ce qui était prévu.
 * À l'application, les portions sont recalculées d'après les convives de la semaine d'arrivée
 * (et non celles du modèle) et les recettes archivées entre-temps sont signalées.
 */
class WeekTemplateManager
{
    public function __construct(
        private readonly WeekPlanner $planner,
        private readonly OccasionService $occasions,
        private readonly GuestCompatibility $compatibility,
    ) {}

    /** Enregistre la semaine donnée comme modèle. */
    public function saveFromWeek(Carbon $weekStart, string $name, ?string $notes = null): WeekTemplate
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('Donnez un nom à la semaine type.');
        }

        $weekStart = $this->planner->weekStart($weekStart);
        $meals = $this->planner->mealsForWeek($weekStart);

        if ($meals->isEmpty()) {
            throw new InvalidArgumentException('Cette semaine est vide : il n\'y a rien à enregistrer.');
        }

        return DB::transaction(function () use ($name, $notes, $meals) {
            $template = WeekTemplate::create(['name' => mb_substr($name, 0, 80), 'notes' => $notes, 'created_by' => auth()->id()]);
            $cells = $meals->keyBy('id');

            foreach ($meals as $meal) {
                $source = $meal->isLeftover() ? $cells->get($meal->leftover_of_id) : null;

                // Restes dont le repas d'origine est hors de la semaine : ils n'ont pas de sens dans un modèle.
                if ($meal->isLeftover() && ! $source) {
                    continue;
                }

                WeekTemplateMeal::create([
                    'week_template_id' => $template->id,
                    'weekday' => $meal->date->dayOfWeekIso,
                    'meal_slot_id' => $meal->meal_slot_id,
                    'position' => $meal->position,
                    'type' => $meal->type->value,
                    'recipe_id' => $meal->recipe_id,
                    'free_text' => $meal->free_text,
                    'leftover_weekday' => $source?->date->dayOfWeekIso,
                    'leftover_meal_slot_id' => $source?->meal_slot_id,
                ]);
            }

            return $template->fresh();
        });
    }

    /**
     * Applique un modèle à une semaine.
     *
     * @param  string  $mode  fill = ne remplit que les cases vides · replace = vide la semaine d'abord
     * @return array{placed: int, skipped: list<string>, warnings: list<string>, removed: int}
     */
    public function apply(WeekTemplate $template, Carbon $weekStart, string $mode = 'fill'): array
    {
        $weekStart = $this->planner->weekStart($weekStart);
        $template->loadMissing('meals.recipe', 'meals.slot');

        return DB::transaction(function () use ($template, $weekStart, $mode) {
            $removed = $mode === 'replace' ? $this->planner->clearWeek($weekStart) : 0;

            $existing = $this->planner->mealsForWeek($weekStart);
            $taken = $existing->map(fn (PlannedMeal $m) => $m->date->toDateString().'|'.$m->meal_slot_id)->flip();
            $guests = $this->occasions->forRange($weekStart, $weekStart->copy()->addDays(6));
            $leftoverNeeds = $this->leftoverNeeds($template, $weekStart);

            $placed = 0;
            $skipped = [];
            $warnings = [];
            $created = [];   // « jour|créneau » du modèle => repas créé (pour les restes)

            // Les recettes et repas libres d'abord : les restes s'y rattachent ensuite.
            foreach ($template->meals->sortBy(fn (WeekTemplateMeal $m) => [$m->type === MealType::Leftover ? 1 : 0, $m->weekday, $m->position]) as $meal) {
                $date = $weekStart->copy()->addDays($meal->weekday - 1);
                $cell = $date->toDateString().'|'.$meal->meal_slot_id;

                if ($taken->has($cell)) {
                    $skipped[] = $this->describe($meal, $date).' : la case est déjà occupée';

                    continue;
                }

                if ($meal->type === MealType::Recipe) {
                    if (! $meal->recipe || $meal->recipe->isArchived()) {
                        $skipped[] = $this->describe($meal, $date).' : recette archivée ou supprimée';

                        continue;
                    }

                    // Portions = convives de la case d'arrivée, plus ce que les restes du modèle réclament.
                    $servings = $this->occasions->servingsAt($date, $meal->meal_slot_id)
                        + ($leftoverNeeds[$meal->weekday.'|'.$meal->meal_slot_id] ?? 0);

                    $created[$meal->weekday.'|'.$meal->meal_slot_id] = $this->planner->addRecipe($date, $meal->meal_slot_id, $meal->recipe_id, min(50, $servings));
                    $warnings = [...$warnings, ...$this->guestWarnings($meal, $date, $guests)];
                } elseif ($meal->type === MealType::Free) {
                    $this->planner->addFree($date, $meal->meal_slot_id, (string) $meal->free_text);
                } else {
                    $source = $created[$meal->leftover_weekday.'|'.$meal->leftover_meal_slot_id] ?? null;

                    if (! $source) {
                        $skipped[] = $this->describe($meal, $date).' : le repas d\'origine n\'a pas été placé';

                        continue;
                    }

                    $remaining = $this->planner->remainingLeftovers($source->fresh());

                    if ($remaining < 1) {
                        $skipped[] = $this->describe($meal, $date).' : il ne reste pas de portions';

                        continue;
                    }

                    $this->planner->addLeftover($date, $meal->meal_slot_id, $source, min($remaining, $this->occasions->servingsAt($date, $meal->meal_slot_id)));
                }

                $taken->put($cell, true);
                $placed++;
            }

            return ['placed' => $placed, 'skipped' => $skipped, 'warnings' => $warnings, 'removed' => $removed];
        });
    }

    /**
     * Portions supplémentaires à cuisiner pour que les restes prévus par le modèle tiennent.
     *
     * @return array<string, int> « jour|créneau » du repas d'origine => portions
     */
    private function leftoverNeeds(WeekTemplate $template, Carbon $weekStart): array
    {
        $needs = [];

        foreach ($template->meals->where('type', MealType::Leftover) as $meal) {
            if (! $meal->leftover_weekday) {
                continue;
            }

            $date = $weekStart->copy()->addDays($meal->weekday - 1);
            $key = $meal->leftover_weekday.'|'.$meal->leftover_meal_slot_id;
            $needs[$key] = ($needs[$key] ?? 0) + $this->occasions->servingsAt($date, $meal->meal_slot_id);
        }

        return $needs;
    }

    /** @return list<string> */
    private function guestWarnings(WeekTemplateMeal $meal, Carbon $date, $guests): array
    {
        $occasion = $guests->get($date->toDateString().'|'.$meal->meal_slot_id);
        $eaters = app(HouseholdService::class)->eaters($occasion);

        if ($eaters->isEmpty() || ! $meal->recipe) {
            return [];
        }

        return collect($this->compatibility->conflicts($meal->recipe, $eaters))
            ->map(fn (array $c) => $this->describe($meal, $date).' — '.$c['guest'].' : '.mb_strtolower($c['subject']))
            ->values()->all();
    }

    private function describe(WeekTemplateMeal $meal, Carbon $date): string
    {
        return $date->locale('fr')->isoFormat('ddd D').' '.mb_strtolower($meal->slot?->name ?? '').' · '.$meal->label();
    }
}
