<?php

namespace App\Console\Commands;

use Database\Seeders\BrowserDemoSeeder;
use Illuminate\Console\Command;

/**
 * Base d'essai des tests dans le navigateur (lot 28, 28.7) : `npm run test:browser` l'appelle avant
 * de lancer les tests.
 *
 * Elle recrée **entièrement** la base : c'est pourquoi la commande refuse de travailler ailleurs que
 * sur le fichier SQLite `database/browser.sqlite`. Si la configuration est en cache
 * (`php artisan config:cache`), les variables données par le test seraient ignorées et l'on
 * risquerait de toucher la vraie base : la commande s'arrête aussi dans ce cas.
 */
class BrowserDbCommand extends Command
{
    protected $signature = 'bouffe:browser-db';

    protected $description = 'Recrée la base SQLite des tests dans le navigateur (jamais la vraie base)';

    public function handle(): int
    {
        $expected = database_path('browser.sqlite');
        $connection = (string) config('database.default');
        $path = (string) config("database.connections.{$connection}.database");

        if (app()->configurationIsCached()) {
            $this->components->error('La configuration est en cache : lancez « php artisan config:clear » avant les tests dans le navigateur.');

            return self::FAILURE;
        }

        if ($connection !== 'sqlite' || realpath(dirname($path)) === false || realpath(dirname($path)).DIRECTORY_SEPARATOR.basename($path) !== realpath(dirname($expected)).DIRECTORY_SEPARATOR.'browser.sqlite') {
            $this->components->error("Refusé : cette commande ne travaille que sur {$expected} (base actuelle : {$connection} {$path}).");

            return self::FAILURE;
        }

        if (! file_exists($expected)) {
            touch($expected);
        }

        $this->call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
        $this->call('db:seed', ['--class' => BrowserDemoSeeder::class, '--force' => true, '--no-interaction' => true]);

        $this->components->info('Base d\'essai prête : '.BrowserDemoSeeder::EMAIL);

        return self::SUCCESS;
    }
}
