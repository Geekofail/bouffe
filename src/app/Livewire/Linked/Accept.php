<?php

namespace App\Livewire\Linked;

use App\Services\Linked\HouseholdLinks;
use App\Support\CurrentHousehold;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Accepter de relier son foyer à celui qui a envoyé le lien (lot 26). Il faut être connecté et
 * responsable du foyer actif ; rien n'est partagé tant que l'un ou l'autre ne l'a pas décidé.
 */
#[Title('Relier deux foyers')]
class Accept extends Component
{
    #[Locked]
    public string $token = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function accept(HouseholdLinks $links)
    {
        abort_unless(Auth::user()->managesHousehold(), 403, 'Réservé aux responsables du foyer.');

        $link = $links->findInvitation($this->token);

        try {
            if (! $link) {
                throw new \InvalidArgumentException('Ce lien n\'est plus valable : demandez-en un nouveau.');
            }

            $links->accept($link, CurrentHousehold::get(), Auth::user());
        } catch (\InvalidArgumentException $e) {
            $this->addError('link', $e->getMessage());

            return null;
        }

        session()->flash('status', 'Foyers reliés. Réglez ici ce que vous partagez avec eux.');

        return $this->redirectRoute('linked.index', navigate: true);
    }

    public function render(HouseholdLinks $links)
    {
        $link = $links->findInvitation($this->token);

        return view('livewire.linked.accept', [
            'link' => $link,
            'usable' => $link?->isUsable() ?? false,
            'from' => $link?->household,
            'household' => CurrentHousehold::get(),
            'owner' => (bool) Auth::user()->managesHousehold(),
        ]);
    }
}
