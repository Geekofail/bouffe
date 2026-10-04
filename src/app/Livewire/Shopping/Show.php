<?php

namespace App\Livewire\Shopping;

use App\Enums\ListStatus;
use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\IngredientPrice;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Store;
use App\Models\Unit;
use App\Services\Pricing\CostCalculator;
use App\Services\Pricing\PriceBook;
use App\Services\Shopping\ShoppingItemPresenter;
use App\Services\Shopping\ShoppingListManager;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class Show extends Component
{
    use \App\Livewire\Concerns\OffersUndo;

    #[Locked]
    public int $listId;

    #[Url(as: 'masquer', except: false)]
    public bool $hideChecked = false;

    public string $newItem = '';

    /** @var list<string> différences de la dernière mise à jour */
    public array $changes = [];

    public bool $showChanges = false;

    /* Édition d'un article */
    public ?int $editingId = null;

    public string $editQuantity = '';

    public ?int $editUnitId = null;

    public ?int $editAisleId = null;

    /** Prix payé, saisi facultativement (15.7) — alimente le prix de référence et le budget. */
    public string $editPrice = '';

    /** « Seulement au marché » (15.3). */
    public ?int $editStoreId = null;

    public bool $showText = false;

    public function mount(ShoppingList $shoppingList): void
    {
        $this->listId = $shoppingList->id;
    }

    #[Computed]
    public function currentList(): ShoppingList
    {
        return ShoppingList::findOrFail($this->listId);
    }

    private function manager(): ShoppingListManager
    {
        return app(ShoppingListManager::class);
    }

    private function item(int $itemId): ShoppingListItem
    {
        return ShoppingListItem::where('shopping_list_id', $this->listId)->findOrFail($itemId);
    }

    /* ================================================================ Articles */

    public function toggle(int $itemId): void
    {
        $this->manager()->toggleCheck($this->item($itemId));
    }

    public function addItem(): void
    {
        $this->validate(['newItem' => 'required|string|max:200'], [], ['newItem' => 'article']);

        try {
            $item = $this->manager()->addManual($this->currentList, $this->newItem);
        } catch (InvalidArgumentException $e) {
            $this->addError('newItem', $e->getMessage());

            return;
        }

        $this->reset('newItem');
        $this->dispatch('notify', message: "«\u{00A0}{$item->label}\u{00A0}» ajouté".($item->aisle ? " ({$item->aisle->name})" : '').'.');
    }

    public function edit(int $itemId): void
    {
        $item = $this->item($itemId);

        $this->resetErrorBag();
        $this->editingId = $item->id;
        $this->editQuantity = $item->quantity === null ? '' : rtrim(rtrim(str_replace('.', ',', (string) $item->quantity), '0'), ',');
        $this->editUnitId = $item->unit_id;
        $this->editAisleId = $item->aisle_id;
        $this->editStoreId = $item->store_id;
        $this->editPrice = $item->paid_price === null ? '' : rtrim(rtrim(str_replace('.', ',', (string) $item->paid_price), '0'), ',');
    }

    public function closeEdit(): void
    {
        $this->editingId = null;
        $this->resetErrorBag();
    }

    #[Computed]
    public function editingItem(): ?ShoppingListItem
    {
        return $this->editingId
            ? ShoppingListItem::with(['ingredient', 'sources', 'checker'])->where('shopping_list_id', $this->listId)->find($this->editingId)
            : null;
    }

    public function saveEdit(): void
    {
        $item = $this->editingItem;

        if (! $item) {
            return;
        }

        $this->validate([
            'editQuantity' => 'nullable|string|max:20',
            'editUnitId' => 'nullable|integer|exists:units,id',
            'editAisleId' => 'nullable|integer|exists:aisles,id',
            'editStoreId' => 'nullable|integer|exists:stores,id',
            'editPrice' => ['nullable', 'regex:/^\d{1,5}([.,]\d{1,2})?$/'],
        ], [
            'editPrice.regex' => 'Prix invalide (ex. 2,49).',
        ], ['editQuantity' => 'quantité', 'editUnitId' => 'unité', 'editAisleId' => 'rayon', 'editStoreId' => 'magasin', 'editPrice' => 'prix payé']);

        $quantityChanged = $this->editQuantity !== ($item->quantity === null ? '' : rtrim(rtrim(str_replace('.', ',', (string) $item->quantity), '0'), ','))
            || $this->editUnitId !== $item->unit_id;

        try {
            if ($quantityChanged) {
                $this->manager()->updateQuantity($item, $this->editQuantity, $this->editUnitId);
            }
        } catch (InvalidArgumentException $e) {
            $this->addError('editQuantity', 'Quantité invalide (ex. 2, 1,5, 1/2).');

            return;
        }

        if ($this->editAisleId !== $item->aisle_id || $this->editStoreId !== $item->store_id) {
            $item->fresh()->update(['aisle_id' => $this->editAisleId, 'store_id' => $this->editStoreId]);
        }

        $this->savePrice($item->fresh());

        $this->closeEdit();
    }

    /**
     * Prix payé (15.7) : gardé sur l'article pour le budget, et relevé dans l'historique des
     * prix de l'ingrédient pour servir de prix de référence (R17).
     */
    private function savePrice(ShoppingListItem $item): void
    {
        $value = trim(str_replace(',', '.', $this->editPrice));
        $price = $value === '' ? null : round((float) $value, 2);

        if ((float) $item->paid_price === (float) $price) {
            return;
        }

        $item->update(['paid_price' => $price]);

        if ($price === null || $price <= 0 || ! $item->ingredient) {
            return;
        }

        app(PriceBook::class)->record(
            ingredient: $item->ingredient,
            price: $price,
            quantity: $item->quantity !== null ? (float) $item->quantity : null,
            unit: $item->unit,
            store: $this->currentList->store,
            on: $item->checked_at ?? now(),
            source: IngredientPrice::SHOPPING,
        );

        $this->dispatch('notify', message: "Prix relevé pour «\u{00A0}{$item->label}\u{00A0}» : il servira à estimer le coût des recettes.");
    }

    /** Change le magasin de la liste : l'ordre des rayons suit (15.3). */
    public function setStore(?int $storeId): void
    {
        $this->currentList->update(['store_id' => $storeId]);
        unset($this->currentList);

        $this->dispatch('notify', message: $storeId
            ? 'Liste rangée dans l\'ordre de ce magasin.'
            : 'Liste rangée dans l\'ordre général des rayons.');
    }

    /** Lot 27 (C1) : la proposition de répartir la liste entre magasins a été écartée pour cette visite. */
    public bool $hideSplit = false;

    /**
     * Articles moins chers dans un autre magasin que celui de la liste (lot 27, C1).
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function splitSuggestions(): Collection
    {
        if ($this->hideSplit || ! $this->currentList->store_id || ! auth()->user()->canEdit() || $this->currentList->isDone()) {
            return collect();
        }

        return app(\App\Services\Pricing\StoreSplit::class)->suggestions($this->currentList);
    }

    /** Réserve à un autre magasin les articles qui y sont moins chers : ils passent dans « Chez … ». */
    public function splitTo(int $storeId): void
    {
        abort_unless(auth()->user()->canEdit(), 403);

        $group = $this->splitSuggestions->firstWhere('store.id', $storeId);

        if (! $group) {
            return;
        }

        $count = app(\App\Services\Pricing\StoreSplit::class)->assign($this->currentList, $group['store'], $group['items']->pluck('item.id')->all());
        unset($this->currentList, $this->splitSuggestions);

        $this->dispatch('notify', message: "{$count} article".($count > 1 ? 's' : '')." à acheter chez {$group['store']->name}.");
    }

    /** Reprend dans ce magasin-ci les articles réservés à un autre. */
    public function bringBack(int $storeId): void
    {
        abort_unless(auth()->user()->canEdit(), 403);

        $count = app(\App\Services\Pricing\StoreSplit::class)->bringBack($this->currentList, $storeId);
        unset($this->currentList, $this->splitSuggestions);

        $this->dispatch('notify', message: "{$count} article".($count > 1 ? 's' : '').' repris dans cette liste.');
    }

    /** Ajoute en un clic un article fréquent (15.6). */
    public function addFrequent(int $ingredientId): void
    {
        $ingredient = Ingredient::findOrFail($ingredientId);

        $this->currentList->items()->create([
            'ingredient_id' => $ingredient->id,
            'label' => $ingredient->name,
            'aisle_id' => $ingredient->aisle_id,
            'origin' => \App\Enums\ItemOrigin::Manual,
        ]);

        unset($this->frequent);
        $this->dispatch('notify', message: "«\u{00A0}{$ingredient->name}\u{00A0}» ajouté à la liste.");
    }

    /** @return \Illuminate\Support\Collection<int, array{ingredient: Ingredient, count: int, last: \Illuminate\Support\Carbon}> */
    #[Computed]
    public function frequent(): Collection
    {
        return $this->manager()->frequentItems($this->currentList);
    }

    #[Computed]
    public function stores(): Collection
    {
        return Store::query()->ordered()->get(['id', 'name', 'color']);
    }

    public function remove(int $itemId): void
    {
        $item = $this->item($itemId);

        // Lot 30 (R32) : un article retiré revient d'un geste, coche comprise.
        $this->undoable('shopping.remove', "« {$item->label} » retiré de la liste", function (\App\Services\Undo\UndoRecorder $r) use ($item) {
            $r->track('shopping_list_items', [$item->id]);
            $this->manager()->setRemoved($item, true);
        });

        $this->closeEdit();
    }

    /** Article couvert par le stock : l'acheter quand même (ou revenir à la déduction). */
    public function buyAnyway(int $itemId, bool $buy = true): void
    {
        $item = $this->manager()->setBuyAnyway($this->item($itemId), $buy);
        $this->closeEdit();
        unset($this->currentList);

        $this->dispatch('notify', message: $buy ? "« {$item->label} » remis dans la liste." : "« {$item->label} » : le stock est de nouveau déduit.");
    }

    public function restore(int $itemId): void
    {
        $this->manager()->setRemoved($this->item($itemId), false);
        $this->closeEdit();
    }

    public function deleteItem(int $itemId): void
    {
        $item = $this->item($itemId);

        $this->undoable('shopping.delete', "« {$item->label} » supprimé de la liste", function (\App\Services\Undo\UndoRecorder $r) use ($item) {
            $r->track('shopping_list_items', [$item->id]);
            $this->manager()->deleteItem($item);
        });

        $this->closeEdit();
    }

    /* ================================================================ Liste */

    public function regenerate(): void
    {
        $list = $this->currentList;

        // Lot 30 (R32) : la mise à jour depuis le planning peut être défaite (articles, quantités, coches).
        $this->changes = $this->undoable('shopping.regenerate', 'Liste mise à jour', function (\App\Services\Undo\UndoRecorder $r) use ($list) {
            $r->track('shopping_lists', [$list->id]);
            $r->track('shopping_list_items', fn () => ShoppingListItem::query()->where('shopping_list_id', $list->id)->pluck('id'));

            return $this->manager()->regenerate($list);
        }, fn (array $changes) => $changes === [] ? null
            : count($changes).' changement'.(count($changes) > 1 ? 's' : '').' appliqué'.(count($changes) > 1 ? 's' : '').'.');

        $this->showChanges = true;

        if ($this->changes === []) {
            $this->dispatch('notify', message: 'La liste était déjà à jour.');
        }
    }

    public function uncheckAll(): void
    {
        $count = $this->manager()->uncheckAll($this->currentList);
        $this->dispatch('notify', message: "{$count} article".($count > 1 ? 's' : '').' décoché'.($count > 1 ? 's' : '').'.');
    }

    public function toggleStatus(): void
    {
        $done = ! $this->currentList->isDone();
        $this->manager()->setStatus($this->currentList, $done ? ListStatus::Done : ListStatus::Active);
        unset($this->currentList);

        $this->dispatch('notify', message: $done ? 'Liste terminée. Bon appétit !' : 'Liste rouverte.');
    }

    public function deleteList(): void
    {
        $this->currentList->delete();
        session()->flash('status', 'Liste supprimée.');
        $this->redirectRoute('shopping.index', navigate: true);
    }

    public function closeText(): void
    {
        $this->showText = false;
    }

    #[Computed]
    public function text(): string
    {
        return $this->manager()->toText($this->currentList);
    }

    /* ================================================================ Données */

    #[Computed]
    public function units(): Collection
    {
        return Unit::query()->ordered()->get(['id', 'label']);
    }

    #[Computed]
    public function aisles(): Collection
    {
        return Aisle::query()->ordered()->get(['id', 'name']);
    }

    #[Computed]
    public function ingredientNames(): array
    {
        return Ingredient::query()->orderBy('name')->pluck('name')->all();
    }

    /** Liste groupée (26.8) : ouverte aux foyers reliés, qui y ajoutent leurs articles. */
    public function toggleShared(\App\Services\Linked\GroupLists $lists): void
    {
        abort_unless(auth()->user()->canEdit(), 403);
        $list = $this->currentList;
        $lists->setShared($list, ! $list->shared_with_links);
        unset($this->currentList);
        $this->dispatch('notify', message: $list->shared_with_links ? 'Liste ouverte à vos proches : ils peuvent y ajouter leurs articles.' : 'Liste refermée.');
    }

    /** Après « Annuler » (lot 30) : la liste est relue. */
    #[On('bouffe-undone')]
    public function afterUndo(): void
    {
        $this->showChanges = false;
        $this->changes = [];
        unset($this->currentList);
    }

    public function render(ShoppingItemPresenter $presenter)
    {
        $list = $this->currentList;
        $grouped = $this->manager()->grouped($list);
        $active = $grouped['aisles']->flatMap(fn ($g) => $g['items'])->merge($grouped['staples']->where('stock_status', '!=', 'covered'));

        return view('livewire.shopping.show', [
            'shoppingList' => $list,
            'grouped' => $grouped,
            'presenter' => $presenter,
            'total' => $active->count(),
            'checked' => $active->where('is_checked', true)->count(),
            // Liste d'un séjour (lot 34) : rien à ranger dans le stock de la maison.
            'toPutAway' => $list->stay_id ? 0 : app(\App\Services\Stock\PutAwayService::class)->pendingCount($list),
            'cost' => app(CostCalculator::class)->shoppingList($list),
            'prices' => app(PriceBook::class),
            'balances' => app(\App\Services\Linked\GroupLists::class)->balances($list),
            'hasLinks' => app(\App\Services\Linked\HouseholdLinks::class)->linkedIds() !== [],
        ])->title($list->name);
    }
}
