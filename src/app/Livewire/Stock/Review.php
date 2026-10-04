<?php

namespace App\Livewire\Stock;

use App\Enums\MovementType;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Services\QuantityFormatter;
use App\Services\Stock\StockManager;
use App\Services\Stock\StockReview;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Inventaire par ancienneté (30.8) : les articles qui n'ont pas bougé depuis 2 mois, un par un.
 */
#[Title('Revue du stock')]
class Review extends Component
{
    #[Url(as: 'emplacement', except: 0)]
    public int $location = 0;

    /** Articles passés pendant cette revue (revus plus tard). @var list<int> */
    public array $skipped = [];

    public int $done = 0;

    #[Computed]
    public function items(): Collection
    {
        return app(StockReview::class)->items($this->location ?: null);
    }

    #[Computed]
    public function current(): ?StockItem
    {
        return $this->items->first(fn (StockItem $item) => ! in_array($item->id, $this->skipped, true));
    }

    #[Computed]
    public function locations(): Collection
    {
        $counts = app(StockReview::class)->countsByLocation();

        return StorageLocation::query()->orderBy('sort_order')->get()
            ->map(fn (StorageLocation $l) => ['id' => $l->id, 'name' => $l->name, 'count' => $counts->get($l->id, 0)])
            ->filter(fn (array $l) => $l['count'] > 0)
            ->values();
    }

    public function keep(int $itemId): void
    {
        $item = $this->item($itemId);
        app(StockReview::class)->keep($item);
        $this->next("« {$item->name()} » : toujours là.");
    }

    public function finish(int $itemId, string $how): void
    {
        $item = $this->item($itemId);
        $type = $how === 'jete' ? MovementType::Waste : MovementType::Consume;

        try {
            $movement = app(StockManager::class)->finish($item, $type, $type === MovementType::Waste ? 'oublié' : null);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());

            return;
        }

        // Le retrait du stock garde son propre « Annuler » (R32).
        $this->done++;
        unset($this->items, $this->current, $this->locations);
        $this->dispatch('notify',
            message: "« {$item->name()} » : ".($type === MovementType::Waste ? 'jeté.' : 'fini.'),
            action: ['label' => 'Annuler', 'event' => 'review-undo', 'params' => ['movementId' => $movement->id]],
            duration: 10000,
        );
    }

    public function skip(int $itemId): void
    {
        $this->skipped[] = $itemId;
        unset($this->current);
    }

    public function restart(): void
    {
        $this->skipped = [];
        unset($this->current);
    }

    #[On('review-undo')]
    public function undo(int $movementId, StockManager $stock): void
    {
        $movement = StockMovement::find($movementId);

        try {
            $item = $movement ? $stock->undo($movement) : null;
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());

            return;
        }

        $this->done = max(0, $this->done - 1);
        unset($this->items, $this->current, $this->locations);

        if ($item) {
            $this->dispatch('notify', message: "Annulé : « {$item->name()} » est de nouveau dans le stock.");
        }
    }

    private function item(int $itemId): StockItem
    {
        return StockItem::query()->active()->with('ingredient')->findOrFail($itemId);
    }

    private function next(string $message): void
    {
        $this->done++;
        unset($this->items, $this->current, $this->locations);
        $this->dispatch('notify', message: $message);
    }

    public function render(QuantityFormatter $formatter)
    {
        return view('livewire.stock.review', ['formatter' => $formatter, 'days' => StockReview::IDLE_DAYS]);
    }
}
