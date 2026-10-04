<?php

namespace App\Livewire\Settings;

use App\Services\System\Diagnostic;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Paramètres → Diagnostic (20.7) et « À propos » (20.6).
 *
 * Une page à ouvrir quand quelque chose cloche, ou avant de partir en vacances : elle dit
 * ce qui va, ce qui est à surveiller, et le geste exact à faire pour chaque point en rouge.
 */
#[Title('Diagnostic')]
class SystemCheck extends Component
{
    public function refresh(): void
    {
        $this->dispatch('flash', message: 'Diagnostic relancé.');
    }

    public function render(Diagnostic $diagnostic)
    {
        $checks = $diagnostic->checks();

        return view('livewire.settings.system-check', [
            'checks' => $checks,
            'summary' => $diagnostic->summary($checks),
            'about' => $diagnostic->about(),
        ]);
    }
}
