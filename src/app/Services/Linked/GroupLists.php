<?php

namespace App\Services\Linked;

use App\Enums\ListStatus;
use App\Models\Household;
use App\Models\Scopes\HouseholdScope;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Services\Shopping\ShoppingListManager;
use App\Support\CurrentHousehold;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Liste de courses groupée (26.8) : pour un drive ou un gros magasin, un foyer ouvre sa liste aux
 * foyers reliés ; ils y ajoutent leurs articles « pour eux ». Celui qui fait les courses saisit le prix
 * payé de ces articles : c'est le montant à se rembourser. Ces articles ne vont ni dans son stock ni
 * dans ses dépenses.
 */
class GroupLists
{
    public function __construct(private readonly HouseholdLinks $links, private readonly ShoppingListManager $manager) {}

    public function setShared(ShoppingList $list, bool $shared): void
    {
        $list->forceFill(['shared_with_links' => $shared])->save();
    }

    /** Listes ouvertes par les foyers reliés. @return Collection<int, ShoppingList> */
    public function openLists(?int $viewer = null): Collection
    {
        $viewer ??= CurrentHousehold::id();
        $linked = $this->links->linkedIds($viewer);

        return $linked === [] ? collect() : ShoppingList::query()->withoutGlobalScope(HouseholdScope::class)
            ->whereIn('household_id', $linked)->where('shared_with_links', true)
            ->where('status', ListStatus::Active->value)
            ->with('household')->orderByDesc('period_start')->get();
    }

    public function findOpen(int $listId): ?ShoppingList
    {
        return $this->openLists()->firstWhere('id', $listId);
    }

    /** Ajoute un article « pour nous » dans la liste d'un foyer relié. */
    public function addFor(ShoppingList $list, string $label): ShoppingListItem
    {
        $viewer = (int) CurrentHousehold::id();

        if (! $this->findOpen($list->id)) {
            throw new InvalidArgumentException('Cette liste n\'est plus ouverte.');
        }

        return CurrentHousehold::run((int) $list->household_id, function () use ($list, $label, $viewer) {
            $item = $this->manager->addManual($list, $label);
            $item->forceFill(['for_household_id' => $viewer])->save();

            return $item;
        });
    }

    /** Retire un de nos articles, tant qu'il n'est pas acheté. */
    public function removeFor(ShoppingList $list, int $itemId): void
    {
        ShoppingListItem::query()->withoutGlobalScope(HouseholdScope::class)
            ->where('shopping_list_id', $list->id)->whereKey($itemId)
            ->where('for_household_id', CurrentHousehold::id())->where('is_checked', false)
            ->delete();
    }

    /** Nos articles dans la liste d'un foyer relié. @return Collection<int, ShoppingListItem> */
    public function itemsFor(ShoppingList $list, ?int $viewer = null): Collection
    {
        return ShoppingListItem::query()->withoutGlobalScope(HouseholdScope::class)
            ->where('shopping_list_id', $list->id)->where('for_household_id', $viewer ?? CurrentHousehold::id())
            ->where('is_removed', false)->orderBy('id')->get();
    }

    /**
     * Ce que chaque foyer relié doit à celui qui fait les courses (liste du foyer actif).
     *
     * @return Collection<int, array{household: Household, count: int, checked: int, amount: float, missing: int}>
     */
    public function balances(ShoppingList $list): Collection
    {
        return $list->items()->whereNotNull('for_household_id')->where('is_removed', false)->with('forHousehold')->get()
            ->groupBy('for_household_id')
            ->map(fn (Collection $items) => [
                'household' => $items->first()->forHousehold,
                'count' => $items->count(),
                'checked' => $items->where('is_checked', true)->count(),
                'amount' => round((float) $items->sum(fn ($i) => (float) $i->paid_price), 2),
                'missing' => $items->where('is_checked', true)->whereNull('paid_price')->count(),
            ])
            ->filter(fn ($row) => $row['household'] !== null)
            ->values();
    }
}
