<?php

namespace App\Services\Shopping\Concerns;

use App\Enums\ItemOrigin;
use App\Models\Aisle;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Support\NameNormalizer;
use Illuminate\Support\Collection;

/**
 * Listes de courses — affichage : articles groupés par rayon et liste en texte (découpé de
 * ShoppingListManager au lot 36).
 */
trait PresentsLists
{
    /**
     * Articles groupés par rayon (ordre du magasin), puis cochés en bas de chaque rayon.
     *
     * @return array{aisles: Collection<int, array{aisle: Aisle|null, items: Collection}>, staples: Collection, covered: Collection, removed: Collection}
     */
    public function grouped(ShoppingList $list): array
    {
        $items = $list->items()->with(['ingredient', 'aisle', 'sources', 'checker'])->get();

        // 15.3 : l'ordre des rayons est celui du magasin de la liste, à défaut l'ordre général.
        $list->loadMissing('store');
        $aisleOrder = collect($this->layout->order($list->store));
        $hiddenAisles = $this->layout->hidden($list->store);

        $sort = fn (Collection $group) => $group
            ->sortBy(fn (ShoppingListItem $i) => [$i->is_checked ? 1 : 0, $i->is_optional ? 1 : 0, NameNormalizer::normalize($i->label)])
            ->values();

        $active = $items->where('is_removed', false);

        // R8 : couverts par le stock → « Déjà en stock » ; produits de base épuisés → dans leur rayon
        $covered = $active->filter(fn (ShoppingListItem $i) => $i->stock_status === 'covered' && $i->origin === ItemOrigin::Generated);
        $active = $active->diff($covered);

        // 15.3 : « seulement au marché » — articles réservés à un autre magasin que celui de la liste,
        // et articles d'un rayon qu'on ne trouve pas ici. Montrés à part plutôt que cachés : on les oublierait.
        $elsewhere = $active->filter(fn (ShoppingListItem $i) => ($i->store_id !== null && $i->store_id !== $list->store_id)
            || ($i->aisle_id !== null && in_array((int) $i->aisle_id, $hiddenAisles, true)));
        $active = $active->diff($elsewhere);

        $inAisles = fn (ShoppingListItem $i) => $i->origin !== ItemOrigin::Staple || $i->stock_status === 'out';

        $aisles = $active->filter($inAisles)
            ->groupBy(fn (ShoppingListItem $i) => $i->aisle_id ?? 0)
            ->sortBy(fn ($group, $aisleId) => $aisleId === 0 ? PHP_INT_MAX : ($aisleOrder[$aisleId] ?? PHP_INT_MAX - 1))
            ->map(fn (Collection $group) => ['aisle' => $group->first()->aisle, 'items' => $sort($group)])
            ->values();

        return [
            'aisles' => $aisles,
            'staples' => $sort($active->reject($inAisles)),
            'elsewhere' => $sort($elsewhere),
            'covered' => $covered->sortBy(fn (ShoppingListItem $i) => NameNormalizer::normalize($i->label))->values(),
            'removed' => $items->where('is_removed', true)->sortBy('label')->values(),
        ];
    }

    /** Liste en texte brut, pour l'envoyer par message. */
    public function toText(ShoppingList $list, bool $includeChecked = false): string
    {
        $grouped = $this->grouped($list);
        $lines = [$list->name, ''];

        foreach ($grouped['aisles'] as $group) {
            $items = $group['items']->filter(fn ($i) => $includeChecked || ! $i->is_checked);

            if ($items->isEmpty()) {
                continue;
            }

            $lines[] = mb_strtoupper($group['aisle']?->name ?? 'Autres');

            foreach ($items as $item) {
                $lines[] = '- '.$this->presenter->text($item).($item->is_optional ? ' (facultatif)' : '');
            }

            $lines[] = '';
        }

        $staples = $grouped['staples']->filter(fn ($i) => $includeChecked || ! $i->is_checked);

        if ($staples->isNotEmpty()) {
            $lines[] = 'À VÉRIFIER DANS LE PLACARD';

            foreach ($staples as $item) {
                $lines[] = '- '.$this->presenter->text($item);
            }
        }

        return rtrim(implode("\n", $lines))."\n";
    }
}
