<?php

namespace App\Livewire\Stays\Concerns;

use App\Models\MealSlot;
use App\Models\Recipe;
use App\Models\StayMeal;
use App\Services\Stays\StayService;
use App\Support\NameNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;

/**
 * Séjour — planning (34.1) : jours × créneaux, portions d'après les présents du jour, alertes
 * d'allergies et de régimes. Rien n'apparaît dans le planning de la maison.
 */
trait PlansStayMeals
{
    /** Case ouverte : « 2026-10-12|3 ». */
    public string $cell = '';

    public string $recipeSearch = '';

    public string $freeText = '';

    public function openCell(string $date, int $slotId): void
    {
        $this->cell = $date.'|'.$slotId;
        $this->reset('recipeSearch', 'freeText');
        $this->resetErrorBag();
    }

    public function closeCell(): void
    {
        $this->cell = '';
    }

    public function pickRecipe(int $recipeId, StayService $stays): void
    {
        $this->addToCell($stays, $recipeId, null);
    }

    public function addFreeText(StayService $stays): void
    {
        $this->validate(['freeText' => 'required|string|max:150'], [], ['freeText' => 'repas']);
        $this->addToCell($stays, null, $this->freeText);
    }

    private function addToCell(StayService $stays, ?int $recipeId, ?string $text): void
    {
        if (! $this->allowedToEdit() || ! str_contains($this->cell, '|')) {
            return;
        }

        [$date, $slot] = explode('|', $this->cell);

        try {
            $stays->addMeal($this->stay, $date, (int) $slot, $recipeId, $text);
        } catch (InvalidArgumentException $e) {
            $this->addError('cell', $e->getMessage());

            return;
        }

        $this->cell = '';
        unset($this->grid);
    }

    public function removeMeal(int $mealId): void
    {
        if (! $this->allowedToEdit() || ! ($meal = $this->manageableMeal($mealId))) {
            return;
        }

        $meal->delete();
        unset($this->grid);
    }

    public function setMealServings(int $mealId, ?string $value, StayService $stays): void
    {
        if (! $this->allowedToEdit() || ! ($meal = $this->manageableMeal($mealId))) {
            return;
        }

        $stays->setServings($meal, $value);
        unset($this->grid);
    }

    /** Lot 42 (42.1) : un foyer qui co-organise change ses plats ; l'organisateur, tous. */
    private function manageableMeal(int $mealId): ?StayMeal
    {
        $meal = $this->stay->meals()->find($mealId);

        return $meal && app(\App\Services\Stays\StayCoorganizers::class)->canManage($meal->household_id, $this->stay) ? $meal : null;
    }

    /** Les créneaux du foyer qui organise (les créneaux sont propres à chaque foyer). */
    #[Computed]
    public function stayMealSlots(): Collection
    {
        return MealSlot::query()->withoutGlobalScope(\App\Models\Scopes\HouseholdScope::class)
            ->where('household_id', $this->stay->household_id)->active()->ordered()->get();
    }

    /**
     * Jour par jour : portions des présents et repas de chaque créneau.
     *
     * @return list<array{date: Carbon, portions: float, people: int, cells: list<array{slot: MealSlot, meals: list<array{meal: StayMeal, servings: float, auto: bool, conflicts: list<array>}>}>}>
     */
    #[Computed]
    public function grid(): array
    {
        // Lot 42 (42.1) : portions et équipement sont ceux du séjour, donc du foyer qui l'organise.
        $viewer = (int) \App\Support\CurrentHousehold::id();

        return app(\App\Services\Stays\StayCoorganizers::class)->asOrganizer($this->stay, fn () => $this->buildGrid($viewer));
    }

    private function buildGrid(int $viewer): array
    {
        $stays = app(StayService::class);
        $meals = $this->stay->meals()->with('recipe.ingredients', 'household')->get()->groupBy(fn (StayMeal $m) => $m->date->toDateString().'|'.$m->meal_slot_id);
        $rows = [];

        foreach ($this->stay->days() as $day) {
            $cells = [];

            foreach ($this->stayMealSlots as $slot) {
                $cells[] = [
                    'slot' => $slot,
                    'meals' => $meals->get($day->toDateString().'|'.$slot->id, collect())->map(fn (StayMeal $meal) => [
                        'meal' => $meal,
                        'servings' => $stays->servingsFor($meal, $this->stay),
                        'auto' => $meal->servings === null,
                        'conflicts' => $stays->conflicts($meal, $this->stay, $viewer),
                    ])->all(),
                ];
            }

            $rows[] = [
                'date' => $day,
                'portions' => $stays->portionsOn($this->stay, $day),
                'people' => $this->stay->participants->filter(fn ($p) => $p->presentOn($day, $this->stay))->count(),
                'cells' => $cells,
            ];
        }

        return $rows;
    }

    /** @return Collection<int, Recipe> */
    #[Computed]
    public function recipeResults(): Collection
    {
        $term = NameNormalizer::normalize($this->recipeSearch);

        // Lot 40 (40.3) : les recettes impossibles avec l'équipement du lieu sont écartées.
        // Lot 42 : « comme à la maison » = la maison du foyer qui organise ; les recettes, celles de notre carnet.
        $equipment = app(\App\Services\Recipes\KitchenEquipment::class);
        $available = app(\App\Services\Stays\StayCoorganizers::class)->asOrganizer($this->stay, fn () => $equipment->available($this->stay));

        return Recipe::query()->active()->with('steps')
            ->when($term !== '', fn ($q) => $q->where('search_title', 'like', '%'.$term.'%'))
            ->orderByDesc('is_favorite')->orderBy('title')
            ->limit(60)->get(['id', 'title', 'servings', 'is_favorite', 'equipment'])
            ->filter(fn (Recipe $recipe) => array_diff($equipment->required($recipe), $available) === [])
            ->take(12)->values();
    }
}
