<?php

namespace App\Livewire\Recipes\Concerns;

use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Services\IngredientLineFormatter;
use App\Services\Planning\GuestCompatibility;
use App\Services\Planning\OccasionService;
use App\Services\QuantityScaler;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * Fiche recette — données affichées : ingrédients, sous-recettes, repas d'origine, stock,
 * statistiques, variantes, saison, nutrition, coût, notes de cuisine (découpé de Show au lot 36).
 */
trait ProvidesRecipeData
{
    /** Notes du foyer sur les étapes (lot 40, 40.2), par numéro d'étape. */
    #[Computed]
    public function stepNotes(): Collection
    {
        return app(\App\Services\Recipes\StepNotes::class)->byStep($this->recipe);
    }

    /** Photos d'étapes (31.2), par numéro d'étape. */
    #[Computed]
    public function stepPhotos(): Collection
    {
        return app(\App\Services\Recipes\RecipePhotos::class)->byStep($this->recipe);
    }

    /** Collections où figure la recette (31.1). */
    #[Computed]
    public function collections(): Collection
    {
        return $this->recipe->isForeign() ? collect() : $this->recipe->collections()->get(['collections.id', 'collections.name']);
    }

    /** L'assistant culinaire (lot 33) : proposé seulement s'il est prêt, et pas sur une recette d'un autre foyer. */
    #[Computed]
    public function assistantAvailable(): bool
    {
        return ! $this->recipe->isForeign()
            && (bool) auth()->user()?->canEdit()
            && app(\App\Services\Assistant\AssistantService::class)->available();
    }

    #[\Livewire\Attributes\On('recipe-photos-changed')]
    #[\Livewire\Attributes\On('recipe-collections-changed')]
    public function refreshLot31(): void
    {
        unset($this->stepPhotos, $this->collections);
    }

    /**
     * Ingrédients mis à l'échelle et groupés.
     *
     * @return Collection<string, Collection<int, array>>
     */
    #[Computed]
    public function ingredientGroups(): Collection
    {
        $scaler = app(QuantityScaler::class);
        $formatter = app(IngredientLineFormatter::class);

        // 13.7 : une variante remplace certaines lignes, à leur place, sans dupliquer la recette.
        return app(\App\Services\Recipes\VariantService::class)
            ->lines($this->recipe, $this->variant)
            ->reject(fn (array $row) => $row['removed'])
            ->map(function (array $row) use ($scaler, $formatter) {
                $line = $row['line'];
                $quantity = $scaler->scale($row['quantity'], $this->recipe->servings, $this->servings);

                return [
                    'id' => $line->id,
                    'ingredient_id' => $row['ingredient']?->id,
                    'group' => (string) $line->group_name,
                    'parts' => $formatter->format($quantity, $row['unit'], $row['ingredient']),
                    'preparation' => $row['note'] ?? $line->preparation,
                    'optional' => $line->is_optional,
                    'swapped' => $row['swapped'],
                ];
            })
            ->groupBy('group');
    }

    /**
     * Sous-recettes (13.8), à l'échelle des portions affichées.
     *
     * @return Collection<int, array{recipe: \App\Models\Recipe, factor: string, note: string|null, lines: list<array>}>
     */
    #[Computed]
    public function subRecipes(): Collection
    {
        $scaler = app(QuantityScaler::class);
        $formatter = app(IngredientLineFormatter::class);
        $numbers = app(\App\Services\QuantityFormatter::class);

        return $this->recipe->components()->with('component.ingredients.ingredient', 'component.ingredients.unit')->get()
            ->filter(fn ($component) => $component->component !== null)
            ->map(function ($component) use ($formatter, $numbers) {
                $factor = $component->factor() * $this->servings / max(1, (int) $this->recipe->servings);
                $sub = $component->component;

                return [
                    'recipe' => $sub,
                    'factor' => abs($factor - round($factor)) < 0.001 || $factor > 3
                        ? $numbers->number($factor, 1)
                        : (new \App\Models\RecipeComponent(['quantity' => round($factor, 3)]))->factorLabel(),
                    'note' => $component->note,
                    'lines' => $sub->ingredients->map(fn ($line) => [
                        'parts' => $formatter->format($line->quantity === null ? null : (float) $line->quantity * $factor, $line->unit, $line->ingredient),
                        'optional' => $line->is_optional,
                    ])->all(),
                ];
            })->values();
    }

    /** @return array{meal: PlannedMeal, occasion: \App\Models\MealOccasion|null, conflicts: list<array>, byIngredient: array<int, string>}|null */
    #[Computed]
    public function mealContext(): ?array
    {
        $meal = $this->mealId ? PlannedMeal::with('slot')->find($this->mealId) : null;

        if (! $meal) {
            return null;
        }

        $occasion = app(OccasionService::class)->find($meal->date, $meal->meal_slot_id);
        $conflicts = $occasion ? app(GuestCompatibility::class)->conflicts($this->recipe, $occasion->guests) : [];
        $byIngredient = [];

        foreach ($conflicts as $conflict) {
            if ($conflict['ingredient_id'] && ($byIngredient[$conflict['ingredient_id']] ?? null) !== 'danger') {
                $byIngredient[$conflict['ingredient_id']] = $conflict['level'];
            }
        }

        return ['meal' => $meal, 'occasion' => $occasion, 'conflicts' => $conflicts, 'byIngredient' => $byIngredient];
    }

    /**
     * Disponibilité des ingrédients dans le stock (10.2), pour les portions affichées.
     * Rien tant que le stock n'est pas utilisé.
     *
     * @return array{lines: array<int, array>, counted: int, available: float, missing: list<array>, urgent: int}|null
     */
    #[Computed]
    public function stockAvailability(): ?array
    {
        if (! \App\Models\StockItem::query()->exists()) {
            return null;
        }

        return app(\App\Services\Stock\RecipeSuggester::class)->evaluate($this->recipe, $this->servings);
    }

    public function addMissingToList(\App\Services\Shopping\ShoppingListManager $manager): void
    {
        $missing = $this->stockAvailability['missing'] ?? [];

        if ($missing === []) {
            $this->dispatch('notify', message: 'Rien ne manque dans le stock pour cette recette.');

            return;
        }

        $list = $manager->currentOrNew();
        $result = $manager->addMissing($list, $missing, $this->recipe->title);
        $count = count($result['added']);

        $this->dispatch('notify', message: $count > 0
            ? $count.' article'.($count > 1 ? 's ajoutés' : ' ajouté')." à « {$list->name} » : ".implode(', ', $result['added']).'.'
            : "Déjà dans « {$list->name} ».");
    }

    /** Notes de cuisine (13.6), les plus récentes d'abord. */
    #[Computed]
    public function cookNotes(): \Illuminate\Support\Collection
    {
        // Recette d'un foyer relié : ses notes de cuisine restent chez lui.
        return $this->recipe->isForeign() ? collect() : $this->recipe->cookNotes()->with('user')->limit(5)->get();
    }

    public function deleteCookNote(int $noteId): void
    {
        $this->recipe->cookNotes()->whereKey($noteId)->where('user_id', auth()->id())->delete();
        unset($this->cookNotes);
    }

    /** Statistiques de planification : nombre de fois, dernière fois, prochaine fois. */
    #[Computed]
    public function planningStats(): array
    {
        $today = now()->toDateString();
        $meals = $this->recipe->plannedMeals()->with('slot');

        return [
            'count' => (clone $meals)->where('date', '<=', $today)->count(),
            'last' => (clone $meals)->where('date', '<=', $today)->orderByDesc('date')->first(),
            'next' => (clone $meals)->where('date', '>', $today)->orderBy('date')->first(),
        ];
    }

    #[Computed]
    public function activeSlots(): \Illuminate\Support\Collection
    {
        return MealSlot::query()->active()->ordered()->get();
    }

    public function showVariant(?int $variantId): void
    {
        $this->variantId = $variantId;
        unset($this->ingredientGroups, $this->variant, $this->nutrition);
    }

    /** @return \Illuminate\Support\Collection<int, \App\Models\RecipeVariant> */
    #[Computed]
    public function variants(): \Illuminate\Support\Collection
    {
        if ($this->recipe->isForeign()) {
            return collect();
        }

        return $this->recipe->variants()->with(['solvesIngredient', 'solvesTag', 'swaps.ingredient', 'swaps.unit'])->get();
    }

    #[Computed]
    public function variant(): ?\App\Models\RecipeVariant
    {
        return $this->variantId ? $this->variants->firstWhere('id', $this->variantId) : null;
    }

    /** Variante proposée d'elle-même quand un convive a une contrainte (13.7). */
    #[Computed]
    public function suggestedVariant(): ?array
    {
        return app(\App\Services\Recipes\VariantService::class)
            ->suggestFor($this->recipe, $this->mealContext['occasion'] ?? null);
    }

    /** État de saison de la recette, au mois du repas s'il y en a un (R18). */
    #[Computed]
    public function season(): array
    {
        return app(\App\Services\Seasons\SeasonCalendar::class)
            ->recipeStatus($this->recipe, $this->mealContext['meal']->date ?? null);
    }

    /** Valeurs nutritionnelles par portion (R19). */
    #[Computed]
    public function nutrition(): array
    {
        return app(\App\Services\Nutrition\NutritionCalculator::class)->recipe($this->recipe, $this->servings);
    }

    /** Coût estimé pour le nombre de portions affiché (17.2, règle R17). */
    #[Computed]
    public function cost(): \App\Services\Pricing\Cost
    {
        return app(\App\Services\Pricing\CostCalculator::class)->recipe($this->recipe, $this->servings);
    }
}
