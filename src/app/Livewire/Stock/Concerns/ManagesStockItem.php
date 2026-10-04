<?php

namespace App\Livewire\Stock\Concerns;

use App\Enums\MovementType;
use App\Models\Ingredient;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Services\QuantityFormatter;
use App\Services\QuantityParser;
use InvalidArgumentException;
use Livewire\Attributes\Computed;

/**
 * Stock — un article : fini, jeté, reste, ouvert, congeler, déplacer, présence, annuler
 * (découpé d'Index au lot 36).
 */
trait ManagesStockItem
{
    public function select(int $itemId): void
    {
        $item = StockItem::active()->findOrFail($itemId);

        $this->resetErrorBag();
        $this->selectedId = $item->id;
        $this->editQuantity = $item->quantity === null ? '' : app(QuantityFormatter::class)->number((float) $item->quantity, 3);
        $this->editUnitId = $item->unit_id;
        $this->editExpiresOn = (string) $item->expires_on?->toDateString();
        $this->editExpiryType = $item->expiry_type->value;
        $this->editNote = (string) $item->note;
        $this->showWaste = false;
    }

    public function closeItem(): void
    {
        $this->selectedId = null;
        $this->showWaste = false;
    }

    #[Computed]
    public function selectedItem(): ?StockItem
    {
        return $this->selectedId ? StockItem::active()->with('ingredient', 'unit', 'location')->find($this->selectedId) : null;
    }

    public function finish(int $itemId): void
    {
        $this->act($itemId, fn (StockItem $item) => $this->stock()->finish($item), fn ($item) => "« {$item->name()} » terminé.");
    }

    public function waste(int $itemId, string $reason): void
    {
        $reason = in_array($reason, self::WASTE_REASONS, true) ? $reason : 'autre';
        $this->act($itemId, fn (StockItem $item) => $this->stock()->finish($item, MovementType::Waste, $reason), fn ($item) => "« {$item->name()} » jeté ({$reason}).");
    }

    public function remaining(int $itemId, string $fraction): void
    {
        $value = ['3/4' => 0.75, '1/2' => 0.5, '1/4' => 0.25][$fraction] ?? null;

        if ($value) {
            $this->act($itemId, fn (StockItem $item) => $this->stock()->setRemaining($item, $value), fn ($item) => "« {$item->name()} » : il en reste ".['3/4' => '¾', '1/2' => '½', '1/4' => '¼'][$fraction].'.', close: false);
        }
    }

    public function openItem(int $itemId): void
    {
        $this->act($itemId, fn (StockItem $item) => $this->stock()->open($item), fn ($item) => "« {$item->name()} » ouvert aujourd'hui.", close: false);
    }

    public function freeze(int $itemId): void
    {
        $this->act($itemId, fn (StockItem $item) => $this->stock()->freeze($item), fn ($item) => "« {$item->name()} » congelé.");
    }

    public function thaw(int $itemId): void
    {
        $this->act($itemId, fn (StockItem $item) => $this->stock()->thaw($item), fn ($item) => "« {$item->name()} » décongelé : à consommer sous 24 h.");
    }

    public function moveTo(int $itemId, int $locationId): void
    {
        $this->act($itemId, fn (StockItem $item) => $this->stock()->move($item, $locationId), fn ($item) => "« {$item->name()} » déplacé : ".StorageLocation::find($locationId)?->name.'.');
    }

    public function saveItem(QuantityParser $parser): void
    {
        $this->validate([
            'editExpiresOn' => 'nullable|date',
            'editExpiryType' => 'in:dlc,ddm,none',
            'editNote' => 'nullable|string|max:255',
        ], [], ['editExpiresOn' => 'date', 'editNote' => 'note']);

        $quantity = $parser->tryParse($this->editQuantity);

        if ($quantity === false || $quantity === 0.0) {
            $this->addError('editQuantity', 'Quantité invalide.');

            return;
        }

        $this->act($this->selectedId, fn (StockItem $item) => $this->stock()->edit($item, [
            'quantity' => $quantity,
            'unit_id' => $this->editUnitId,
            'expires_on' => $this->editExpiresOn ?: null,
            'expiry_type' => $this->editExpiryType,
            'note' => $this->editNote,
        ]), fn ($item) => "« {$item->name()} » modifié.");
    }

    public function togglePresence(int $ingredientId, bool $present): void
    {
        $ingredient = Ingredient::findOrFail($ingredientId);

        try {
            $movement = $this->stock()->setPresence($ingredient, $present);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());

            return;
        }

        $this->closeItem();
        unset($this->groups, $this->outOfStock, $this->locations);

        if ($movement) {
            $this->offerUndo($movement, $present ? "« {$ingredient->name} » en stock." : "Plus de « {$ingredient->name} ».");
        }

        $this->restockOffer = $present ? null : ['ingredient_id' => $ingredient->id, 'name' => $ingredient->name];
    }

    public function undo(): void
    {
        $offer = $this->undoOffer;
        $this->undoOffer = null;

        if (! $offer || ! ($movement = StockMovement::find($offer['movement_id']))) {
            return;
        }

        try {
            $item = $this->stock()->undo($movement);
            $this->dispatch('notify', message: "Action annulée : « {$item->name()} ».");
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());
        }

        $this->restockOffer = null;
        unset($this->groups, $this->outOfStock, $this->locations);
    }

    public function dismissUndo(): void
    {
        $this->undoOffer = null;
    }

    private function act(?int $itemId, callable $action, callable $message, bool $close = true): void
    {
        $item = $itemId ? StockItem::active()->with('ingredient', 'location')->find($itemId) : null;

        if (! $item) {
            $this->closeItem();

            return;
        }

        try {
            $movement = $action($item);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());

            return;
        }

        if ($close || $item->fresh()->finished_at) {
            $this->closeItem();
        } else {
            $this->select($item->id);
        }

        unset($this->groups, $this->selectedItem, $this->locations);
        $this->offerUndo($movement, $message($item->fresh(['ingredient', 'location'])));
    }

    private function offerUndo(?StockMovement $movement, string $message): void
    {
        unset($this->groups, $this->outOfStock, $this->locations);
        $this->undoOffer = $movement ? ['movement_id' => $movement->id, 'message' => $message] : null;
    }
}
