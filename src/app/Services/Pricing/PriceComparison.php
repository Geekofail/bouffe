<?php

namespace App\Services\Pricing;

use App\Models\Ingredient;
use App\Models\IngredientPrice;
use App\Models\Store;
use App\Models\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Comparateur de prix entre magasins (lot 27 — idée C1).
 *
 * Il ne compare que ce qui se compare :
 *  - le **dernier** prix relevé de chaque produit dans chaque magasin, sur les 6 derniers mois ;
 *  - ramené à la même unité (€/kg, €/l, €/pièce, €/boîte…) : un prix « au kilo » ne se compare
 *    pas à un prix « à la boîte » ;
 *  - entre magasins, sur les seuls produits relevés dans les deux, et seulement s'il y en a assez.
 *
 * Il ne devine rien : un produit relevé dans un seul magasin n'est comparé à rien.
 *
 * Lot 30 (R35) : le prix courant d'un magasin est son dernier prix **hors promotion** ; le plus bas
 * prix vu en promotion est donné à part (« meilleur prix vu »), sans entrer dans les comparaisons.
 */
class PriceComparison
{
    /** Au-delà, un prix n'est plus utilisé : il ne dit plus rien du prix d'aujourd'hui. */
    public const DAYS = 180;

    /** Au-delà, un prix est encore utilisé mais signalé « ancien ». */
    public const STALE_DAYS = 90;

    /** Produits en commun nécessaires pour comparer deux magasins dans leur ensemble. */
    public const MIN_COMMON = 3;

    /** Écart en dessous duquel deux prix sont considérés comme équivalents. */
    public const MIN_GAP = 0.05;

    /** @var Collection<int, array<string, mixed>>|null */
    private ?Collection $latest = null;

    public function __construct(private readonly PriceBook $prices) {}

    /**
     * Dernier prix de chaque produit dans chaque magasin.
     *
     * @return Collection<int, array{key: string, ingredient_id: int, unit: Unit, store_id: int, unit_price: float, observed_on: Carbon, price: IngredientPrice}>
     */
    public function latest(?Carbon $today = null): Collection
    {
        if ($this->latest !== null && $today === null) {
            return $this->latest;
        }

        $today ??= Carbon::today();

        $rows = IngredientPrice::query()
            ->whereNotNull('store_id')
            ->whereNotNull('unit_price')
            ->where('unit_price', '>', 0)
            ->where('is_promo', false)
            ->whereBetween('observed_on', [$today->copy()->subDays(self::DAYS)->toDateString(), $today->toDateString()])
            ->with('unit')
            ->orderByDesc('observed_on')->orderByDesc('id')
            ->get()
            ->map(function (IngredientPrice $price) {
                $unit = $this->prices->baseUnitFor($price->unit);

                return $unit ? [
                    'key' => $price->ingredient_id.'|'.$unit->id,
                    'ingredient_id' => (int) $price->ingredient_id,
                    'unit' => $unit,
                    'store_id' => (int) $price->store_id,
                    'unit_price' => (float) $price->unit_price,
                    'observed_on' => $price->observed_on,
                    'price' => $price,
                ] : null;
            })
            ->filter()
            ->unique(fn (array $row) => $row['key'].'|'.$row['store_id'])   // le plus récent d'abord : on garde celui-là
            ->values();

        return $this->latest = $rows;
    }

    /**
     * Plus bas prix en promotion de chaque produit, sur les 6 derniers mois (R35).
     *
     * @return Collection<string, array{unit_price: float, store_id: int, observed_on: Carbon}> clé : « ingrédient|unité »
     */
    public function promotions(?Carbon $today = null): Collection
    {
        $today ??= Carbon::today();

        return IngredientPrice::query()
            ->where('is_promo', true)
            ->whereNotNull('store_id')->whereNotNull('unit_price')->where('unit_price', '>', 0)
            ->whereBetween('observed_on', [$today->copy()->subDays(self::DAYS)->toDateString(), $today->toDateString()])
            ->with('unit')
            ->get()
            ->map(function (IngredientPrice $price) {
                $unit = $this->prices->baseUnitFor($price->unit);

                return $unit ? [
                    'key' => $price->ingredient_id.'|'.$unit->id,
                    'unit_price' => (float) $price->unit_price,
                    'store_id' => (int) $price->store_id,
                    'observed_on' => $price->observed_on,
                ] : null;
            })
            ->filter()
            ->sortBy('unit_price')
            ->unique('key')
            ->keyBy('key');
    }

    /**
     * Produits relevés, avec leurs prix par magasin du moins cher au plus cher.
     *
     * @return Collection<int, array{key: string, ingredient: Ingredient, unit: Unit, prices: Collection<int, array{store: Store, unit_price: float, observed_on: Carbon, stale: bool, label: string}>, gap: float|null}>
     */
    public function products(?Carbon $today = null): Collection
    {
        $today ??= Carbon::today();
        $latest = $this->latest($today);
        $promotions = $this->promotions($today);
        $stores = Store::query()->get()->keyBy('id');
        $ingredients = Ingredient::query()->whereIn('id', $latest->pluck('ingredient_id')->unique())->get()->keyBy('id');

        return $latest
            ->groupBy('key')
            ->map(function (Collection $rows) use ($stores, $ingredients, $today, $promotions) {
                $prices = $rows
                    ->filter(fn (array $row) => $stores->has($row['store_id']))
                    ->map(fn (array $row) => [
                        'store' => $stores[$row['store_id']],
                        'unit_price' => $row['unit_price'],
                        'observed_on' => $row['observed_on'],
                        'stale' => $row['observed_on']->lt($today->copy()->subDays(self::STALE_DAYS)),
                        'label' => $this->prices->unitPriceLabel($row['unit_price'], $row['unit']),
                    ])
                    ->sortBy('unit_price')
                    ->values();

                $first = $rows->first();
                $ingredient = $ingredients[$first['ingredient_id']] ?? null;

                if (! $ingredient || $prices->isEmpty()) {
                    return null;
                }

                $cheapest = $prices->first()['unit_price'];
                $dearest = $prices->last()['unit_price'];
                $promo = $promotions->get($first['key']);

                return [
                    'key' => $first['key'],
                    'ingredient' => $ingredient,
                    'unit' => $first['unit'],
                    'prices' => $prices,
                    // Meilleur prix vu (R35) : une promotion plus basse que tous les prix courants.
                    'best' => $promo && $promo['unit_price'] < $cheapest && $stores->has($promo['store_id']) ? $promo + [
                        'store' => $stores[$promo['store_id']],
                        'label' => $this->prices->unitPriceLabel($promo['unit_price'], $first['unit']),
                    ] : null,
                    // « Le beurre est 18 % plus cher chez Cactus que chez Lidl. »
                    'gap' => $prices->count() > 1 && $cheapest > 0 ? round($dearest / $cheapest - 1, 4) : null,
                ];
            })
            ->filter()
            ->sortBy(fn (array $product) => mb_strtolower($product['ingredient']->name))
            ->values();
    }

    /**
     * Magasins face au magasin de référence (celui de la liste par défaut, à défaut le plus relevé).
     *
     * L'écart est la moyenne géométrique des rapports de prix sur les produits relevés dans les deux :
     * « sur 18 produits en commun, 12 % moins cher ». Il n'est pas donné sous MIN_COMMON produits.
     *
     * @return array{reference: Store|null, stores: Collection<int, array{store: Store, products: int, common: int, diff: float|null, cheapest: int}>}
     */
    public function stores(?Carbon $today = null): array
    {
        $products = $this->products($today);
        $byStore = [];

        foreach ($products as $product) {
            foreach ($product['prices'] as $index => $row) {
                $byStore[$row['store']->id][$product['key']] = $row['unit_price'];
                $byStore[$row['store']->id]['#cheapest'] = ($byStore[$row['store']->id]['#cheapest'] ?? 0)
                    + ($index === 0 && $product['prices']->count() > 1 ? 1 : 0);
            }
        }

        if ($byStore === []) {
            return ['reference' => null, 'stores' => collect()];
        }

        $stores = Store::query()->whereIn('id', array_keys($byStore))->ordered()->get();
        $preferred = Store::preferred();
        $reference = $preferred && isset($byStore[$preferred->id])
            ? $preferred
            : $stores->sortByDesc(fn (Store $s) => count($byStore[$s->id]))->first();

        $refPrices = $byStore[$reference->id];
        unset($refPrices['#cheapest']);

        $rows = $stores->map(function (Store $store) use ($byStore, $refPrices, $reference) {
            $prices = $byStore[$store->id];
            $cheapest = $prices['#cheapest'] ?? 0;
            unset($prices['#cheapest']);

            $common = array_intersect_key($prices, $refPrices);
            $diff = null;

            if ($store->id !== $reference->id && count($common) >= self::MIN_COMMON) {
                $logs = array_map(fn ($key) => log($prices[$key] / $refPrices[$key]), array_keys($common));
                $diff = round(exp(array_sum($logs) / count($logs)) - 1, 4);
            }

            return [
                'store' => $store,
                'products' => count($prices),
                'common' => $store->id === $reference->id ? count($prices) : count($common),
                'diff' => $diff,
                'cheapest' => $cheapest,
            ];
        })
            ->sortBy(fn (array $row) => [$row['store']->id === $reference->id ? 0 : 1, $row['diff'] ?? PHP_FLOAT_MAX])
            ->values();

        return ['reference' => $reference, 'stores' => $rows];
    }

    /**
     * Dernier prix d'un produit dans chaque magasin, pour une unité ramenée donnée.
     *
     * @return Collection<int, array{store_id: int, unit: Unit, unit_price: float, observed_on: Carbon}> clé : store_id
     */
    public function pricesFor(int $ingredientId): Collection
    {
        return $this->latest()
            ->where('ingredient_id', $ingredientId)
            ->groupBy(fn (array $row) => $row['unit']->id)
            // Plusieurs unités pour un même produit (au kilo et à la pièce) : on garde celle qui a le plus de magasins.
            ->sortByDesc(fn (Collection $rows) => $rows->count())
            ->first(default: collect())
            ->keyBy('store_id');
    }

    /** Oublie les prix lus : à appeler après un nouveau relevé dans la même requête. */
    public function forget(): void
    {
        $this->latest = null;
    }
}
