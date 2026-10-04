<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupManager;
use Illuminate\Console\Command;

/**
 * Sauvegarde de la base et des photos.
 *
 *   php artisan bouffe:backup            → sauvegarde manuelle (jamais supprimée automatiquement)
 *   php artisan bouffe:backup --auto     → sauvegarde « automatique » (rotation, ex. Planificateur de tâches Windows)
 */
class BackupCommand extends Command
{
    protected $signature = 'bouffe:backup
                            {--auto : Marquer la sauvegarde comme automatique (seules les plus récentes sont gardées)}';

    protected $description = 'Sauvegarde la base de données et les photos des recettes dans une archive zip';

    public function handle(BackupManager $backups): int
    {
        try {
            $backup = $backups->create($this->option('auto') ? 'auto' : 'manual');
        } catch (\Throwable $e) {
            $this->components->error('Sauvegarde impossible : '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Sauvegarde créée : {$backup->filename} ({$backup->humanSize()})");
        $this->components->twoColumnDetail('Dossier', $backups->directory());
        $this->components->twoColumnDetail('Contenu', $backup->summary());

        return self::SUCCESS;
    }
}
