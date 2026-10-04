<?php

namespace App\Livewire\Linked;

use App\Services\Linked\FamilyBook;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Carnet familial (26.9) : choisir les recettes (les nôtres et celles des proches), puis imprimer
 * ou enregistrer en PDF depuis le navigateur.
 */
#[Title('Carnet familial')]
class FamilyBookPage extends Component
{
    public string $title = 'Le carnet de la famille';

    /** @var list<int> */
    public array $selected = [];

    public function toggleGroup(array $ids): void
    {
        $ids = array_map('intval', $ids);
        $all = array_diff($ids, $this->selected) === [];
        $this->selected = $all ? array_values(array_diff($this->selected, $ids)) : array_values(array_unique([...$this->selected, ...$ids]));
    }

    public function render(FamilyBook $book)
    {
        $selected = array_values(array_unique(array_map('intval', $this->selected)));

        return view('livewire.linked.family-book', [
            'groups' => $book->candidates(),
            'printUrl' => $selected === [] ? null : route('linked.book.print', ['recettes' => implode(',', $selected), 'titre' => $this->title]),
            'count' => count($selected),
        ]);
    }
}
