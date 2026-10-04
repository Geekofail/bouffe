<?php

namespace App\Services\Receipts;

use App\Models\OcrReading;
use App\Models\Receipt;
use App\Services\Receipts\Readers\AzureReader;
use App\Services\Receipts\Readers\MistralReader;
use App\Services\Receipts\Readers\ReceiptReader;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Accès au service de lecture choisi (Q32), plafond mensuel et suivi des coûts (24.7).
 *
 * Principe 5 du document 07 : un service externe n'est jamais indispensable. Sans clé, sans
 * réseau, au-delà du plafond : ReadingFailed, et l'écran propose la saisie à la main.
 */
class OcrService
{
    public const PROVIDERS = ['mistral' => MistralReader::class, 'azure' => AzureReader::class];

    public function __construct(private readonly ReceiptFiles $files) {}

    public function provider(): string
    {
        return (string) Settings::get('receipts.provider', 'mistral');
    }

    public function reader(?string $provider = null): ?ReceiptReader
    {
        $class = self::PROVIDERS[$provider ?? $this->provider()] ?? null;

        return $class ? app($class) : null;
    }

    /**
     * La lecture automatique est-elle possible maintenant, et sinon pourquoi.
     *
     * @return array{available: bool, reason: string|null, label: string|null}
     */
    public function status(): array
    {
        $reader = $this->reader();

        if (! $reader) {
            return ['available' => false, 'reason' => 'Lecture automatique désactivée : saisie à la main.', 'label' => null];
        }

        if (! $reader->configured()) {
            return ['available' => false, 'reason' => 'Aucune clé pour '.$reader->label().' : renseignez-la dans Paramètres → Tickets de caisse.', 'label' => $reader->label()];
        }

        $usage = $this->usage();

        if ($usage['remaining'] !== null && $usage['remaining'] <= 0) {
            return ['available' => false, 'reason' => "Plafond de {$usage['cap']} lectures atteint ce mois-ci : saisie à la main jusqu'au mois prochain.", 'label' => $reader->label()];
        }

        return ['available' => true, 'reason' => null, 'label' => $reader->label()];
    }

    /**
     * Lectures du mois (tickets et recettes).
     *
     * @return array{count: int, pages: int, cost: float, failed: int, cap: int, remaining: int|null}
     */
    public function usage(?Carbon $month = null): array
    {
        $month ??= Carbon::today();
        // Le service est payé pour toute l'installation : le plafond compte les lectures de tous les foyers.
        $rows = OcrReading::withoutGlobalScope(\App\Models\Scopes\HouseholdScope::class)
            ->whereBetween('created_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->get();

        $ok = $rows->where('succeeded', true);
        $cap = Settings::int('receipts.monthly_cap', 50);

        return [
            'count' => $ok->count(),
            'pages' => (int) $ok->sum('pages'),
            'cost' => round((float) $ok->sum('cost_estimate'), 4),
            'failed' => $rows->where('succeeded', false)->count(),
            'cap' => $cap,
            'remaining' => max(0, $cap - $ok->count()),
        ];
    }

    /** Lit le ticket (photos ou PDF déjà enregistrés). */
    public function readReceipt(Receipt $receipt): ReceiptReading
    {
        $this->ensureAvailable();
        $reader = $this->reader();
        $document = $this->files->document($receipt);

        if ($reader instanceof MistralReader && $document['pages'] > MistralReader::MAX_PAGES) {
            $this->cleanup($document);

            throw new ReadingFailed('Mistral lit 8 pages au plus par ticket : gardez les pages utiles.');
        }

        try {
            $reading = $reader->read($document['path'], $document['mime']);
        } catch (ReadingFailed $e) {
            $this->record('receipt', $reader->provider(), 0, false, $receipt);

            throw $e;
        } finally {
            $this->cleanup($document);
        }

        $this->record('receipt', $reader->provider(), $reading->pages, true, $receipt);

        return $reading;
    }

    /** Texte d'une photo de recette (C3) : page de livre, fiche manuscrite. */
    public function readRecipeText(UploadedFile $file): string
    {
        return $this->readDocumentText($file, 'recipe');
    }

    /**
     * Texte d'une photo ou d'un PDF, sans autre analyse : une recette, le menu de la cantine (lot 39).
     * Le coût est celui d'une lecture de texte.
     */
    public function readDocumentText(UploadedFile $file, string $purpose = 'recipe'): string
    {
        $this->ensureAvailable();
        $reader = $this->reader();
        $pdf = $file->getMimeType() === 'application/pdf' || strtolower($file->getClientOriginalExtension()) === 'pdf';
        $path = $pdf ? $file->getRealPath() : $this->files->temporaryJpeg($file->getRealPath());

        try {
            $result = $reader->readText($path, $pdf ? 'application/pdf' : 'image/jpeg');
        } catch (ReadingFailed $e) {
            $this->record($purpose, $reader->provider(), 0, false);

            throw $e;
        } finally {
            if (! $pdf) {
                @unlink($path);
            }
        }

        $this->record($purpose, $reader->provider(), $result['pages'], true);

        return CardScrubber::text($result['text']) ?? '';
    }

    private function ensureAvailable(): void
    {
        $status = $this->status();

        if (! $status['available']) {
            throw new ReadingFailed((string) $status['reason']);
        }
    }

    private function record(string $purpose, string $provider, int $pages, bool $succeeded, ?Receipt $receipt = null): void
    {
        $rates = (array) config('bouffe.receipts.cost_per_page', []);
        $rate = (float) ($rates[$purpose !== 'receipt' && $provider === 'mistral' ? 'mistral_text' : $provider] ?? 0);

        OcrReading::create([
            'purpose' => $purpose,
            'provider' => $provider,
            'pages' => $pages,
            'cost_estimate' => round($pages * $rate, 4),
            'succeeded' => $succeeded,
            'receipt_id' => $receipt?->id,
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);
    }

    /** @param  array{path: string, temporary: bool}  $document */
    private function cleanup(array $document): void
    {
        if ($document['temporary']) {
            @unlink($document['path']);
        }
    }
}
