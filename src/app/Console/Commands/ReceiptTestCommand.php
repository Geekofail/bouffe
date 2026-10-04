<?php

namespace App\Console\Commands;

use App\Models\ReceiptLine;
use App\Services\Receipts\ReadingFailed;
use App\Services\Receipts\ReceiptService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * Essai de lecture d'un ticket réel (§7.4 : jeu de tickets de vos magasins).
 *
 *   php artisan bouffe:ticket C:\Users\Pierre\Downloads\cactus.jpg
 *   php artisan bouffe:ticket haut.jpg bas.jpg --garder     → garde le ticket, à relire dans l'application
 *
 * Chaque essai compte comme une lecture du mois (et coûte le prix d'une lecture).
 */
class ReceiptTestCommand extends Command
{
    protected $signature = 'bouffe:ticket
                            {fichiers* : Photo(s) JPEG/PNG ou un PDF}
                            {--garder : Garder le ticket (sinon supprimé après l\'affichage)}
                            {--json : Afficher la réponse brute (nettoyée) du service}';

    protected $description = 'Lit un ticket de caisse avec le service configuré et affiche le résultat, sans rien valider';

    public function handle(ReceiptService $receipts): int
    {
        $files = [];

        foreach ((array) $this->argument('fichiers') as $path) {
            if (! is_file($path)) {
                $this->components->error("Fichier introuvable : {$path}");

                return self::FAILURE;
            }

            $files[] = new UploadedFile($path, basename($path), mime_content_type($path) ?: null, null, true);
        }

        try {
            $receipt = $receipts->create($files);
            $receipt = $receipts->read($receipt);
        } catch (ReadingFailed|\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());
            isset($receipt) && ! $this->option('garder') && $receipts->discard($receipt);

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Service', (string) $receipt->provider);
        $this->components->twoColumnDetail('Magasin', $receipt->storeLabel().($receipt->store_name ? " (lu : {$receipt->store_name})" : ''));
        $this->components->twoColumnDetail('Date', (string) $receipt->purchased_on?->toDateString());
        $this->components->twoColumnDetail('Total', $receipt->total !== null ? number_format((float) $receipt->total, 2, ',', ' ').' €' : '—');

        $this->table(['Libellé', 'Genre', 'Qté', 'Montant', 'Ingrédient', 'Confiance', ''], $receipt->lines->map(fn (ReceiptLine $l) => [
            $l->label,
            ReceiptLine::KINDS[$l->kind] ?? $l->kind,
            $l->weight !== null ? ((float) $l->weight).' g' : ($l->count !== null ? (float) $l->count : ''),
            number_format((float) $l->amount, 2, ',', ' ').((float) $l->discount ? ' ('.number_format((float) $l->discount, 2, ',', ' ').')' : ''),
            $l->ingredient?->name,
            $l->confidence,
            $l->doubtful ? 'à vérifier' : '',
        ])->all());

        $check = $receipts->consistency($receipt);
        $check['ok']
            ? $this->components->info('Le compte est bon : '.number_format($check['sum'], 2, ',', ' ').' €.')
            : $this->components->warn('Écart : lignes '.number_format($check['sum'], 2, ',', ' ').' € pour un total de '.($check['total'] !== null ? number_format($check['total'], 2, ',', ' ').' €' : 'inconnu').'.');

        if ($this->option('json')) {
            $this->line(json_encode($receipt->raw_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        if ($this->option('garder')) {
            $this->components->info('Ticket gardé : '.route('receipts.show', $receipt));
        } else {
            $receipts->discard($receipt);
        }

        return self::SUCCESS;
    }
}
