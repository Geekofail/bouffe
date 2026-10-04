<?php

namespace App\Livewire\Layout;

use App\Services\Undo\UndoManager;
use App\Services\Undo\UndoRefused;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Le bouton « Annuler » des messages (30.1, R32) : quel que soit l'écran, c'est ici que l'annulation
 * est faite, puis la page est prévenue pour se redessiner.
 */
class Undo extends Component
{
    #[On('bouffe-undo')]
    public function undo(string $token, UndoManager $undo): void
    {
        try {
            $record = $undo->undo($token);
        } catch (UndoRefused $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());

            return;
        }

        $this->dispatch('bouffe-undone', action: $record->action);
        $this->dispatch('notify', message: 'Annulé : '.mb_strtolower(mb_substr($record->label, 0, 1)).mb_substr($record->label, 1).'.');
    }

    public function render()
    {
        return <<<'HTML'
            <div hidden></div>
            HTML;
    }
}
