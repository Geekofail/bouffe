<?php

namespace App\Console\Commands;

use App\Services\Backup\Backup;
use App\Services\Backup\BackupManager;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

/**
 * Restauration d'une sauvegarde : remplace TOUTES les données actuelles.
 *
 *   php artisan bouffe:restore                                   → choix dans la liste
 *   php artisan bouffe:restore bouffe-2026-09-20_101500-manual.zip
 */
class RestoreCommand extends Command
{
    protected $signature = 'bouffe:restore
                            {fichier? : Nom de l\'archive dans le dossier des sauvegardes}
                            {--force : Ne pas demander de confirmation}';

    protected $description = 'Restaure une sauvegarde Bouffe (base de données + photos)';

    public function handle(BackupManager $backups): int
    {
        $list = $backups->all();

        if ($list->isEmpty()) {
            $this->components->error("Aucune sauvegarde dans {$backups->directory()}.");

            return self::FAILURE;
        }

        $filename = $this->argument('fichier') ?? select(
            label: 'Quelle sauvegarde restaurer ?',
            options: $list->mapWithKeys(fn (Backup $b) => [
                $b->filename => $b->createdAt->locale('fr')->isoFormat('ddd D MMM YYYY HH:mm').' — '.$b->typeLabel().' — '.$b->summary(),
            ])->all(),
            scroll: 10,
        );

        $backup = $backups->find(basename($filename));

        if (! $backup) {
            $this->components->error("Sauvegarde introuvable : {$filename}");

            return self::FAILURE;
        }

        $this->components->warn('Toutes les données actuelles (recettes, planning, listes, comptes, photos) vont être remplacées.');
        $this->components->twoColumnDetail('Sauvegarde', $backup->filename);
        $this->components->twoColumnDetail('Date', $backup->createdAt->format('d/m/Y H:i'));
        $this->components->twoColumnDetail('Contenu', $backup->summary());

        if (! $this->option('force') && ! confirm('Restaurer cette sauvegarde ?', default: false)) {
            $this->components->info('Restauration annulée.');

            return self::SUCCESS;
        }

        try {
            $result = $backups->restore($backup->filename);
        } catch (\Throwable $e) {
            $this->components->error('Restauration interrompue : '.$e->getMessage());
            $this->line('  L\'état précédent a été sauvegardé juste avant (type « Avant restauration ») : relancer php artisan bouffe:restore pour le remettre.');

            return self::FAILURE;
        }

        $this->components->info("Sauvegarde restaurée ({$result['statements']} instructions SQL).");

        if ($result['safety']) {
            $this->components->twoColumnDetail('État précédent conservé dans', $result['safety']->filename);
        }

        $this->line('  Les sessions ont été réinitialisées : il faudra peut-être se reconnecter.');

        return self::SUCCESS;
    }
}
