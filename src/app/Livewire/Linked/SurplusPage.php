<?php

namespace App\Livewire\Linked;

use App\Models\StockItem;
use App\Models\SurplusOffer;
use App\Services\Linked\HouseholdLinks;
use App\Services\Linked\Surplus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Surplus à donner (26.7) : nos annonces, celles des foyers reliés, réservation en un geste.
 */
#[Title('Surplus à donner')]
class SurplusPage extends Component
{
    #[Url(as: 'article', except: null)]
    public ?int $stockItemId = null;

    public string $label = '';

    public string $quantity = '';

    public string $until = '';

    public string $note = '';

    public function mount(): void
    {
        $this->until = Carbon::today()->addDays(3)->toDateString();

        if ($this->stockItemId && ($item = StockItem::query()->active()->find($this->stockItemId))) {
            $this->label = $item->name();
            $this->until = ($item->expires_on && $item->expires_on->gte(Carbon::today()) ? $item->expires_on : Carbon::today()->addDays(3))->toDateString();
        }
    }

    public function updatedStockItemId(): void
    {
        $item = $this->stockItemId ? StockItem::query()->active()->find($this->stockItemId) : null;

        if ($item) {
            $this->label = $item->name();
            $this->until = ($item->expires_on && $item->expires_on->gte(Carbon::today()) ? $item->expires_on : Carbon::today()->addDays(3))->toDateString();
        }
    }

    public function offer(Surplus $surplus): void
    {
        $this->requireEdit();
        $this->validate([
            'label' => 'required_without:stockItemId|nullable|string|max:150',
            'quantity' => 'nullable|string|max:60',
            'until' => 'required|date|after_or_equal:today',
            'note' => 'nullable|string|max:255',
        ], [], ['label' => 'article', 'until' => 'date limite', 'quantity' => 'quantité']);

        try {
            $surplus->offer(['label' => $this->label, 'stock_item_id' => $this->stockItemId, 'quantity' => $this->quantity, 'available_until' => $this->until, 'note' => $this->note], Auth::user());
        } catch (\InvalidArgumentException $e) {
            $this->addError('label', $e->getMessage());

            return;
        }

        $this->reset('label', 'quantity', 'note', 'stockItemId');
        $this->until = Carbon::today()->addDays(3)->toDateString();
        $this->dispatch('notify', message: 'Annonce publiée auprès de vos proches.');
    }

    public function cancel(int $id, Surplus $surplus): void
    {
        $this->requireEdit();
        $surplus->cancel(SurplusOffer::query()->findOrFail($id));
    }

    public function handOver(int $id, Surplus $surplus): void
    {
        $this->requireEdit();
        $surplus->handOver(SurplusOffer::query()->findOrFail($id));
        $this->dispatch('notify', message: 'Remis. L\'article est retiré du stock.');
    }

    public function reserve(int $id, Surplus $surplus): void
    {
        try {
            $surplus->reserve($id, Auth::user());
            $this->dispatch('notify', message: 'Réservé. Il ne reste qu\'à passer le chercher.');
        } catch (\InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());
        }
    }

    public function release(int $id, Surplus $surplus): void
    {
        $surplus->release($id);
    }

    private function requireEdit(): void
    {
        abort_unless(Auth::user()?->canEdit(), 403, 'Réservé aux comptes complets du foyer.');
    }

    public function render(Surplus $surplus, HouseholdLinks $links)
    {
        return view('livewire.linked.surplus', [
            'mine' => $surplus->mine(),
            'theirs' => $surplus->fromLinked(),
            'hasLinks' => $links->linkedIds() !== [],
            'stockItems' => StockItem::query()->active()->with('ingredient')->get()->sortBy(fn ($i) => $i->name())->values(),
            'canEdit' => (bool) Auth::user()?->canEdit(),
            'me' => \App\Support\CurrentHousehold::id(),
        ]);
    }
}
