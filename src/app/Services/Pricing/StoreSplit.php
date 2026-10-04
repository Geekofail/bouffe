<?php

namespace App\Services\Pricing;

use App\Enums\ItemOrigin;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Store;
use App\Models\Unit;
use App\Services\UnitConverter;
use Illuminate\Support\Collection;

/**
 * Répartir une liste de courses entre deux magasins (lot 27 — idée C1).
 *
 * Pour chaque article encore à acheter, on compare le dernier prix relevé dans le magasin de la
 * liste à celui des autres magasins. Un article n'est proposé ailleurs que si :
 *  - son prix est connu **dans le magasin de la liste** (sinon, on ne sait pas s'il est moins cher) ;
 *  - l'autre magasin est moins cher d'au moins 5 %.
 *
 * L'économie en euros n'est donnée que pour les articles dont la quantité se convertit ; les
 * autres sont comptés à part (« dont 2 sans quantité »). Rien n'est déplacé sans clic.
 */
class StoreSplit
{
    public function __construct(
        private readonly PriceComparison $comparison,
        private readonly UnitConverter $converter,
    ) {}

    /**
     * @return Collection<int, array{store: Store, items: Collection<int, array{item: ShoppingListItem, here: float, there: float, gap: float, saving: float|null}>, saving: float, unknown: int}>
     */
    public function suggestions(ShoppingList $list): Collection
    {
        if (! $list->store_id) {
            return collect();
        }

        $stores = Store::query()->get()->keyBy('id');
        $suggestions = [];

        foreach ($this->candidates($list) as $item) {
            $prices = $this->comparison->pricesFor((int) $item->ingredient_id);
            $here = $prices->get((int) $list->store_id);

            if (! $here) {
                continue;
            }

            $best = $prices
                ->reject(fn (array $row) => $row['store_id'] === (int) $list->store_id || ! $stores->has($row['store_id']))
                ->sortBy('unit_price')
                ->first();

            if (! $best || $best['unit_price'] > $here['unit_price'] * (1 - PriceComparison::MIN_GAP)) {
                continue;
            }

            $quantity = $this->baseQuantity($item, $here['unit']);

            $suggestions[$best['store_id']][] = [
                'item' => $item,
                'here' => $here['unit_price'],
                'there' => $best['unit_price'],
                'gap' => round(1 - $best['unit_price'] / $here['unit_price'], 4),
                'saving' => $quantity !== null ? round(($here['unit_price'] - $best['unit_price']) * $quantity, 2) : null,
            ];
        }

        return collect($suggestions)
            ->map(fn (array $rows, int $storeId) => [
                'store' => $stores[$storeId],
                'items' => collect($rows)->sortBy(fn ($row) => mb_strtolower($row['item']->label))->values(),
                'saving' => round((float) collect($rows)->sum(fn ($row) => $row['saving'] ?? 0), 2),
                'unknown' => collect($rows)->whereNull('saving')->count(),
            ])
            ->sortByDesc('saving')
            ->values();
    }

    /**
     * Réserve des articles à un autre magasin : ils passent dans « Chez … » sur la liste.
     *
     * @param  list<int>  $itemIds
     */
    public function assign(ShoppingList $list, Store $store, array $itemIds): int
    {
        return $list->items()->whereIn('id', $itemIds)->where('is_checked', false)->update(['store_id' => $store->id]);
    }

    /** Reprend dans le magasin de la liste tous les articles réservés à un autre magasin. */
    public function bringBack(ShoppingList $list, int $storeId): int
    {
        return $list->items()->where('store_id', $storeId)->where('is_checked', false)->update(['store_id' => null]);
    }

    /** @return Collection<int, ShoppingListItem> articles encore à acheter, dans ce magasin-ci */
    private function candidates(ShoppingList $list): Collection
    {
        return $list->items()
            ->with('unit', 'ingredient')
            ->whereNotNull('ingredient_id')
            ->whereNull('for_household_id')
            ->where('is_removed', false)
            ->where('is_checked', false)
            ->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $list->store_id))
            ->get()
            // Déjà en stock, ou produit de base seulement « à vérifier » : on ne l'achètera sans doute pas.
            ->reject(fn (ShoppingListItem $i) => ($i->stock_status === 'covered' && ! $i->buy_anyway)
                || ($i->origin === ItemOrigin::Staple && $i->stock_status !== 'out'));
    }

    /** Quantité de l'article dans l'unité du prix, ou null si elle ne se convertit pas. */
    private function baseQuantity(ShoppingListItem $item, Unit $priceUnit): ?float
    {
        $parts = [['q' => (float) $item->quantity, 'unit' => $item->unit]];

        foreach ((array) $item->extra_quantities as $extra) {
            $parts[] = ['q' => (float) ($extra['q'] ?? 0), 'unit' => isset($extra['unit_id']) ? Unit::find($extra['unit_id']) : null];
        }

        $total = 0.0;

        foreach ($parts as $part) {
            if ($part['q'] <= 0) {
                return null;
            }

            $unit = $part['unit'];

            if ($unit === null) {
                if ($priceUnit->code !== 'piece') {
                    return null;
                }
                $total += $part['q'];

                continue;
            }

            if ($unit->id === $priceUnit->id) {
                $total += $part['q'];

                continue;
            }

            if (! $this->converter->canConvert($unit, $priceUnit, $item->ingredient)) {
                return null;
            }

            $total += $this->converter->convert($part['q'], $unit, $priceUnit, $item->ingredient);
        }

        return $total;
    }
}
