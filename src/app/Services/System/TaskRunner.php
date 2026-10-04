<?php

namespace App\Services\System;

use App\Mail\Notice;
use App\Models\User;
use App\Services\Backup\BackupManager;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Security\LoginJournal;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Tâches régulières de Bouffe, en un seul passage (lot 25, 27.3).
 *
 * Lancées par l'adresse /taches/{jeton} (service externe, toutes les 5 minutes), par cron.php
 * (tâche planifiée OVH, une fois par heure) ou par `php artisan bouffe:tasks` :
 *
 *  1. rappels, notifications, clôture des repas, dépenses récurrentes, purges (NotificationDispatcher) ;
 *  2. sauvegarde automatique quand la dernière est trop ancienne (27.9 : chaque jour en ligne) ;
 *  3. e-mail hebdomadaire à l'administrateur avec le lien de la dernière sauvegarde (27.9) ;
 *  4. journal des connexions : effacement des lignes trop anciennes (27.5).
 *
 * Deux appels simultanés ne se marchent pas dessus (verrou) ; une étape en échec n'empêche pas
 * les suivantes et reste visible dans Paramètres → Mise en ligne.
 */
class TaskRunner
{
    public const SOURCES = ['externe' => 'service externe', 'cron' => 'tâche planifiée OVH', 'commande' => 'commande', 'planificateur' => 'planificateur Laravel', 'windows' => 'tâche Windows'];

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly BackupManager $backups,
        private readonly LoginJournal $journal,
    ) {}

    /**
     * @return array{ran: bool, reminders: int, sent: int, backup: string|null, email: bool, purged: int, errors: list<string>, seconds: float}
     */
    public function run(string $source = 'commande', ?Carbon $now = null): array
    {
        $empty = ['ran' => false, 'reminders' => 0, 'sent' => 0, 'backup' => null, 'email' => false, 'purged' => 0, 'errors' => [], 'seconds' => 0.0];
        $lock = Cache::lock('bouffe.tasks', 900);

        if (! $lock->get()) {
            return $empty;   // un passage est déjà en cours
        }

        $start = microtime(true);
        $now ??= now();
        $result = ['ran' => true] + $empty;

        try {
            $this->step($result, 'Rappels et notifications', function () use (&$result, $now) {
                $totals = $this->dispatcher->run($now);
                $result['reminders'] = $totals['reminders'];
                $result['sent'] = $totals['sent'];
            });

            $this->step($result, 'Sauvegarde', function () use (&$result) {
                if ($this->backups->needsAutomaticBackup()) {
                    $result['backup'] = $this->backups->create('auto')->filename;
                }
            });

            $this->step($result, 'E-mail de sauvegarde', function () use (&$result, $now) {
                $result['email'] = $this->weeklyBackupEmail($now);
            });

            $this->step($result, 'Journal des connexions', function () use (&$result) {
                $result['purged'] = $this->journal->purge();
            });

            // Lot 40 (40.4) : allergènes des produits scannés avant de les connaître.
            $this->step($result, 'Allergènes des produits', function () {
                app(\App\Services\Stock\ProductLookup::class)->refreshPendingAllergens();
            });

            $result['seconds'] = round(microtime(true) - $start, 2);

            Settings::set('tasks.last_run', [
                'at' => $now->toIso8601String(),
                'source' => array_key_exists($source, self::SOURCES) ? $source : 'commande',
                'seconds' => $result['seconds'],
                'backup' => $result['backup'],
                'errors' => $result['errors'],
            ]);
        } finally {
            $lock->release();
        }

        return $result;
    }

    /** Dernier passage : date, origine, durée, erreurs. @return array{at: Carbon, source: string, seconds: float, errors: list<string>}|null */
    public function lastRun(): ?array
    {
        $last = Settings::get('tasks.last_run');

        if (is_array($last) && isset($last['at'])) {
            return ['at' => Carbon::parse($last['at']), 'source' => self::SOURCES[$last['source'] ?? ''] ?? (string) ($last['source'] ?? ''), 'seconds' => (float) ($last['seconds'] ?? 0), 'errors' => (array) ($last['errors'] ?? [])];
        }

        // Avant le lot 25 : seul le passage des rappels était noté.
        $reminders = Settings::get('notifications.last_run');

        return is_array($reminders) && isset($reminders['at'])
            ? ['at' => Carbon::parse($reminders['at']), 'source' => 'rappels', 'seconds' => 0.0, 'errors' => []]
            : null;
    }

    /**
     * 27.9 — une fois par semaine, le jour choisi : lien vers la dernière sauvegarde pour chaque
     * administrateur (connexion obligatoire pour la télécharger).
     */
    public function weeklyBackupEmail(Carbon $now): bool
    {
        if (! config('bouffe.backups.weekly_email') || $now->dayOfWeek !== (int) config('bouffe.backups.weekly_email_day', 1)) {
            return false;
        }

        $last = Settings::get('backups.last_email');

        if (is_array($last) && isset($last['at']) && Carbon::parse($last['at'])->gt($now->copy()->subDays(6))) {
            return false;
        }

        $backup = $this->backups->latest();
        $admins = User::query()->where('is_admin', true)->get();

        if (! $backup || $admins->isEmpty()) {
            return false;
        }

        $size = number_format($backup->size / 1048576, 1, ',', ' ').' Mo';

        foreach ($admins as $admin) {
            Mail::to($admin)->send(new Notice(
                'sauvegarde de la semaine',
                'Sauvegarde de la semaine',
                [
                    "Bonjour {$admin->name},",
                    "La sauvegarde la plus récente date du {$backup->createdAt->locale('fr')->isoFormat('D MMMM YYYY [à] HH:mm')} ({$size}).",
                    'Téléchargez-la et rangez-la hors de l\'hébergement (PC, clé USB, disque) : si l\'hébergement disparaissait, c\'est elle qui permettrait de tout remettre.',
                ],
                ['Télécharger la sauvegarde', route('settings.backups.download', $backup->filename)],
                'Le lien demande d\'être connecté avec votre compte administrateur. Les sauvegardes restent '.config('bouffe.backups.keep').' jours sur l\'hébergement.',
            ));
        }

        Settings::set('backups.last_email', ['at' => $now->toIso8601String(), 'backup' => $backup->filename]);

        return true;
    }

    /** @param  array<string, mixed>  $result */
    private function step(array &$result, string $label, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            $result['errors'][] = $label.' : '.Str::limit($e->getMessage(), 200);
            Log::error("Tâches planifiées — {$label} : ".$e->getMessage(), ['exception' => $e]);
        }
    }
}
