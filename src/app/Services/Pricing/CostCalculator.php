<?php

namespace App\Services\Pricing;

use App\Enums\ItemOrigin;
use App\Enums\MealType;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Services\QuantityScaler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Coût d'une recette, d'une liste de courses, d'une semaine (17.2, règle R17).
 *
 * Ce qu'on ne sait pas chiffrer est compté à part, jamais estimé au doigt mouillé :
 * un produit de base sans quantité (« un peu de sel ») est **ignoré**, un ingrédient
 * avec une quantité mais sans prix connu est **signalé comme manquant**.
 */
class CostCalculator
{
    public function __construct(
        private readonly PriceBook $prices,
        private readonly QuantityScaler $scaler,
    ) {}

    /* ================================================================ Recette */

    public function recipe(Recipe $recipe, int|float|null $servings = null): Cost
    {
        $servings = max(0.5, (float) ($servings ?? (int) $recipe->servings));
        $base = max(1, (int) $recipe->servings);

        $recipe->loadMissing(['ingredients.ingredient.referencePriceUnit', 'ingredients.unit']);

        $total = 0.0;
        $counted = 0;
        $missing = 0;

        foreach (app(\App\Services\Recipes\SubRecipes::class)->lines($recipe) as $line) {
            $ingredient = $line->ingredient;

            if (! $ingredient) {
                continue;
            }

            $quantity = $this->scaler->scale($line->quantity, $base, $servings);
            $cost = $this->prices->costOf($ingredient, $quantity, $line->unit);

            if ($cost !== null) {
                $total += $cost;
                $counted++;

                continue;
            }

            // « Sel, poivre » : un produit de base sans quantité ne se chiffre pas et ne manque à personne.
            if ($ingredient->is_staple && ($quantity === null || $line->quantity === null)) {
                continue;
            }

            $missing++;
        }

        return new Cost(total: $total, counted: $counted, missing: $missing, servings: $servings);
    }

    /**
     * Coûts de plusieurs recettes d'un coup (index des recettes, filtre par prix).
     *
     * @param  Collection<int, Recipe>  $recipes
     * @return array<int, Cost> indexé par identifiant de recette
     */
    public function forRecipes(Collection $recipes): array
    {
        $recipes->loadMissing(['ingredients.ingredient.referencePriceUnit', 'ingredients.unit', 'components.component.ingredients.ingredient.referencePriceUnit', 'components.component.ingredients.unit']);

        return $recipes->mapWithKeys(fn (Recipe $recipe) => [$recipe->id => $this->recipe($recipe)])->all();
    }

    /* ================================================================ Liste de courses */

    /**
     * Coût d'une liste : ce qu'il reste à acheter, et ce qui a déjà été payé (15.7).
     *
     * Les articles retirés (« j'en ai déjà ») et ceux couverts par le stock ne comptent pas :
     * ils ne seront pas payés.
     */
    public function shoppingList(ShoppingList $list): Cost
    {
        $items = $list->items()
            ->where('is_removed', false)
            ->whereNull('for_household_id')   // acheté pour un foyer relié : remboursé, pas notre dépense (26.8)
            ->with(['ingredient.referencePriceUnit', 'unit'])
            ->get()
            ->reject(fn (ShoppingListItem $item) => $item->stock_status === 'covered' && $item->origin === ItemOrigin::Generated);

        $total = 0.0;
        $counted = 0;
        $missing = 0;
        $paid = 0.0;

        foreach ($items as $item) {
            $paid += (float) $item->paid_price;

            // Un prix payé vaut mieux qu'une estimation.
            if ($item->paid_price !== null) {
                $total += (float) $item->paid_price;
                $counted++;

                continue;
            }

            $cost = $item->ingredient
                ? $this->prices->costOf($item->ingredient, $item->quantity !== null ? (float) $item->quantity : null, $item->unit)
                : null;

            if ($cost !== null) {
                $total += $cost;
                $counted++;

                continue;
            }

            // Un produit de base « à vérifier dans le placard » sans quantité n'est pas un oubli.
            if ($item->origin === ItemOrigin::Staple && $item->quantity === null) {
                continue;
            }

            $missing++;
        }

        return new Cost(total: $total, counted: $counted, missing: $missing, paid: $paid);
    }

    /* ================================================================ Semaine */

    /** Coût des repas « recette » d'une période ; les restes ne sont pas recomptés. */
    public function week(Carbon $from, Carbon $to): Cost
    {
        $meals = PlannedMeal::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('type', MealType::Recipe->value)
            ->with(['recipe.ingredients.ingredient.referencePriceUnit', 'recipe.ingredients.unit'])
            ->get();

        $cost = new Cost;

        foreach ($meals as $meal) {
            if (! $meal->recipe) {
                continue;
            }

            $cost = $cost->plus($this->recipe($meal->recipe, (float) $meal->servings ?: null));
        }

        return $cost;
    }
}
