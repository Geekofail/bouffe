<?php

namespace App\Livewire\Stock;

use App\Models\StockItem;
use App\Services\Stock\FreezerBoard;
use App\Services\Stock\StockManager;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Le congélateur (16.4).
 *
 * Un congélateur se gère par l'ancienneté : ce qui est là depuis le plus longtemps passe en
 * premier. La page trie donc par âge, propose de planifier les plus vieux plats, et permet
 * d'imprimer des étiquettes pour les boîtes (16.5).
 */
#[Title('Congélateur')]
class Freezer extends Component
{
    /** plats | tout */
    #[Url(as: 'vue', except: 'plats')]
    public string $view = 'plats';

    /** Articles cochés pour l'impression d'étiquettes. */
    public array $selected = [];

    public function toggle(int $id): void
    {
        $this->selected = in_array($id, $this->selected, true)
            ? array_values(array_diff($this->selected, [$id]))
            : [...$this->selected, $id];
    }

    public function selectAll(): void
    {
        $this->selected = $this->rows->pluck('item.id')->map(fn ($id) => (int) $id)->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /** Sorti du congélateur : décongélation (le stock garde la trace). */
    public function thaw(int $id, StockManager $stock): void
    {
        $item = StockItem::active()->findOrFail($id);
        $stock->thaw($item);

        unset($this->rows);
        $this->dispatch('notify', message: "« {$item->name()} » sorti du congélateur.");
    }

    public function finish(int $id, StockManager $stock): void
    {
        $item = StockItem::active()->findOrFail($id);
        $stock->finish($item);

        unset($this->rows);
        $this->dispatch('notify', message: "« {$item->name()} » terminé.");
    }

    /** @return Collection<int, array> */
    #[Computed]
    public function rows(): Collection
    {
        $board = app(FreezerBoard::class);

        return $this->view === 'tout' ? $board->all() : $board->homemade();
    }

    public function render(FreezerBoard $board)
    {
        return view('livewire.stock.freezer', [
            'board' => $board,
            'servings' => $board->servingsInStore(),
            'homemadeCount' => $board->homemade()->count(),
            'totalCount' => $board->all()->count(),
        ]);
    }
}
