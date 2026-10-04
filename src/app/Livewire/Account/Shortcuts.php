<?php

namespace App\Livewire\Account;

use App\Services\Shortcuts\ShortcutTokens;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Mon compte › Raccourcis (lot 38, 38.1, règle R39) : le jeton personnel et le mode d'emploi des
 * raccourcis à créer dans l'application Raccourcis de l'iPhone (Siri).
 */
#[Title('Raccourcis et Siri')]
class Shortcuts extends Component
{
    /** Le jeton en clair, montré une seule fois, juste après sa création. */
    public string $plain = '';

    public function create(ShortcutTokens $tokens): void
    {
        $this->plain = $tokens->create(auth()->user());
        $this->dispatch('notify', message: 'Jeton créé : recopiez-le maintenant dans vos raccourcis, il ne sera plus affiché.');
    }

    public function revoke(ShortcutTokens $tokens): void
    {
        $tokens->revoke(auth()->user());
        $this->plain = '';
        $this->dispatch('notify', message: 'Jeton révoqué : les raccourcis ne fonctionnent plus.');
    }

    public function render(ShortcutTokens $tokens)
    {
        return view('livewire.account.shortcuts', [
            'token' => $tokens->active(auth()->user()),
            'urls' => [
                'courses' => route('shortcuts.shopping'),
                'menu' => route('shortcuts.menu'),
                'stock' => route('shortcuts.stock'),
                'inbox' => route('shortcuts.inbox'),
            ],
            'secure' => str_starts_with((string) config('app.url'), 'https://'),
            'canEdit' => auth()->user()->canEdit(),
        ]);
    }
}
