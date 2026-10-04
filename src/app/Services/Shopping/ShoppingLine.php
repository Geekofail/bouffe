<?php

namespace App\Services\Shopping;

use App\Models\Ingredient;
use App\Models\Unit;

/**
 * Ligne calculée de la liste de courses (non enregistrée) : un ingrédient, ses quantités
 * additionnées et la provenance de chaque contribution.
 */
final class ShoppingLine
{
    /**
     * @param  list<array{quantity: float|null, unit: Unit|null}>  $parts  première partie = quantité principale
     * @param  list<array{planned_meal_id: int, recipe_title: string, meal_date: string, slot_name: string|null, servings: float, quantity: float|null, unit_id: int|null, is_optional: bool}>  $sources
     */
    public function __construct(
        public readonly Ingredient $ingredient,
        public readonly array $parts,
        public readonly bool $isOptional,
        public readonly bool $isStaple,
        public readonly array $sources,
    ) {}

    public function quantity(): ?float
    {
        return $this->parts[0]['quantity'] ?? null;
    }

    public function unit(): ?Unit
    {
        return $this->parts[0]['unit'] ?? null;
    }

    /** @return list<array{q: float, unit_id: int|null}> */
    public function extraQuantities(): array
    {
        return array_values(array_map(
            fn (array $part) => ['q' => round((float) $part['quantity'], 3), 'unit_id' => $part['unit']?->id],
            array_filter(array_slice($this->parts, 1), fn (array $part) => $part['quantity'] !== null),
        ));
    }
}
