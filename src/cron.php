<?php

/*
|--------------------------------------------------------------------------
| Tâche planifiée d'OVH (lot 25, 27.3)
|--------------------------------------------------------------------------
|
| L'hébergement mutualisé OVH lance un script PHP au plus une fois par heure. Dans l'espace client :
| Hébergements → votre hébergement → Tâches planifiées - Cron → Ajouter :
|
|     Commande à exécuter : www/bouffe/src/cron.php        (chemin depuis la racine FTP)
|     Langage             : PHP 8.4
|     Fréquence           : toutes les heures
|
| C'est le secours : l'adresse /taches/{jeton}, appelée toutes les 5 minutes par un service externe,
| fait le même travail plus souvent (voir docs/10-mise-en-ligne-ovh.md).
|
| Ce fichier est hors du dossier public : on ne peut pas l'ouvrir depuis un navigateur.
*/

chdir(__DIR__);
define('LARAVEL_START', microtime(true));

require __DIR__.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__.'/bootstrap/app.php';

exit($app->handleCommand(new Symfony\Component\Console\Input\ArrayInput([
    'command' => 'bouffe:tasks',
    '--source' => 'cron',
])));
