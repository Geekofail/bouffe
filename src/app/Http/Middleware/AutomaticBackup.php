<?php

namespace App\Http\Middleware;

use App\Services\Backup\BackupManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

use function Illuminate\Support\defer;

/**
 * Sauvegarde automatique sans tâche planifiée (Wamp n'a pas de cron) : quand l'application est utilisée
 * et que la dernière sauvegarde date de plus de BOUFFE_BACKUP_AUTO_DAYS jours, une sauvegarde est créée
 * après l'envoi de la page. La vérification n'est faite qu'une fois par heure.
 */
class AutomaticBackup
{
    public function __construct(private readonly BackupManager $backups) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->user() && (int) config('bouffe.backups.auto_days') > 0 && Cache::add('bouffe.backup.checked', true, now()->addHour())) {
            defer(function () {
                try {
                    if ($this->backups->needsAutomaticBackup()) {
                        $this->backups->create('auto');
                    }
                } catch (\Throwable $e) {
                    Log::warning('Sauvegarde automatique impossible : '.$e->getMessage());
                }
            });
        }

        return $response;
    }
}
