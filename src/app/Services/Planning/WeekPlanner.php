<?php

namespace App\Services\Planning;

use App\Enums\MealType;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Règles métier du planning de la semaine.
 *
 * Une « case » est un couple (date, créneau). Une case peut contenir plusieurs éléments,
 * ordonnés par `position` (1, 2, 3…).
 */
class WeekPlanner
{
    /* ================================================================ Semaines */

    /** Lundi de la semaine contenant $date. */
    public function weekStart(Carbon|string|null $date = null): Carbon
    {
        $date = $date instanceof Carbon ? $date->copy() : Carbon::parse($date ?? 'today');

        return $date->startOfDay()->startOfWeek(Carbon::MONDAY);
    }

    /** @return Collection<int, Carbon> les 7 jours de la semaine */
    public function days(Carbon $weekStart): Collection
    {
        return collect(range(0, 6))->map(fn (int $i) => $weekStart->copy()->addDays($i));
    }

    /** @return Collection<int, PlannedMeal> repas de la semaine, relations chargées */
    public function mealsForWeek(Carbon $weekStart): Collection
    {
        return PlannedMeal::query()
            ->between($weekStart, $weekStart->copy()->addDays(6))
            ->with(['recipe.tags', 'leftoverOf.recipe', 'slot', 'forPerson'])
            ->orderBy('date')->orderBy('meal_slot_id')->orderBy('position')
            ->get();
    }

    /* ================================================================ Ajout */

    public function addRecipe(Carbon|string $date, MealSlot|int $slot, Recipe|int $recipe, int|float|null $servings = null, ?string $comment = null): PlannedMeal
    {
        $recipeId = $recipe instanceof Recipe ? $recipe->id : $recipe;

        return $this->create($date, $slot, [
            'type' => MealType::Recipe,
            'recipe_id' => $recipeId,
            'servings' => $this->validServings($servings ?? $this->occasions()->servingsAt($date, $slot)),
            'comment' => $comment,
        ]);
    }

    public function addFree(Carbon|string $date, MealSlot|int $slot, string $text, ?string $comment = null): PlannedMeal
    {
        $text = trim($text);

        if ($text === '') {
            throw new InvalidArgumentException('Le texte du repas est vide.');
        }

        return $this->create($date, $slot, [
            'type' => MealType::Free,
            'free_text' => mb_substr($text, 0, 200),
            'servings' => $this->occasions()->servingsAt($date, $slot),
            'comment' => $comment,
        ]);
    }

    /**
     * Place les restes d'un repas (recette) dans une case.
     *
     * @throws InvalidArgumentException si le repas source n'est pas une recette ou n'a plus assez de restes
     */
    /**
     * Place des restes. Avec $forPersonId, ce sont les restes emportés en **gamelle** par cette
     * personne du foyer (32.3, compte ou pas) : sa portion seulement, par défaut.
     */
    public function addLeftover(Carbon|string $date, MealSlot|int $slot, PlannedMeal $source, int|float|null $servings = null, ?int $forPersonId = null): PlannedMeal
    {
        if (! $source->isRecipe()) {
            throw new InvalidArgumentException('Seul un repas « recette » peut avoir des restes.');
        }

        $forPersonId = $this->lunchboxOwner($forPersonId);
        $default = $forPersonId !== null
            ? app(Appetites::class)->portionFor($forPersonId)
            : $this->occasions()->servingsAt($date, $slot);
        $servings = $this->validServings($servings ?? min($default, max(0.5, $this->remainingLeftovers($source))));

        if ($servings > $this->remainingLeftovers($source) + 0.001) {
            throw new InvalidArgumentException('Il ne reste pas assez de portions de ce repas.');
        }

        if (Carbon::parse($date)->lt($source->date)) {
            throw new InvalidArgumentException('Les restes ne peuvent pas être placés avant le repas d\'origine.');
        }

        return $this->create($date, $slot, [
            'type' => MealType::Leftover,
            'leftover_of_id' => $source->id,
            'servings' => $servings,
            'for_person_id' => $forPersonId,
            'is_lunchbox' => $forPersonId !== null,
        ]);
    }

    /**
     * Fait d'un reste la gamelle d'une personne, ou le rend à toute la table ($personId null).
     * Les portions ne changent pas : on les ajuste à part si besoin.
     */
    public function setLunchbox(PlannedMeal $meal, ?int $personId): PlannedMeal
    {
        if (! $meal->isLeftover()) {
            throw new InvalidArgumentException('Seuls des restes peuvent partir en gamelle.');
        }

        $personId = $this->lunchboxOwner($personId);
        $meal->update(['for_person_id' => $personId, 'is_lunchbox' => $personId !== null]);

        return $meal->fresh(['forPerson']);
    }

    private function lunchboxOwner(?int $personId): ?int
    {
        if ($personId === null) {
            return null;
        }

        if (! \App\Models\HouseholdPerson::query()->whereKey($personId)->exists()) {
            throw new InvalidArgumentException('Cette personne ne fait pas partie du foyer.');
        }

        return $personId;
    }

    /* ================================================================ Restes */

    public function householdSize(): int
    {
        return $this->occasions()->householdSize();
    }

    /** Portions mangées au repas (règles R7, R33) : foyer par défaut, ou convives saisis pour la case. */
    public function dinersOf(PlannedMeal $meal): float
    {
        return $this->occasions()->dinersAt($meal->date, $meal->meal_slot_id);
    }

    /**
     * Portions restantes d'un repas « recette » :
     * portions cuisinées − portions mangées au repas (convives) − restes déjà placés.
     */
    public function remainingLeftovers(PlannedMeal $meal): float
    {
        if (! $meal->isRecipe()) {
            return 0.0;
        }

        $eatenAtMeal = min($meal->servings, $this->dinersOf($meal));
        $alreadyPlaced = (float) $meal->leftovers()->sum('servings');

        return max(0.0, round($meal->servings - $eatenAtMeal - $alreadyPlaced, 1));
    }

    /**
     * Repas (recettes) ayant encore des restes, cuisinés dans les $days jours précédant $date (inclus).
     *
     * @return Collection<int, array{meal: PlannedMeal, remaining: int}>
     */
    public function availableLeftovers(Carbon|string $date, int $days = 7): Collection
    {
        $date = Carbon::parse($date)->startOfDay();
        $from = $date->copy()->subDays($days);
        $occasions = $this->occasions()->forRange($from, $date);

        return PlannedMeal::query()
            ->where('type', MealType::Recipe->value)
            ->whereBetween('date', [$from->toDateString(), $date->toDateString()])
            ->with(['recipe', 'slot'])
            ->withSum('leftovers', 'servings')
            ->orderByDesc('date')
            ->get()
            ->map(function (PlannedMeal $meal) use ($occasions) {
                $diners = $this->occasions()->diners($occasions->get($meal->date->toDateString().'|'.$meal->meal_slot_id));

                return [
                    'meal' => $meal,
                    'remaining' => max(0.0, round($meal->servings - min($meal->servings, $diners) - (float) $meal->leftovers_sum_servings, 1)),
                ];
            })
            ->filter(fn (array $row) => $row['remaining'] > 0)
            ->values();
    }

    /**
     * Case suivant immédiatement (date, créneau) parmi les créneaux actifs :
     * créneau suivant le même jour, sinon premier créneau du lendemain.
     *
     * @return array{date: Carbon, slot: MealSlot}|null
     */
    public function nextSlot(Carbon|string $date, MealSlot|int $slot): ?array
    {
        $slots = MealSlot::query()->active()->ordered()->get();

        if ($slots->isEmpty()) {
            return null;
        }

        $slotId = $slot instanceof MealSlot ? $slot->id : $slot;
        $index = $slots->search(fn (MealSlot $s) => $s->id === $slotId);
        $date = Carbon::parse($date)->startOfDay();

        if ($index !== false && $index < $slots->count() - 1) {
            return ['date' => $date, 'slot' => $slots[$index + 1]];
        }

        return ['date' => $date->copy()->addDay(), 'slot' => $slots->first()];
    }

    /* ================================================================ Modification */

    /**
     * Déplace un élément vers une case, à une position (0 = en premier), et renumérote
     * les cases d'origine et de destination.
     */
    public function move(PlannedMeal $meal, Carbon|string $date, MealSlot|int $slot, int $position): void
    {
        $date = Carbon::parse($date)->toDateString();
        $slotId = $slot instanceof MealSlot ? $slot->id : $slot;

        if ($meal->isLeftover() && $meal->leftoverOf && $date < $meal->leftoverOf->date->toDateString()) {
            throw new InvalidArgumentException('Les restes ne peuvent pas être placés avant le repas d\'origine.');
        }

        if ($meal->isRecipe() && $meal->leftovers()->whereDate('date', '<', $date)->exists()) {
            throw new InvalidArgumentException('Ce repas a des restes planifiés plus tôt : déplacez d\'abord les restes.');
        }

        DB::transaction(function () use ($meal, $date, $slotId, $position) {
            $from = [$meal->date->toDateString(), (int) $meal->meal_slot_id];

            $ids = $this->cellQuery($date, $slotId)->whereKeyNot($meal->id)->pluck('id')->all();
            array_splice($ids, max(0, min($position, count($ids))), 0, [$meal->id]);

            $meal->update(['date' => $date, 'meal_slot_id' => $slotId]);
            $this->renumber($ids);

            if ($from !== [$date, (int) $slotId]) {
                $this->renumber($this->cellQuery(...$from)->pluck('id')->all());
            }
        });
    }

    public function update(PlannedMeal $meal, array $attributes): PlannedMeal
    {
        $data = array_intersect_key($attributes, array_flip(['servings', 'comment', 'cook_user_id', 'cook_together']));

        // Qui cuisine (14.7) : une personne, « ensemble », ou personne.
        if (array_key_exists('cook_user_id', $data) || array_key_exists('cook_together', $data)) {
            $data['cook_together'] = (bool) ($data['cook_together'] ?? false);
            $data['cook_user_id'] = $data['cook_together'] ? null : ($data['cook_user_id'] ?: null);
        }

        if (array_key_exists('servings', $data)) {
            $data['servings'] = $this->validServings($data['servings']);

            if ($meal->isRecipe()) {
                $placed = (float) $meal->leftovers()->sum('servings');
                $diners = $this->dinersOf($meal);
                $available = $data['servings'] - min($data['servings'], $diners);

                if ($placed > $available) {
                    $minimum = $placed + $diners;

                    throw new InvalidArgumentException('Des restes ('.Appetites::label($placed).') sont déjà planifiés : il faut au moins '.Appetites::label($minimum).'.');
                }
            }
        }

        if (array_key_exists('comment', $data)) {
            $data['comment'] = trim((string) $data['comment']) ?: null;
        }

        $meal->update($data);

        return $meal;
    }

    public function toggleCooked(PlannedMeal $meal): PlannedMeal
    {
        // Mangé : ce n'est plus un repas « pas fait » (lot 21, R23).
        $meal->update(['cooked_at' => $meal->cooked_at ? null : now(), 'skipped_at' => null, 'closed_automatically' => false]);

        // Une recette « à tester » ne l'est plus une fois cuisinée (13.13).
        if ($meal->cooked_at && ($recipe = $meal->eatenRecipe()) && $recipe->is_to_test) {
            $recipe->timestamps = false;
            $recipe->update(['is_to_test' => false]);
        }

        return $meal;
    }

    /** Duplique un élément dans une autre case (les restes ne sont pas duplicables). */
    public function duplicate(PlannedMeal $meal, Carbon|string $date, MealSlot|int $slot): PlannedMeal
    {
        return match ($meal->type) {
            MealType::Recipe => $this->addRecipe($date, $slot, $meal->recipe_id, $meal->servings, $meal->comment),
            MealType::Free => $this->addFree($date, $slot, (string) $meal->free_text, $meal->comment),
            MealType::Leftover => throw new InvalidArgumentException('Des restes ne peuvent pas être dupliqués.'),
        };
    }

    /**
     * « Autre idée » (lot 37, 37.4) : un autre plat dans la même case, mêmes portions, même
     * commentaire, même cuisinier. Les restes déjà placés suivent le nouveau plat ; une envie
     * placée sur l'ancien redevient une envie à planifier.
     */
    public function replaceRecipe(PlannedMeal $meal, Recipe|int $recipe): PlannedMeal
    {
        $recipe = $recipe instanceof Recipe ? $recipe : Recipe::query()->active()->findOrFail($recipe);

        if (! $meal->isRecipe()) {
            throw new InvalidArgumentException('Seul un plat peut être remplacé par un autre.');
        }

        if ($meal->cooked_at || $meal->prepared_at) {
            throw new InvalidArgumentException('Ce plat est déjà cuisiné : il ne peut plus être remplacé.');
        }

        return DB::transaction(function () use ($meal, $recipe) {
            \App\Models\Wish::query()->where('planned_meal_id', $meal->id)->where('recipe_id', '!=', $recipe->id)
                ->update(['planned_meal_id' => null, 'planned_at' => null]);

            $meal->update(['recipe_id' => $recipe->id]);

            return $meal->fresh(['recipe']);
        });
    }

    public function delete(PlannedMeal $meal): void
    {
        DB::transaction(function () use ($meal) {
            $cell = [$meal->date->toDateString(), (int) $meal->meal_slot_id];

            // Supprimer un repas supprime aussi ses restes planifiés.
            $meal->leftovers()->delete();
            $meal->delete();

            $this->renumber($this->cellQuery(...$cell)->pluck('id')->all());
        });
    }

    /* ================================================================ Semaine entière */

    /**
     * Copie tous les éléments d'une semaine vers une autre (même jour de la semaine, même créneau).
     * Les restes sont recopiés en pointant vers les copies de leurs repas d'origine.
     *
     * @param  bool  $replace  vider la semaine cible avant la copie
     * @return int nombre d'éléments copiés
     */
    public function copyWeek(Carbon $fromWeek, Carbon $toWeek, bool $replace = false): int
    {
        $fromWeek = $this->weekStart($fromWeek);
        $toWeek = $this->weekStart($toWeek);

        if ($fromWeek->equalTo($toWeek)) {
            throw new InvalidArgumentException('La semaine de destination doit être différente.');
        }

        $offset = (int) $fromWeek->diffInDays($toWeek, false);

        return DB::transaction(function () use ($fromWeek, $toWeek, $offset, $replace) {
            if ($replace) {
                $this->clearWeek($toWeek);
            }

            $meals = PlannedMeal::query()->between($fromWeek, $fromWeek->copy()->addDays(6))
                ->orderByRaw("CASE WHEN type = 'leftover' THEN 1 ELSE 0 END")
                ->orderBy('date')->orderBy('meal_slot_id')->orderBy('position')
                ->get();

            $map = [];

            foreach ($meals as $meal) {
                $date = $meal->date->copy()->addDays($offset);

                if ($meal->isLeftover()) {
                    // Restes dont le repas d'origine est hors de la semaine copiée : ignorés.
                    if (! isset($map[$meal->leftover_of_id])) {
                        continue;
                    }
                }

                $copy = $this->create($date, $meal->meal_slot_id, [
                    'type' => $meal->type,
                    'recipe_id' => $meal->recipe_id,
                    'leftover_of_id' => $meal->isLeftover() ? $map[$meal->leftover_of_id] : null,
                    'free_text' => $meal->free_text,
                    'servings' => $meal->servings,
                    'comment' => $meal->comment,
                ]);

                $map[$meal->id] = $copy->id;
            }

            return count($map);
        });
    }

    /** @return int nombre d'éléments supprimés */
    public function clearWeek(Carbon $weekStart): int
    {
        $weekStart = $this->weekStart($weekStart);

        return DB::transaction(function () use ($weekStart) {
            $query = PlannedMeal::query()->between($weekStart, $weekStart->copy()->addDays(6));
            $ids = $query->pluck('id');

            // Les restes de ces repas (même placés la semaine suivante) sont retirés aussi.
            PlannedMeal::query()->whereIn('leftover_of_id', $ids)->whereNotIn('id', $ids)->delete();
            PlannedMeal::query()->whereIn('id', $ids)->whereNotNull('leftover_of_id')->delete();

            return PlannedMeal::query()->whereIn('id', $ids)->delete();
        });
    }

    /* ================================================================ Suggestions */

    /**
     * Recettes à proposer : actives, pas déjà prévues cette semaine, les moins récemment
     * planifiées en premier (jamais planifiées d'abord).
     *
     * @param  list<int>  $tagIds  catégories obligatoires
     * @return Collection<int, Recipe> avec l'attribut `last_planned_on`
     */
    public function suggestions(Carbon $weekStart, int $limit = 8, array $tagIds = []): Collection
    {
        $weekStart = $this->weekStart($weekStart);
        $weekEnd = $weekStart->copy()->addDays(6);

        $query = Recipe::query()
            ->active()
            ->whereDoesntHave('plannedMeals', fn ($q) => $q->between($weekStart, $weekEnd))
            ->withMax(['plannedMeals as last_planned_on' => fn ($q) => $q->where('date', '<', $weekStart->toDateString())], 'date')
            ->with('tags');

        foreach ($tagIds as $tagId) {
            $query->whereHas('tags', fn ($q) => $q->whereKey($tagId));
        }

        return $query->get()
            ->sortBy(fn (Recipe $r) => [$r->last_planned_on === null ? 0 : 1, (string) $r->last_planned_on, $r->search_title])
            ->take($limit)
            ->values();
    }

    /* ================================================================ Outils */

    private function occasions(): OccasionService
    {
        return app(OccasionService::class);
    }

    private function create(Carbon|string $date, MealSlot|int $slot, array $attributes): PlannedMeal
    {
        $date = Carbon::parse($date)->toDateString();
        $slotId = $slot instanceof MealSlot ? $slot->id : $slot;

        $position = (int) $this->cellQuery($date, $slotId)->max('position') + 1;

        return PlannedMeal::create(array_merge($attributes, [
            'date' => $date,
            'meal_slot_id' => $slotId,
            'position' => $position,
            'created_by' => auth()->id(),
        ]));
    }

    /* ================================================================ Annuler (lot 30) */

    /** Repas d'une case : ceux qu'un déplacement ou une suppression renumérote. @return list<int> */
    public function cellMealIds(Carbon|string $date, int $slotId): array
    {
        return $this->cellQuery(Carbon::parse($date)->toDateString(), $slotId)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Repas d'une semaine, et leurs restes placés la semaine suivante. @return list<int> */
    public function weekMealIds(Carbon $weekStart): array
    {
        $weekStart = $this->weekStart($weekStart);
        $ids = PlannedMeal::query()->between($weekStart, $weekStart->copy()->addDays(6))->pluck('id');
        $leftovers = PlannedMeal::query()->whereIn('leftover_of_id', $ids)->pluck('id');

        return $ids->merge($leftovers)->unique()->map(fn ($id) => (int) $id)->values()->all();
    }

    private function cellQuery(string $date, int $slotId)
    {
        return PlannedMeal::query()->whereDate('date', $date)->where('meal_slot_id', $slotId)->orderBy('position')->orderBy('id');
    }

    /** @param  list<int>  $ids */
    private function renumber(array $ids): void
    {
        foreach (array_values($ids) as $index => $id) {
            PlannedMeal::query()->whereKey($id)->update(['position' => $index + 1]);
        }
    }

    /** Portions d'un plat : de 0,5 à 50, par demi-portion (lot 32, R33). */
    private function validServings(int|float|string $servings): float
    {
        $value = (float) str_replace(',', '.', (string) $servings);

        if ($value < 0.5 || $value > 50 || abs($value * 2 - round($value * 2)) > 0.001) {
            throw new InvalidArgumentException('Le nombre de portions doit être compris entre 0,5 et 50, par demi-portion.');
        }

        return round($value * 2) / 2;
    }
}
