<?php

namespace App\Services\Pricing;

use App\Models\Ingredient;
use App\Models\IngredientPrice;
use App\Models\Store;
use App\Models\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Inflation personnelle (lot 27 — idée C2) : comment évolue le prix de **votre** panier.
 *
 * Un produit suivi est un ingrédient acheté dans un même magasin, ramené à une même unité
 * (€/kg, €/pièce…) : changer de magasin n'est pas de l'inflation.
 *
 * L'indice du panier est calculé comme un indice des prix classique (Laspeyres) :
 *  - le panier : les produits relevés dans les 3 premiers mois de la période et revus ensuite ;
 *  - chaque mois, on prend le dernier prix connu de chaque produit (reporté tant qu'il n'est pas
 *    relevé à nouveau), rapporté à son premier prix ;
 *  - la moyenne est pondérée par ce qu'on a dépensé pour chaque produit : le beurre acheté chaque
 *    semaine pèse plus que le safran acheté une fois.
 *
 * Il n'est pas donné sous MIN_BASKET produits suivis : quelques relevés ne font pas une tendance.
 */
class PersonalInflation
{
    public const MONTHS = 12;

    /** Mois du départ dans lesquels un produit doit avoir été relevé pour entrer dans le panier. */
    public const BASE_MONTHS = 3;

    public const MIN_BASKET = 3;

    /** Hausse marquée : +15 % d'un relevé au suivant, dans un même magasin. */
    public const ALERT = 0.15;

    /** Une hausse est signalée tant que le relevé qui la montre a moins de 60 jours. */
    public const ALERT_DAYS = 60;

    public function __construct(private readonly PriceBook $prices) {}

    /**
     * Produits suivis : au moins deux relevés dans le même magasin, à au moins 4 semaines d'écart.
     *
     * @return Collection<int, array{key: string, ingredient: Ingredient, store: Store|null, unit: Unit, first: array{unit_price: float, on: Carbon}, last: array{unit_price: float, on: Carbon}, change: float, spent: float, count: int, observations: Collection<int, array{unit_price: float, on: Carbon}>}>
     */
    public function series(?Carbon $today = null): Collection
    {
        return $this->allSeries($today)
            ->filter(fn (array $s) => $s['count'] >= 2 && $s['first']['on']->diffInDays($s['last']['on']) >= 28)
            ->sortByDesc('change')
            ->values();
    }

    /**
     * Indice du panier, mois par mois (100 au départ).
     *
     * @return array{months: list<array{start: Carbon, label: string, short: string, index: float}>, basket: int, change: float|null, since: Carbon|null, observations: int}
     */
    public function index(?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $all = $this->allSeries($today);
        $empty = ['months' => [], 'basket' => 0, 'change' => null, 'since' => null, 'observations' => (int) $all->sum('count')];

        if ($all->isEmpty()) {
            return $empty;
        }

        $start = $all->min(fn (array $s) => $s['first']['on'])->copy()->startOfMonth();
        $baseEnd = $start->copy()->addMonthsNoOverflow(self::BASE_MONTHS)->subDay();

        // Panier : relevé au départ, puis revu au moins une fois après ce premier relevé.
        $basket = $all->filter(fn (array $s) => $s['first']['on']->lte($baseEnd) && $s['count'] >= 2);

        if ($basket->count() < self::MIN_BASKET) {
            return ['basket' => $basket->count(), 'since' => $start] + $empty;
        }

        $weights = $basket->mapWithKeys(fn (array $s) => [$s['key'] => max($s['spent'], 0.01)]);
        $total = $weights->sum();
        $months = [];

        for ($month = $start->copy(); $month->lte($today); $month->addMonthNoOverflow()) {
            $end = $month->copy()->endOfMonth();
            $sum = 0.0;

            foreach ($basket as $s) {
                $current = $s['observations']->filter(fn (array $o) => $o['on']->lte($end))->last();
                $relative = $current ? $current['unit_price'] / $s['first']['unit_price'] : 1.0;
                $sum += $weights[$s['key']] * $relative;
            }

            $months[] = [
                'start' => $month->copy(),
                'label' => ucfirst($month->locale('fr')->isoFormat('MMMM YYYY')),
                'short' => ucfirst($month->locale('fr')->isoFormat('MMM')),
                'index' => round($sum / $total * 100, 1),
            ];
        }

        return [
            'months' => $months,
            'basket' => $basket->count(),
            'change' => count($months) > 1 ? round(end($months)['index'] / 100 - 1, 4) : null,
            'since' => $start,
            'observations' => (int) $all->sum('count'),
        ];
    }

    /**
     * Hausses marquées : dernier relevé d'un produit au moins 15 % au-dessus du précédent, même magasin.
     *
     * @return Collection<int, array{ingredient: Ingredient, store: Store|null, unit: Unit, before: float, after: float, before_on: Carbon, after_on: Carbon, change: float, label_before: string, label_after: string}>
     */
    public function rises(?Carbon $today = null, int $days = self::ALERT_DAYS): Collection
    {
        $today ??= Carbon::today();

        return $this->allSeries($today)
            ->filter(fn (array $s) => $s['count'] >= 2 && $s['last']['on']->gte($today->copy()->subDays($days)))
            ->map(function (array $s) {
                $previous = $s['observations'][$s['count'] - 2];
                $change = $s['last']['unit_price'] / $previous['unit_price'] - 1;

                return $change >= self::ALERT ? [
                    'ingredient' => $s['ingredient'],
                    'store' => $s['store'],
                    'unit' => $s['unit'],
                    'before' => $previous['unit_price'],
                    'after' => $s['last']['unit_price'],
                    'before_on' => $previous['on'],
                    'after_on' => $s['last']['on'],
                    'change' => round($change, 4),
                    'label_before' => $this->prices->unitPriceLabel($previous['unit_price'], $s['unit']),
                    'label_after' => $this->prices->unitPriceLabel($s['last']['unit_price'], $s['unit']),
                ] : null;
            })
            ->filter()
            ->sortByDesc('after_on')
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> toutes les séries de la période, même d'un seul relevé */
    private function allSeries(?Carbon $today): Collection
    {
        $today ??= Carbon::today();
        $from = $today->copy()->startOfMonth()->subMonthsNoOverflow(self::MONTHS - 1);

        // Lot 30 (R35) : l'indice du panier et les hausses marquées ignorent les prix en promotion.
        $prices = IngredientPrice::query()
            ->whereNotNull('unit_price')
            ->where('unit_price', '>', 0)
            ->where('is_promo', false)
            ->whereBetween('observed_on', [$from->toDateString(), $today->toDateString()])
            ->with('unit')
            ->orderBy('observed_on')->orderBy('id')
            ->get();

        if ($prices->isEmpty()) {
            return collect();
        }

        $ingredients = Ingredient::query()->whereIn('id', $prices->pluck('ingredient_id')->unique())->get()->keyBy('id');
        $stores = Store::query()->get()->keyBy('id');

        return $prices
            ->map(fn (IngredientPrice $p) => ['price' => $p, 'unit' => $this->prices->baseUnitFor($p->unit)])
            ->filter(fn (array $row) => $row['unit'] !== null && $ingredients->has($row['price']->ingredient_id))
            ->groupBy(fn (array $row) => $row['price']->ingredient_id.'|'.$row['unit']->id.'|'.($row['price']->store_id ?? 0))
            ->map(function (Collection $rows, string $key) use ($ingredients, $stores) {
                $observations = $rows->map(fn (array $row) => ['unit_price' => (float) $row['price']->unit_price, 'on' => $row['price']->observed_on])->values();
                $first = $observations->first();
                $last = $observations->last();
                $price = $rows->first()['price'];

                return [
                    'key' => $key,
                    'ingredient' => $ingredients[$price->ingredient_id],
                    'store' => $price->store_id ? $stores->get($price->store_id) : null,
                    'unit' => $rows->first()['unit'],
                    'first' => $first,
                    'last' => $last,
                    'first_label' => $this->prices->unitPriceLabel($first['unit_price'], $rows->first()['unit']),
                    'last_label' => $this->prices->unitPriceLabel($last['unit_price'], $rows->first()['unit']),
                    'change' => round($last['unit_price'] / $first['unit_price'] - 1, 4),
                    'spent' => round((float) $rows->sum(fn (array $row) => (float) $row['price']->price), 2),
                    'count' => $observations->count(),
                    'observations' => $observations,
                ];
            })
            ->values();
    }
}
