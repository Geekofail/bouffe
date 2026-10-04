<?php

namespace App\Console\Commands;

use App\Services\System\TaskRunner;
use Illuminate\Console\Command;

/**
 * Tâches régulières (lot 25, 27.3) : rappels, notifications, sauvegarde du jour, e-mail de la semaine.
 *
 *   php artisan bouffe:tasks            à la main, ou depuis une tâche planifiée
 *   php cron.php                        tâche planifiée d'OVH (une fois par heure)
 */
class TasksCommand extends Command
{
    protected $signature = 'bouffe:tasks {--source=commande : Origine notée dans Paramètres → Mise en ligne (cron, windows…)}';

    protected $description = 'Lance les tâches régulières : rappels, notifications, sauvegarde, e-mail de sauvegarde';

    public function handle(TaskRunner $runner): int
    {
        $result = $runner->run((string) $this->option('source'));

        if (! $result['ran']) {
            $this->components->warn('Un passage est déjà en cours : rien à faire.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Tâches faites en %s s · %d rappel(s) · %d notification(s)%s%s',
            number_format($result['seconds'], 1, ',', ''),
            $result['reminders'],
            $result['sent'],
            $result['backup'] ? ' · sauvegarde '.$result['backup'] : '',
            $result['email'] ? ' · e-mail de sauvegarde envoyé' : '',
        ));

        foreach ($result['errors'] as $error) {
            $this->components->error($error);
        }

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
