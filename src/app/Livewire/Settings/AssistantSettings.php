<?php

namespace App\Livewire\Settings;

use App\Models\AssistantUsage;
use App\Services\Assistant\AssistantService;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Paramètres → Assistant culinaire (33.5, Q46, R34).
 *
 * Activer ou non, plafond mensuel en euros, clé Mistral (la même que pour les tickets), et ce qui a
 * été consommé. Ces réglages valent pour toute l'installation : seul l'administrateur les modifie.
 */
#[Title('Assistant culinaire')]
class AssistantSettings extends Component
{
    public bool $enabled = true;

    public string $monthlyCap = '1';

    public string $mistralKey = '';

    public function mount(AssistantService $assistant): void
    {
        $this->enabled = $assistant->enabled();
        $this->monthlyCap = rtrim(rtrim(number_format($assistant->cap(), 2, '.', ''), '0'), '.');
    }

    public function save(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $this->monthlyCap = str_replace(',', '.', trim($this->monthlyCap));
        $this->validate([
            'enabled' => ['boolean'],
            'monthlyCap' => ['required', 'numeric', 'min:0', 'max:100'],
            'mistralKey' => ['nullable', 'string', 'max:200'],
        ], [], ['monthlyCap' => 'plafond', 'mistralKey' => 'clé Mistral']);

        Settings::set('assistant.enabled', $this->enabled);
        Settings::set('assistant.monthly_cap', round((float) $this->monthlyCap, 2));

        if (trim($this->mistralKey) !== '') {
            Settings::set('receipts.mistral_key', trim($this->mistralKey));
        }

        $this->reset('mistralKey');
        $this->dispatch('notify', message: 'Réglages de l\'assistant enregistrés.');
    }

    private function keySource(): ?string
    {
        if (Settings::stored('receipts.mistral_key')) {
            return Settings::secret('receipts.mistral_key') !== null ? 'Enregistrée dans Bouffe (chiffrée)' : 'Illisible : saisissez-la de nouveau';
        }

        return Settings::secret('receipts.mistral_key') !== null ? 'Lue dans le fichier .env' : null;
    }

    public function render(AssistantService $assistant)
    {
        $months = collect(range(5, 0))->map(function (int $ago) use ($assistant) {
            $month = Carbon::today()->startOfMonth()->subMonths($ago);

            return ['label' => ucfirst($month->locale('fr')->isoFormat('MMMM YYYY'))] + $assistant->usage($month);
        });

        return view('livewire.settings.assistant', [
            'status' => $assistant->status(),
            'usage' => $assistant->usage(),
            'months' => $months,
            'kinds' => AssistantUsage::KINDS,
            'keySource' => $this->keySource(),
        ]);
    }
}
