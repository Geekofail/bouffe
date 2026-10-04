<?php

namespace App\Services\Shopping;

use App\Enums\MealType;
use App\Enums\UnitType;
use App\Models\Ingredient;
use App\Models\PlannedMeal;
use App\Models\Unit;
use App\Services\QuantityScaler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Calcul de la liste de courses à partir du planning (règles R1, R2, R4 et R5 de l'analyse).
 *
 *  R1 — quantités mises à l'échelle : quantité × portions planifiées / portions de la recette
 *  R2 — agrégation par ingrédient : masses en g, volumes en ml, pièces converties en grammes
 *       grâce au poids moyen, unités incompatibles juxtaposées (« 3 pièces + 1 boîte »)
 *  R4 — exclusions : restes et repas libres ignorés, repas passés exclus sauf demande
 *  R5 — provenance : chaque contribution (recette, date, quantité) est conservée
 *
 * Ce service ne touche pas la base : il renvoie des ShoppingLine. L'enregistrement est fait
 * par ShoppingListManager.
 */
class ShoppingListGenerator
{
    public function __construct(private readonly QuantityScaler $scaler) {}

    /**
     * Repas « recette » de la période pris en compte.
     *
     * @param  list<int>  $excludedMealIds
     * @return Collection<int, PlannedMeal>
     */
    public function meals(Carbon $from, Carbon $to, bool $includePast = false, array $excludedMealIds = [], ?Carbon $today = null): Collection
    {
        $today ??= Carbon::today();
        $start = $includePast ? $from->copy() : $from->copy()->max($today);

        if ($start->gt($to)) {
            return collect();
        }

        return PlannedMeal::query()
            ->where('type', MealType::Recipe->value)
            ->whereBetween('date', [$start->toDateString(), $to->toDateString()])
            ->when($excludedMealIds !== [], fn ($q) => $q->whereNotIn('id', $excludedMealIds))
            // Plat déjà cuisiné à l'avance (14.8) : il est au stock, plus rien à acheter.
            ->whereNull('prepared_at')
            // Lot 42 (42.2) : un plat que quelqu'un apporte n'est plus à acheter.
            ->whereNotIn('id', \App\Services\Together\Contributions::broughtPlannedMeals())
            ->whereHas('recipe')
            ->with(['recipe.ingredients.ingredient.defaultUnit', 'recipe.ingredients.ingredient.aisle', 'recipe.ingredients.unit', 'slot',
                ...array_map(fn ($r) => 'recipe.'.$r, \App\Services\Recipes\SubRecipes::EAGER)])
            ->orderBy('date')->orderBy('meal_slot_id')->orderBy('position')
            ->get();
    }

    /**
     * @param  iterable<PlannedMeal>  $meals
     * @return Collection<int, ShoppingLine> triées par nom d'ingrédient
     */
    public function generate(iterable $meals): Collection
    {
        /** @var array<int, array{ingredient: Ingredient, contributions: list<array>}> $byIngredient */
        $byIngredient = [];

        foreach ($meals as $meal) {
            $recipe = $meal->recipe;

            // Sous-recettes dépliées (13.8) : la pâte brisée de la quiche compte dans les courses.
            foreach (app(\App\Services\Recipes\SubRecipes::class)->lines($recipe) as $line) {
                $quantity = $this->scaler->scale($line->quantity, max(1, $recipe->servings), $meal->servings);

                $byIngredient[$line->ingredient_id] ??= ['ingredient' => $line->ingredient, 'contributions' => []];
                $byIngredient[$line->ingredient_id]['contributions'][] = [
                    'quantity' => $quantity,
                    'unit' => $line->unit,
                    'optional' => $line->is_optional,
                    'source' => [
                        'planned_meal_id' => $meal->id,
                        'recipe_title' => $recipe->title.($line->via_recipe ? ' ('.mb_strtolower($line->via_recipe).')' : ''),
                        'meal_date' => $meal->date->toDateString(),
                        'slot_name' => $meal->slot?->name,
                        'servings' => $meal->servings,
                        'quantity' => $quantity === null ? null : round($quantity, 3),
                        'unit_id' => $line->unit_id,
                        'is_optional' => $line->is_optional,
                    ],
                ];
            }
        }

        return collect($byIngredient)
            ->map(fn (array $group) => new ShoppingLine(
                ingredient: $group['ingredient'],
                parts: $this->aggregate($group['ingredient'], $group['contributions']),
                isOptional: collect($group['contributions'])->every(fn ($c) => $c['optional']),
                isStaple: $group['ingredient']->is_staple,
                sources: array_column($group['contributions'], 'source'),
            ))
            ->sortBy(fn (ShoppingLine $line) => $line->ingredient->search_name)
            ->values();
    }

    /**
     * Additionne les contributions d'un ingrédient (règle R2).
     *
     * @param  list<array{quantity: float|null, unit: Unit|null}>  $contributions
     * @return list<array{quantity: float|null, unit: Unit|null}>
     */
    public function aggregate(Ingredient $ingredient, array $contributions): array
    {
        $pieceWeight = (float) $ingredient->piece_weight_g;
        $defaultUnit = $ingredient->defaultUnit;

        $mass = 0.0;
        $massFromRealMassUnit = false;
        $pieceUnitsInMass = [];   // code => Unit|null : pièces converties en grammes
        $volume = 0.0;
        $volumeUnits = [];        // code => Unit
        $others = [];             // code => ['quantity' => float, 'unit' => Unit|null]
        $unspecified = false;

        foreach ($contributions as $contribution) {
            $quantity = $contribution['quantity'];
            /** @var Unit|null $unit */
            $unit = $contribution['unit'];

            if ($quantity === null) {
                $unspecified = true;

                continue;
            }

            if ($unit === null || $unit->type === UnitType::Piece) {
                $code = $unit?->code ?? 'piece';
                $convertible = $pieceWeight > 0
                    && ($defaultUnit === null || $defaultUnit->type !== UnitType::Piece || $defaultUnit->code === $code);

                if ($convertible) {
                    $mass += $quantity * $pieceWeight;
                    $pieceUnitsInMass[$code] = $unit ?? $pieceUnitsInMass[$code] ?? null;
                } else {
                    $others[$code] ??= ['quantity' => 0.0, 'unit' => $unit];
                    $others[$code]['quantity'] += $quantity;
                }

                continue;
            }

            if ($unit->type === UnitType::Mass && $unit->factor_to_base !== null) {
                $mass += $quantity * (float) $unit->factor_to_base;
                $massFromRealMassUnit = true;

                continue;
            }

            if ($unit->type === UnitType::Volume && $unit->factor_to_base !== null) {
                $volume += $quantity * (float) $unit->factor_to_base;
                $volumeUnits[$unit->code] = $unit;

                continue;
            }

            $others[$unit->code] ??= ['quantity' => 0.0, 'unit' => $unit];
            $others[$unit->code]['quantity'] += $quantity;
        }

        $parts = [];

        if ($mass > 0) {
            if ($defaultUnit?->type === UnitType::Piece && $pieceWeight > 0) {
                // Unité habituelle en pièces : « 3 oignons » plutôt que « 450 g d'oignons »
                $parts[] = ['quantity' => $mass / $pieceWeight, 'unit' => $defaultUnit];
            } elseif (! $massFromRealMassUnit && count($pieceUnitsInMass) === 1) {
                // Uniquement des pièces : on reste en pièces
                $parts[] = ['quantity' => $mass / $pieceWeight, 'unit' => reset($pieceUnitsInMass) ?: $this->unitByCode('piece')];
            } else {
                $parts[] = ['quantity' => $mass, 'unit' => $this->unitByCode('g')];
            }
        }

        if ($volume > 0) {
            $single = count($volumeUnits) === 1 ? reset($volumeUnits) : null;

            $parts[] = $single && ! $single->is_metric
                ? ['quantity' => $volume / (float) $single->factor_to_base, 'unit' => $single]   // « 3 c. à soupe »
                : ['quantity' => $volume, 'unit' => $this->unitByCode('ml')];
        }

        foreach ($others as $other) {
            $parts[] = ['quantity' => $other['quantity'], 'unit' => $other['unit'] ?? $this->unitByCode('piece')];
        }

        // « sel » : à convenance. Si une autre recette précise une quantité, on garde celle-ci.
        if ($parts === [] && $unspecified) {
            $parts[] = ['quantity' => null, 'unit' => null];
        }

        return $parts;
    }

    /** @var array<string, Unit|null> */
    private array $unitCache = [];

    private function unitByCode(string $code): ?Unit
    {
        return $this->unitCache[$code] ??= Unit::firstWhere('code', $code);
    }
}
