<?php

namespace App\Livewire\Stock;

use App\Enums\MovementType;
use App\Enums\StockMode;
use App\Models\StockItem;
use App\Models\StorageLocation;
use App\Services\QuantityFormatter;
use App\Services\QuantityParser;
use App\Services\Stock\StockManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Inventaire d'un emplacement (9.11) : on passe les articles un par un
 * (toujours là · quantité · plus là), puis « Terminer ».
 */
class Inventory extends Component
{
    #[Locked]
    public int $locationId;

    /** État par article : ok · qty · gone */
    #[Locked]
    public array $done = [];

    /** Articles retirés pendant cet inventaire (restent affichés, barrés). */
    #[Locked]
    public array $goneIds = [];

    /** Article dont on saisit la quantité. */
    public ?int $editingId = null;

    public string $quantity = '';

    public function mount(StorageLocation $location): void
    {
        $this->locationId = $location->id;
    }

    #[Computed]
    public function location(): StorageLocation
    {
        return StorageLocation::findOrFail($this->locationId);
    }

    /** @return Collection<int, StockItem> */
    #[Computed]
    public function items(): Collection
    {
        return StockItem::query()
            ->with('ingredient', 'unit')
            ->where('storage_location_id', $this->locationId)
            ->where(fn ($q) => $q->whereNull('finished_at')->orWhereIn('id', $this->goneIds ?: [0]))
            ->get()
            ->sortBy(fn (StockItem $item) => \App\Support\NameNormalizer::normalize($item->name()))
            ->values();
    }

    public function confirm(int $itemId): void
    {
        if ($this->item($itemId)) {
            $this->done[$itemId] = 'ok';
            $this->editingId = null;
        }
    }

    public function editQuantity(int $itemId): void
    {
        $item = $this->item($itemId);

        if (! $item) {
            return;
        }

        $this->resetErrorBag();
        $this->editingId = $item->id;
        $this->quantity = $item->quantity === null ? '' : app(QuantityFormatter::class)->number((float) $item->quantity, 3);
    }

    public function saveQuantity(QuantityParser $parser, StockManager $stock): void
    {
        $item = $this->editingId ? $this->item($this->editingId) : null;

        if (! $item) {
            return;
        }

        $this->resetErrorBag();
        $quantity = $parser->tryParse($this->quantity);

        if ($quantity === false || $quantity === null) {
            $this->addError('quantity', 'Quantité invalide (0 = plus là).');

            return;
        }

        try {
            $quantity <= 0 ? $this->markGone($item, $stock) : $stock->setQuantity($item, $quantity);
        } catch (InvalidArgumentException $e) {
            $this->addError('quantity', $e->getMessage());

            return;
        }

        $this->done[$item->id] = $quantity <= 0 ? 'gone' : 'qty';
        $this->editingId = null;
        unset($this->items);
    }

    public function gone(int $itemId, StockManager $stock): void
    {
        $item = $this->item($itemId);

        if ($item) {
            $this->markGone($item, $stock);
            $this->done[$item->id] = 'gone';
            $this->editingId = null;
            unset($this->items);
        }
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
    }

    public function finish(): void
    {
        $location = $this->location;
        $location->update(['last_inventory_at' => Carbon::now()]);

        $gone = count(array_filter($this->done, fn ($s) => $s === 'gone'));
        $changed = count(array_filter($this->done, fn ($s) => $s === 'qty'));
        $parts = array_filter([
            $gone ? $gone.' retiré'.($gone > 1 ? 's' : '') : null,
            $changed ? $changed.' quantité'.($changed > 1 ? 's' : '').' corrigée'.($changed > 1 ? 's' : '') : null,
        ]);

        session()->flash('status', "Inventaire « {$location->name} » terminé".($parts ? ' : '.implode(', ', $parts) : '').'.');
        $this->redirectRoute('stock.index', ['emplacement' => $location->id], navigate: true);
    }

    public function render(QuantityFormatter $formatter)
    {
        $total = $this->items->count();

        return view('livewire.stock.inventory', [
            'formatter' => $formatter,
            'total' => $total,
            'checked' => count($this->done),
        ])->title('Inventaire · '.$this->location->name);
    }

    private function item(int $itemId): ?StockItem
    {
        return StockItem::active()->where('storage_location_id', $this->locationId)->find($itemId);
    }

    private function markGone(StockItem $item, StockManager $stock): void
    {
        $stock->finish($item, MovementType::Adjust, 'inventaire');
        $this->goneIds[] = $item->id;
    }

    public function isPresence(StockItem $item): bool
    {
        return $item->ingredient?->stock_mode === StockMode::Presence;
    }
}
