<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\WebPush;
use Illuminate\Console\Command;

/**
 * Tâche planifiée (19.4) : calcule les rappels et envoie les notifications.
 *
 *   php artisan bouffe:reminders                   à lancer toutes les 5 à 15 minutes
 *   php artisan bouffe:reminders --tache-windows   affiche la commande qui crée la tâche planifiée Windows
 */
class RemindersCommand extends Command
{
    protected $signature = 'bouffe:reminders {--tache-windows : Affiche la commande qui crée la tâche planifiée Windows}';

    protected $description = 'Calcule les rappels de préparation et envoie les notifications (téléphone, e-mail)';

    public function handle(NotificationDispatcher $dispatcher, WebPush $push): int
    {
        if ($this->option('tache-windows')) {
            $this->windowsTask();

            return self::SUCCESS;
        }

        $result = $dispatcher->run();

        $this->components->info(sprintf(
            '%d rappel%s à jour · %d notification%s envoyée%s%s · %d e-mail%s',
            $result['reminders'], $result['reminders'] > 1 ? 's' : '',
            $result['sent'], $result['sent'] > 1 ? 's' : '', $result['sent'] > 1 ? 's' : '',
            $result['quiet'] ? ' (heures calmes : rien sur les téléphones)' : '',
            $result['recap'], $result['recap'] > 1 ? 's' : '',
        ));

        if ($result['closed'] + $result['usage'] > 0) {
            $this->components->info(sprintf('Stock : %d repas marqué(s) mangé(s) automatiquement · %d consommation(s) régulière(s) retirée(s)', $result['closed'], $result['usage']));
        }

        if (! $push->isSupported()) {
            $this->components->warn('Notifications sur le téléphone indisponibles : l\'extension PHP openssl est incomplète.');
        }

        return self::SUCCESS;
    }

    private function windowsTask(): void
    {
        $quote = fn (string $path) => str_contains($path, ' ') ? '\\"'.$path.'\\"' : $path;
        // Lot 25 : bouffe:tasks fait les rappels et, en plus, la sauvegarde automatique.
        $action = $quote(PHP_BINARY).' '.$quote(base_path('artisan')).' bouffe:tasks --source=windows';

        $this->line('Dans un PowerShell <options=bold>ouvert en administrateur</>, collez :');
        $this->newLine();
        // SYSTEM : la tâche tourne sans ouvrir de fenêtre, même quand personne n'est connecté.
        $this->line('schtasks /Create /TN "Bouffe - rappels" /SC MINUTE /MO 10 /RU SYSTEM /TR "'.$action.'" /F');
        $this->newLine();
        $this->line('La tâche tourne toutes les 10 minutes tant que l\'ordinateur est allumé.');
        $this->line('Vérifier : <comment>schtasks /Query /TN "Bouffe - rappels"</comment> · supprimer : <comment>schtasks /Delete /TN "Bouffe - rappels" /F</comment>');
    }
}
