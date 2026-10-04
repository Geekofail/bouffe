<?php

namespace App\Services\Stock;

use App\Enums\StockMode;
use App\Models\Ingredient;
use App\Models\StockItem;
use App\Models\Unit;
use App\Services\QuantityFormatter;
use App\Services\UnitConverter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Ce que le stock contient pour un ingrédient, exprimé dans une unité donnée (règles R8, R9, R10).
 */
class StockAvailability
{
    public function __construct(
        private readonly UnitConverter $converter,
        private readonly ExpiryCalculator $expiry,
        private readonly QuantityFormatter $formatter,
    ) {}

    /** @return Collection<int, StockItem> articles actifs de l'ingrédient, date effective la plus proche d'abord (FEFO) */
    public function items(Ingredient $ingredient, bool $includeFrozen = true): Collection
    {
        return StockItem::query()->active()->where('ingredient_id', $ingredient->id)->with('unit', 'ingredient.defaultUnit')->get()
            ->reject(fn (StockItem $i) => ! $includeFrozen && $i->isFrozen())
            ->sortBy(fn (StockItem $i) => $this->expiry->effective($i)['date']?->timestamp ?? PHP_INT_MAX)
            ->values();
    }

    /** Quantité d'un article convertie dans $unit, ou null (quantité inconnue, unités incompatibles). */
    public function amountIn(StockItem $item, ?Unit $unit, Ingredient $ingredient): ?float
    {
        return $item->quantity === null ? null : $this->convert((float) $item->quantity, $item->unit, $unit, $ingredient);
    }

    /** Conversion d'une quantité (unité de l'article → autre unité), ou null. */
    public function convert(float $quantity, ?Unit $from, ?Unit $to, Ingredient $ingredient): ?float
    {
        if ($from === null || $to === null) {
            return $from === $to ? $quantity : null;
        }

        return $this->converter->canConvert($from, $to, $ingredient) ? $this->converter->convert($quantity, $from, $to, $ingredient) : null;
    }

    /**
     * Stock utilisable pour un besoin exprimé en $unit, à la date $neededOn (règle R8).
     *
     * @return array{
     *   tracked: bool, present: bool, amount: float, unknown: Collection<int, StockItem>,
     *   expiring: Collection<int, StockItem>, usable: Collection<int, StockItem>
     * }
     *   tracked  : l'ingrédient est suivi et a déjà eu du stock
     *   present  : au moins un article utilisable
     *   amount   : quantité comparable additionnée (dans $unit)
     *   unknown  : articles utilisables mais non comparables (quantité inconnue, boîte vs grammes…)
     *   expiring : articles qui périment avant $neededOn (non comptés)
     */
    /**
     * @param  array<int, float>  $reservedByItem  quantités déjà promises à d'autres repas (R24), par article
     */
    public function forNeed(Ingredient $ingredient, ?Unit $unit, ?Carbon $neededOn = null, array $reservedByItem = []): array
    {
        $neededOn ??= Carbon::today();
        $empty = ['tracked' => false, 'present' => false, 'amount' => 0.0, 'unknown' => collect(), 'expiring' => collect(), 'usable' => collect()];

        if ($ingredient->stock_mode === StockMode::None) {
            return $empty;
        }

        $tracked = StockItem::query()->where('ingredient_id', $ingredient->id)->exists();
        $items = $this->items($ingredient);

        $expiring = $items->filter(function (StockItem $i) use ($neededOn) {
            $date = $this->expiry->effective($i)['date'];

            return $date !== null && $date->copy()->startOfDay()->lt($neededOn->copy()->startOfDay());
        })->values();

        $usable = $items->diff($expiring)->values();
        $amount = 0.0;
        $unknown = collect();

        foreach ($usable as $item) {
            if ($item->quantity !== null && isset($reservedByItem[$item->id])) {
                $left = max(0.0, (float) $item->quantity - $reservedByItem[$item->id]);
                $amount += $this->convert($left, $item->unit, $unit, $ingredient) ?? 0.0;

                continue;
            }

            $value = $this->amountIn($item, $unit, $ingredient);
            $value === null ? $unknown->push($item) : $amount += $value;
        }

        return ['tracked' => $tracked, 'present' => $usable->isNotEmpty(), 'amount' => $amount, 'unknown' => $unknown, 'expiring' => $expiring, 'usable' => $usable];
    }

    /** « 250 g », « 2 boîtes », « quantité inconnue » */
    public function describe(StockItem $item): string
    {
        return $item->quantity === null ? 'quantité inconnue' : $this->formatter->format((float) $item->quantity, $item->unit);
    }
}
