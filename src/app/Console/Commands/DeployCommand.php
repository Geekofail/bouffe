<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupManager;
use App\Services\System\Diagnostic;
use App\Services\System\GitUpdater;
use Illuminate\Console\Command;

/**
 * Mise à jour de Bouffe (lot 16, point 20.6).
 *
 * Une seule commande à retenir après avoir remplacé le dossier « src » :
 *
 *     php artisan bouffe:deploy
 *
 * Elle fait d'abord une sauvegarde (une mise à jour touche à la base), met la structure à jour,
 * vide les caches, puis affiche le diagnostic pour dire si tout va bien.
 *
 * Lot 25 (27.10) : en ligne, le site passe en maintenance pendant la mise à jour (page « Maintenance
 * en cours » pour les visiteurs) et revient tout seul à la fin, même en cas d'erreur. La commande
 * signale aussi quand les bibliothèques PHP sont à réinstaller (composer install).
 *
 * Lot 36 (36.3) : « php artisan bouffe:deploy --git » récupère d'abord la version validée du
 * dépôt (branche main par défaut) au lieu d'une copie par SFTP ; la copie reste possible.
 */
class DeployCommand extends Command
{
    protected $signature = 'bouffe:deploy
                            {--sans-sauvegarde : Ne pas sauvegarder avant la mise à jour}
                            {--sans-maintenance : Laisser le site ouvert pendant la mise à jour}
                            {--git : Récupérer d\'abord la dernière version du dépôt Git (avance rapide)}
                            {--branche=main : Branche du dépôt à récupérer avec --git}';

    protected $description = 'Met Bouffe à jour : sauvegarde, structure de la base, caches, diagnostic';

    private ?string $backupName = null;

    public function handle(BackupManager $backups, Diagnostic $diagnostic, GitUpdater $git): int
    {
        $this->newLine();
        $this->line('  <fg=white;bg=bright-red;options=bold> Bouffe </> mise à jour');
        $this->newLine();

        // Lot 36 (36.3) : vérifier le dépôt avant de toucher à quoi que ce soit.
        $branch = (string) $this->option('branche');
        $pending = [];

        if ($this->option('git')) {
            $checked = $this->checkGit($git, $branch);

            if ($checked === null) {
                return self::FAILURE;
            }

            $pending = $checked;
        }

        if (! $this->option('sans-sauvegarde')) {
            $this->components->task('Sauvegarde avant mise à jour', function () use ($backups) {
                $backup = $backups->create('restore');
                $this->backupName = $backup->filename;

                return true;
            });

            if ($this->backupName) {
                $this->components->twoColumnDetail('  Sauvegarde', $this->backupName);
            }
        }

        $maintenance = ! $this->option('sans-maintenance') && ! app()->isDownForMaintenance();

        if ($maintenance) {
            $this->components->task('Site en maintenance', fn () => $this->callSilently('down', ['--retry' => 30, '--refresh' => 15]) === 0);
        }

        try {
            if ($this->option('git') && $pending !== [] && ! $this->pullFromGit($git, $branch)) {
                return self::FAILURE;
            }

            if ($this->vendorOutdated()) {
                $this->components->warn('composer.lock est plus récent que le dossier vendor : lancez « composer install --no-dev -o » puis relancez bouffe:deploy.');
            }

            $this->components->task('Structure de la base de données', fn () => $this->call('migrate', ['--force' => true, '--no-interaction' => true]) === 0);
            $this->components->task('Caches vidés', fn () => $this->call('optimize:clear') === 0);
            if (! file_exists(public_path('storage'))) {
                $this->components->task('Lien vers les fichiers envoyés', fn () => $this->call('storage:link', ['--no-interaction' => true]) === 0);
            }
        } finally {
            if ($maintenance) {
                $this->components->task('Site rouvert', fn () => $this->callSilently('up') === 0);
            }
        }

        /* ------------------------------------------------------------------ Bilan */

        $checks = $diagnostic->checks();
        $summary = $diagnostic->summary($checks);

        $this->newLine();
        $this->line('  <options=bold>Diagnostic</>');
        $this->newLine();

        foreach ($checks as $check) {
            $colour = match ($check['status']) {
                Diagnostic::ERROR => 'red',
                Diagnostic::WARN => 'yellow',
                default => 'green',
            };

            $this->components->twoColumnDetail(
                "  {$check['label']}",
                "<fg={$colour}>{$check['value']}</>",
            );

            if ($check['help'] && $check['status'] !== Diagnostic::OK) {
                $this->line("    <fg=gray>{$check['help']}</>");
            }
        }

        $this->newLine();

        if ($summary['error'] > 0) {
            $this->components->error("Mise à jour faite, mais {$summary['error']} point(s) demandent votre attention (voir ci-dessus).");

            return self::FAILURE;
        }

        $summary['warn'] > 0
            ? $this->components->warn("Bouffe est à jour. {$summary['warn']} point(s) à surveiller.")
            : $this->components->info('Bouffe est à jour, tout va bien.');

        $this->line('  Détail dans l\'application : Paramètres → Diagnostic.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Dépôt Git présent, sans fichier modifié sur place, version distante récupérée.
     *
     * @return list<string>|null versions à récupérer ; null : on s'arrête
     */
    private function checkGit(GitUpdater $git, string $branch): ?array
    {
        if (! preg_match('/^[A-Za-z0-9._\/-]{1,100}$/', $branch)) {
            $this->components->error('Nom de branche invalide.');

            return null;
        }

        if (! $git->available()) {
            $this->components->error('Git est introuvable, ou Bouffe n\'est pas dans un dépôt Git. Sans --git, la mise à jour par copie reste possible.');

            return null;
        }

        $changes = $git->localChanges();

        if ($changes !== []) {
            $this->components->error('Des fichiers ont été modifiés sur ce serveur : rien n\'est écrasé.');
            $this->components->bulletList(array_slice($changes, 0, 10));
            $this->line('    <fg=gray>Pour les abandonner : git checkout -- <fichier>. Pour les garder : les enregistrer dans le dépôt depuis le PC.</>');

            return null;
        }

        try {
            $this->components->task('Dépôt interrogé (branche '.$branch.')', fn () => $git->fetch($branch) ?? true);
        } catch (\RuntimeException $e) {
            $this->components->error($e->getMessage());

            return null;
        }

        $pending = $git->pending($branch);

        if ($pending === []) {
            $this->components->info('Déjà à la dernière version : '.$git->describe().'.');
        } else {
            $this->components->twoColumnDetail('  Nouvelles versions', (string) count($pending));
            foreach (array_slice($pending, 0, 8) as $line) {
                $this->line('    <fg=gray>'.$line.'</>');
            }
        }

        return $pending;
    }

    /** Avance rapide, puis composer install si composer.lock a changé. */
    private function pullFromGit(GitUpdater $git, string $branch): bool
    {
        $before = $git->head();

        try {
            $this->components->task('Nouvelle version récupérée', fn () => $git->fastForward($branch) ?? true);
        } catch (\RuntimeException $e) {
            $this->components->error($e->getMessage());

            return false;
        }

        $this->components->twoColumnDetail('  Version', $git->describe());

        if (in_array('composer.lock', array_map(fn (string $file) => basename($file), $git->changedFiles($before)), true)) {
            $installed = null;
            $this->components->task('Bibliothèques PHP (composer install)', function () use ($git, &$installed) {
                $installed = $git->composerInstall();

                return $installed !== false;
            });

            if ($installed === null) {
                $this->components->warn('composer est introuvable : lancez « composer install --no-dev -o » puis relancez bouffe:deploy.');
            }
        }

        return true;
    }

    /** Une bibliothèque de composer.lock absente de vendor, ou dans une autre version (git pull sans « composer install »). */
    private function vendorOutdated(): bool
    {
        $lock = json_decode((string) @file_get_contents(base_path('composer.lock')), true);
        $installed = json_decode((string) @file_get_contents(base_path('vendor/composer/installed.json')), true);

        if (! is_array($lock) || ! is_array($installed)) {
            return false;
        }

        $versions = array_column($installed['packages'] ?? $installed, 'version', 'name');

        foreach ($lock['packages'] ?? [] as $package) {
            if (($versions[$package['name']] ?? null) !== $package['version']) {
                return true;
            }
        }

        return false;
    }
}
