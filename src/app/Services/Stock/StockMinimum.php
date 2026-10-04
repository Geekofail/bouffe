<?php

namespace App\Services\Stock;

use App\Enums\StockMode;
use App\Models\Ingredient;
use App\Services\QuantityFormatter;
use Illuminate\Support\Collection;

/**
 * Ingrédients sous leur stock minimum (9.10).
 */
class StockMinimum
{
    public function __construct(
        private readonly StockAvailability $availability,
        private readonly QuantityFormatter $formatter,
    ) {}

    /**
     * @return Collection<int, array{ingredient: Ingredient, available: float|null, missing: float|null, text: string}>
     *                                                                                                                  missing : quantité à racheter pour revenir au minimum (dans l'unité du minimum), null en mode présence
     */
    public function below(): Collection
    {
        return Ingredient::query()
            ->whereSetting('min_stock_quantity', '>', 0)
            ->whereSetting('stock_mode', '!=', StockMode::None->value)
            ->with('defaultUnit', 'aisle')
            ->orderBy('name')->get()
            ->map(function (Ingredient $ingredient) {
                $unit = $ingredient->min_stock_unit_id ? \App\Models\Unit::find($ingredient->min_stock_unit_id) : $ingredient->defaultUnit;
                $minimum = (float) $ingredient->min_stock_quantity;

                if ($ingredient->stock_mode === StockMode::Presence) {
                    $present = $this->availability->items($ingredient)->isNotEmpty();

                    return $present ? null : ['ingredient' => $ingredient, 'available' => null, 'missing' => null, 'unit' => null, 'text' => 'plus en stock'];
                }

                $stock = $this->availability->forNeed($ingredient, $unit);

                // Quantité inconnue ou non comparable : on ne sait pas, on ne propose rien.
                if ($stock['unknown']->isNotEmpty() || $stock['amount'] >= $minimum - 0.0005) {
                    return null;
                }

                return [
                    'ingredient' => $ingredient,
                    'available' => $stock['amount'],
                    'missing' => $minimum - $stock['amount'],
                    'unit' => $unit,
                    'text' => ($stock['amount'] > 0 ? 'reste '.$this->formatter->format($stock['amount'], $unit) : 'plus en stock').' · minimum '.$this->formatter->format($minimum, $unit),
                ];
            })
            ->filter()
            ->values();
    }
}
