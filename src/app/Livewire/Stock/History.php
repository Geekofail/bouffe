<?php

namespace App\Livewire\Stock;

use App\Enums\MovementType;
use App\Models\StockMovement;
use App\Services\Stock\ShelfLifeLearner;
use App\Services\Stock\WasteStats;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Historique du stock et statistiques anti-gaspillage (16.3).
 *
 * Deux choses sur la même page, parce qu'elles répondent à la même question :
 * « qu'est-ce qui se passe avec ce qu'on achète ? » — l'historique pour retrouver un geste,
 * les statistiques pour voir ce qui part à la poubelle régulièrement.
 */
#[Title('Historique du stock')]
class History extends Component
{
    use WithPagination;

    /** Type de mouvement affiché, ou vide pour tout. */
    #[Url(as: 'type', except: '')]
    public string $type = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Nombre de mois du graphique (nom distinct de la variable « months » de la vue). */
    #[Url(as: 'mois', except: 6)]
    public int $range = 6;

    public function updated(string $property): void
    {
        if (in_array($property, ['type', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('type', 'search');
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, StockMovement> */
    public function movements(): LengthAwarePaginator
    {
        return StockMovement::query()
            ->with(['ingredient', 'unit', 'user', 'item.location'])
            ->when($this->type !== '', fn ($query) => $query->where('type', $this->type))
            ->when(trim($this->search) !== '', fn ($query) => $query->where('label', 'like', '%'.trim($this->search).'%'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(30);
    }

    /** Durées observées qui s'écartent du réglage : proposées en correction (16.2). */
    #[Computed]
    public function disagreements()
    {
        return app(ShelfLifeLearner::class)->disagreements()->take(4);
    }

    /** Aligne le réglage de l'ingrédient sur ce qui est réellement observé. */
    public function applyObserved(int $ingredientId): void
    {
        $row = app(ShelfLifeLearner::class)->disagreements()->firstWhere('ingredient.id', $ingredientId);

        if (! $row) {
            return;
        }

        $row['ingredient']->update(['shelf_life_days' => $row['observed']]);

        unset($this->disagreements);
        $this->dispatch('notify', message: "« {$row['ingredient']->name} » : conservation réglée sur {$row['observed']} jours, d'après vos achats.");
    }

    public function render(WasteStats $stats)
    {
        $months = $stats->months($this->range);

        return view('livewire.stock.history', [
            'movements' => $this->movements(),
            'types' => MovementType::cases(),
            'summary' => $stats->summary($this->range),
            'months' => $months,
            'peak' => max(1, collect($months)->max(fn ($m) => $m['wasted'] + $m['consumed'])),
            'topWasted' => $stats->topWasted(),
            'stats' => $stats,
        ]);
    }
}
