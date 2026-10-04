<?php

namespace App\Livewire\Settings;

use App\Support\Navigation;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Paramètres → Affichage (lot 11) : barre du bas du téléphone, blocs de l'accueil (par utilisateur),
 * thème (par appareil, enregistré dans le navigateur).
 */
#[Title('Affichage')]
class Display extends Component
{
    /** @var list<string> 4 raccourcis */
    public array $bottom = [];

    /** @var list<array{key: string, label: string, visible: bool}> */
    public array $home = [];

    public function mount(): void
    {
        $this->bottom = Navigation::bottom(auth()->user());
        $this->home = Navigation::home(auth()->user());
    }

    /** Lot 29 (29.8) : taille du texte, par compte. */
    public function setTextSize(string $size): void
    {
        auth()->user()->setPreference('text_size', $size === 'grand' ? 'grand' : 'normal');
        $this->redirectRoute('settings.display', navigate: false);
    }

    /** Lot 30 (30.3) : aide contextuelle — tout réafficher, ou ne plus jamais en montrer. */
    public function resetHints(): void
    {
        auth()->user()->setPreference('hints_seen', []);
        auth()->user()->setPreference('hints_off', false);
        $this->dispatch('notify', message: 'Les aides « Le saviez-vous ? » s\'afficheront de nouveau.');
    }

    public function toggleHints(): void
    {
        $user = auth()->user();
        $user->setPreference('hints_off', ! $user->preference('hints_off', false));
    }

    public function saveBottom(): void
    {
        $this->validate([
            'bottom' => ['required', 'array', 'size:'.Navigation::BOTTOM_SIZE],
            'bottom.*' => ['required', 'distinct', Rule::in(array_keys(Navigation::SECTIONS))],
        ], [
            'bottom.*.distinct' => 'Chaque raccourci ne peut être choisi qu\'une fois.',
        ], ['bottom.*' => 'raccourci']);

        auth()->user()->setPreference('bottom_nav', array_values($this->bottom));
        $this->dispatch('notify', message: 'Barre du bas enregistrée : elle s\'affiche à la prochaine page ouverte.');
    }

    public function resetBottom(): void
    {
        $this->bottom = Navigation::DEFAULT_BOTTOM;
        $this->saveBottom();
    }

    public function moveHome(int $index, int $direction): void
    {
        $target = $index + $direction;

        if (! isset($this->home[$index], $this->home[$target])) {
            return;
        }

        [$this->home[$index], $this->home[$target]] = [$this->home[$target], $this->home[$index]];
        $this->saveHome();
    }

    public function toggleHome(int $index): void
    {
        if (isset($this->home[$index])) {
            $this->home[$index]['visible'] = ! $this->home[$index]['visible'];
            $this->saveHome();
        }
    }

    public function resetHome(): void
    {
        auth()->user()->setPreference('home', []);
        $this->home = Navigation::home(auth()->user());
        $this->dispatch('notify', message: 'Accueil remis dans l\'ordre d\'origine.');
    }

    private function saveHome(): void
    {
        $rows = collect($this->home)
            ->filter(fn ($row) => isset(Navigation::HOME_SECTIONS[$row['key'] ?? '']))
            ->map(fn ($row) => ['key' => $row['key'], 'visible' => (bool) $row['visible']])
            ->values()->all();

        auth()->user()->setPreference('home', $rows);
        $this->home = Navigation::home(auth()->user()->fresh());
    }

    public function render()
    {
        return view('livewire.settings.display', [
            'sections' => Navigation::SECTIONS,
            'textSize' => auth()->user()->preference('text_size', 'normal') === 'grand' ? 'grand' : 'normal',
            'hintsOff' => (bool) auth()->user()->preference('hints_off', false),
            'hintsSeen' => count((array) auth()->user()->preference('hints_seen', [])),
            'hintsTotal' => count(\App\Http\Controllers\HintController::KEYS),
        ]);
    }
}
