<?php

namespace App\Livewire\Stock;

use App\Enums\MovementType;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Services\QuantityFormatter;
use App\Services\Stock\ExpiryAlerts;
use App\Services\Stock\StockManager;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Carte « À consommer rapidement » (11.4, 11.5) : produits à surveiller et actions directes.
 */
class ExpiryAlertsCard extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;

    #[Locked]
    public int $limit = 6;

    /** Article dont on saisit une nouvelle date / une raison de mise à la poubelle. */
    public ?int $datingId = null;

    public string $newDate = '';

    public ?int $wastingId = null;

    /** @var array{movement_id: int, message: string}|null */
    public ?array $undoOffer = null;

    public function consume(int $itemId): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $this->act($itemId, fn (StockItem $i) => app(StockManager::class)->finish($i), fn (StockItem $i) => "« {$i->name()} » consommé.");
    }

    public function askWaste(int $itemId): void
    {
        $this->wastingId = $this->wastingId === $itemId ? null : $itemId;
        $this->datingId = null;
    }

    public function waste(int $itemId, string $reason): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $reason = in_array($reason, Index::WASTE_REASONS, true) ? $reason : 'autre';
        $this->act($itemId, fn (StockItem $i) => app(StockManager::class)->finish($i, MovementType::Waste, $reason), fn (StockItem $i) => "« {$i->name()} » jeté ({$reason}).");
    }

    public function freeze(int $itemId): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $this->act($itemId, fn (StockItem $i) => app(StockManager::class)->freeze($i), fn (StockItem $i) => "« {$i->name()} » congelé.");
    }

    public function open(int $itemId): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $this->act($itemId, fn (StockItem $i) => app(StockManager::class)->open($i), fn (StockItem $i) => "« {$i->name()} » ouvert aujourd'hui.");
    }

    public function askDate(int $itemId): void
    {
        $item = StockItem::active()->find($itemId);
        $this->datingId = $item && $this->datingId !== $itemId ? $item->id : null;
        $this->newDate = (string) $item?->expires_on?->toDateString();
        $this->wastingId = null;
        $this->resetErrorBag();
    }

    public function saveDate(): void
    {
        $this->validate(['newDate' => 'required|date'], [], ['newDate' => 'date']);
        $date = $this->newDate;

        $this->act($this->datingId, fn (StockItem $i) => app(StockManager::class)->edit($i, ['expires_on' => $date]),
            fn (StockItem $i) => "« {$i->name()} » : nouvelle date le ".$i->expires_on->locale('fr')->isoFormat('D MMMM').'.');
    }

    public function undo(): void
    {
        $offer = $this->undoOffer;
        $this->undoOffer = null;

        if ($offer && ($movement = StockMovement::find($offer['movement_id']))) {
            try {
                app(StockManager::class)->undo($movement);
            } catch (InvalidArgumentException $e) {
                $this->dispatch('notify', type: 'warning', message: $e->getMessage());
            }
        }

        unset($this->alerts);
    }

    public function dismissUndo(): void
    {
        $this->undoOffer = null;
    }

    private function act(?int $itemId, callable $action, callable $message): void
    {
        $item = $itemId ? StockItem::active()->with('ingredient')->find($itemId) : null;
        $this->reset('datingId', 'wastingId', 'newDate');

        if (! $item) {
            return;
        }

        try {
            $movement = $action($item);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());

            return;
        }

        unset($this->alerts, $this->recipeIdeas);
        $this->undoOffer = ['movement_id' => $movement->id, 'message' => $message($item->fresh('ingredient'))];
    }

    /** Stock modifié ailleurs sur la page (repas mangé). */
    #[On('stock-changed')]
    public function refreshAlerts(): void
    {
        unset($this->alerts, $this->recipeIdeas);
    }

    /**
     * « À utiliser rapidement » (10.4) : jusqu'à 3 ingrédients à consommer (hors DLC dépassée)
     * et le nombre de recettes faisables ou presque qui les utilisent.
     *
     * @return array{ingredients: \Illuminate\Support\Collection<int, \App\Models\Ingredient>, count: int}|null
     */
    #[Computed]
    public function recipeIdeas(): ?array
    {
        $ingredients = $this->alerts
            ->reject(fn (array $row) => $row['level'] === \App\Enums\ExpiryLevel::Expired || ! $row['item']->ingredient || $row['item']->isFrozen())
            ->map(fn (array $row) => $row['item']->ingredient)
            ->unique('id')->take(3)->values();

        if ($ingredients->isEmpty()) {
            return null;
        }

        $results = app(\App\Services\Stock\RecipeSuggester::class)->suggest([
            'servings' => app(\App\Services\Planning\OccasionService::class)->householdSize(),
            'mustUse' => $ingredients->pluck('id')->all(),
        ]);

        return ['ingredients' => $ingredients, 'count' => $results['feasible']->count() + $results['almost']->count()];
    }

    #[Computed]
    public function alerts(): \Illuminate\Support\Collection
    {
        return app(ExpiryAlerts::class)->items();
    }

    public function render()
    {
        return view('livewire.stock.expiry-alerts-card', [
            'shown' => $this->alerts->take($this->limit),
            'formatter' => app(QuantityFormatter::class),
            'hasStock' => StockItem::active()->exists(),
        ]);
    }
}
