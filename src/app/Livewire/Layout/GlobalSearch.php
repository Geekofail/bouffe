<?php

namespace App\Livewire\Layout;

use App\Services\Search\GlobalSearch as SearchService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Fenêtre de recherche globale (12.1), ouverte par Ctrl+K, « / » ou la loupe de l'en-tête.
 */
class GlobalSearch extends Component
{
    public bool $show = false;

    public string $query = '';

    #[On('open-search')]
    public function open(): void
    {
        $this->reset('query');
        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
    }

    #[Computed]
    public function results(): array
    {
        return $this->show ? app(SearchService::class)->search($this->query) : [];
    }

    public function render()
    {
        return view('livewire.layout.global-search');
    }
}
