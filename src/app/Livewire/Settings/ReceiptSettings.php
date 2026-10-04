<?php

namespace App\Livewire\Settings;

use App\Models\ReceiptLabelMapping;
use App\Services\Receipts\OcrService;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Paramètres → Tickets de caisse (lot 23 : Q32, 24.5, 24.7, 24.8).
 *
 * Les clés d'API saisies ici sont chiffrées en base (APP_KEY) et ne sont jamais réaffichées ;
 * celles de .env restent possibles. Un champ laissé vide ne change pas la clé enregistrée.
 */
#[Title('Tickets de caisse')]
class ReceiptSettings extends Component
{
    public string $provider = 'mistral';

    public string $mistralKey = '';

    public string $azureEndpoint = '';

    public string $azureKey = '';

    public int $monthlyCap = 50;

    public int $keepMonths = 12;

    public string $search = '';

    public function mount(): void
    {
        $this->provider = (string) Settings::get('receipts.provider', 'mistral');
        $this->azureEndpoint = (string) (Settings::get('receipts.azure_endpoint') ?? '');
        $this->monthlyCap = Settings::int('receipts.monthly_cap', 50);
        $this->keepMonths = Settings::int('receipts.keep_months', 12);
    }

    public function save(): void
    {
        // Service, clés, plafond et conservation valent pour toute l'installation (lot 24).
        abort_unless(auth()->user()->isAdmin(), 403);

        $this->validate([
            'provider' => ['required', Rule::in(['mistral', 'azure', 'none'])],
            'mistralKey' => ['nullable', 'string', 'max:200'],
            'azureEndpoint' => ['nullable', 'url:https', 'max:200'],
            'azureKey' => ['nullable', 'string', 'max:200'],
            'monthlyCap' => ['required', 'integer', 'min:0', 'max:1000'],
            'keepMonths' => ['required', 'integer', 'min:0', 'max:60'],
        ], [], [
            'mistralKey' => 'clé Mistral', 'azureEndpoint' => 'point de terminaison', 'azureKey' => 'clé Azure',
            'monthlyCap' => 'plafond', 'keepMonths' => 'durée de conservation',
        ]);

        Settings::set('receipts.provider', $this->provider);
        Settings::set('receipts.monthly_cap', $this->monthlyCap);
        Settings::set('receipts.keep_months', $this->keepMonths);
        Settings::set('receipts.azure_endpoint', $this->azureEndpoint);

        foreach (['mistralKey' => 'receipts.mistral_key', 'azureKey' => 'receipts.azure_key'] as $property => $key) {
            if (trim($this->{$property}) !== '') {
                Settings::set($key, $this->{$property});
            }
        }

        $this->reset('mistralKey', 'azureKey');
        $this->dispatch('notify', message: 'Réglages des tickets enregistrés.');
    }

    public function forgetKey(string $which): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $key = ['mistral' => 'receipts.mistral_key', 'azure' => 'receipts.azure_key'][$which] ?? null;

        if ($key) {
            Settings::set($key, '');
            $this->dispatch('notify', message: 'Clé retirée de Bouffe.');
        }
    }

    public function forgetMapping(int $id): void
    {
        ReceiptLabelMapping::whereKey($id)->delete();
    }

    /** « Enregistrée dans Bouffe », « Lue dans .env » ou rien. */
    private function keySource(string $key): ?string
    {
        if (Settings::stored($key)) {
            return Settings::secret($key) !== null ? 'Enregistrée dans Bouffe (chiffrée)' : 'Illisible : saisissez-la de nouveau';
        }

        return Settings::secret($key) !== null ? 'Lue dans le fichier .env' : null;
    }

    public function render(OcrService $ocr)
    {
        $months = collect(range(5, 0))->map(function (int $ago) use ($ocr) {
            $month = Carbon::today()->startOfMonth()->subMonths($ago);

            return ['label' => ucfirst($month->locale('fr')->isoFormat('MMMM YYYY'))] + $ocr->usage($month);
        });

        return view('livewire.settings.receipts', [
            'status' => $ocr->status(),
            'months' => $months,
            'mistralSource' => $this->keySource('receipts.mistral_key'),
            'azureSource' => $this->keySource('receipts.azure_key'),
            'mappings' => ReceiptLabelMapping::query()->with('ingredient', 'store')
                ->when(trim($this->search) !== '', fn ($q) => $q->where('normalized_label', 'like', '%'.mb_strtoupper(trim($this->search)).'%'))
                ->orderByDesc('updated_at')->limit(50)->get(),
            'mappingCount' => ReceiptLabelMapping::count(),
        ]);
    }
}
