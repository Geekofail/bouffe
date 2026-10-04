<?php

namespace App\Services\Stock;

use App\Enums\MovementType;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Services\Pricing\PriceBook;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Statistiques anti-gaspillage (16.3).
 *
 * Le but n'est pas de culpabiliser : c'est de voir ce qui part à la poubelle **régulièrement**,
 * parce que c'est là qu'un changement d'habitude paie. Les montants viennent des prix relevés
 * (lot 17) ; sans prix, on compte les articles sans inventer d'euros.
 */
class WasteStats
{
    public function __construct(private readonly PriceBook $prices) {}

    /**
     * Mois par mois : jetés, consommés, part consommée, coût estimé du gaspillage.
     *
     * @return list<array{month: Carbon, label: string, short: string, wasted: int, consumed: int, share: int|null, cost: float, priced: int}>
     */
    public function months(int $count = 6): array
    {
        $start = Carbon::today()->startOfMonth()->subMonths(max(1, $count) - 1);

        $movements = StockMovement::query()
            ->whereIn('type', [MovementType::Waste->value, MovementType::Consume->value])
            ->where('created_at', '>=', $start)
            ->with(['ingredient.referencePriceUnit', 'unit'])
            ->get()
            ->groupBy(fn (StockMovement $movement) => $movement->created_at->format('Y-m'));

        $months = [];

        for ($i = 0; $i < max(1, $count); $i++) {
            $month = $start->copy()->addMonths($i);
            $rows = $movements->get($month->format('Y-m'), collect());

            $wasted = $rows->where('type', MovementType::Waste);
            $consumed = $rows->where('type', MovementType::Consume);
            $total = $wasted->count() + $consumed->count();

            $months[] = [
                'month' => $month,
                'label' => ucfirst($month->locale('fr')->isoFormat('MMMM YYYY')),
                'short' => ucfirst($month->locale('fr')->isoFormat('MMM')),
                'wasted' => $wasted->count(),
                'consumed' => $consumed->count(),
                'share' => $total > 0 ? (int) round($consumed->count() / $total * 100) : null,
                'cost' => $this->costOf($wasted),
                'priced' => $wasted->filter(fn (StockMovement $m) => $this->movementCost($m) !== null)->count(),
            ];
        }

        return $months;
    }

    /**
     * Les produits les plus souvent jetés sur la période.
     *
     * @return Collection<int, array{ingredient: Ingredient|null, label: string, count: int, cost: float, last: Carbon}>
     */
    public function topWasted(int $limit = 8, int $days = 180): Collection
    {
        return StockMovement::query()
            ->where('type', MovementType::Waste->value)
            ->where('created_at', '>=', Carbon::today()->subDays($days))
            ->with(['ingredient.referencePriceUnit', 'unit'])
            ->get()
            ->groupBy(fn (StockMovement $movement) => $movement->ingredient_id ?? 'x-'.$movement->label)
            ->map(fn (Collection $group) => [
                'ingredient' => $group->first()->ingredient,
                'label' => $group->first()->label,
                'count' => $group->count(),
                'cost' => $this->costOf($group),
                'last' => $group->max('created_at'),
            ])
            ->sortByDesc(fn (array $row) => [$row['count'], $row['cost']])
            ->take($limit)
            ->values();
    }

    /**
     * Bilan de la période : ce qu'on jette, ce que ça coûte, et si ça s'améliore.
     *
     * @return array{wasted: int, consumed: int, share: int|null, cost: float, trend: int|null, priced: int}
     */
    public function summary(int $months = 6): array
    {
        $rows = collect($this->months($months));
        $wasted = (int) $rows->sum('wasted');
        $consumed = (int) $rows->sum('consumed');
        $total = $wasted + $consumed;

        // Tendance : les trois derniers mois comparés aux trois précédents.
        $recent = $rows->slice(-3)->sum('wasted');
        $previous = $rows->slice(-6, 3)->sum('wasted');
        $trend = $previous > 0 ? (int) round(($recent - $previous) / $previous * 100) : null;

        return [
            'wasted' => $wasted,
            'consumed' => $consumed,
            'share' => $total > 0 ? (int) round($consumed / $total * 100) : null,
            'cost' => (float) $rows->sum('cost'),
            'priced' => (int) $rows->sum('priced'),
            'trend' => $trend,
        ];
    }

    /** Coût estimé d'un mouvement de gaspillage, ou null si l'ingrédient n'a pas de prix. */
    public function movementCost(StockMovement $movement): ?float
    {
        $ingredient = $movement->ingredient ?? $movement->item?->ingredient;

        if (! $ingredient || $movement->quantity === null) {
            return null;
        }

        return $this->prices->costOf($ingredient, (float) $movement->quantity, $movement->unit ?? $movement->item?->unit);
    }

    /** @param  Collection<int, StockMovement>  $movements */
    private function costOf(Collection $movements): float
    {
        return (float) $movements->sum(fn (StockMovement $movement) => $this->movementCost($movement) ?? 0);
    }

    public function money(float $amount): string
    {
        return $this->prices->money($amount);
    }
}
