<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Tâches régulières (lot 20, 19.4 ; lot 25, 27.3) : rappels, notifications, sauvegarde du jour.
 *   - serveur avec cron à la minute (VPS) : * * * * * cd /chemin/vers/src && php artisan schedule:run
 *   - hébergement mutualisé OVH : adresse /taches/{jeton} + cron.php (voir docs/10-mise-en-ligne-ovh.md) ;
 *   - Windows : tâche planifiée créée par « php artisan bouffe:reminders --tache-windows ».
 */
Illuminate\Support\Facades\Schedule::command('bouffe:tasks --source=planificateur')->everyFiveMinutes()->withoutOverlapping();
