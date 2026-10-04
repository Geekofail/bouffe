<?php

namespace App\Services\System;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Mise en ligne depuis Git (lot 36, 36.3) : récupérer la version validée du dépôt, sans copie par SFTP.
 *
 * Utilisé par « php artisan bouffe:deploy --git ». Seule l'avance rapide est acceptée
 * (git merge --ff-only) : si des fichiers ont été modifiés sur le serveur, rien n'est écrasé.
 */
class GitUpdater
{
    public function __construct(private readonly ?string $directory = null) {}

    private function run(array $command, int $timeout = 120): \Illuminate\Contracts\Process\ProcessResult
    {
        return Process::path($this->directory ?? base_path())->timeout($timeout)->run($command);
    }

    /** Git est installé et Bouffe est dans un dépôt. */
    public function available(): bool
    {
        $inside = $this->run(['git', 'rev-parse', '--is-inside-work-tree']);

        return $inside->successful() && trim($inside->output()) === 'true';
    }

    /** Fichiers suivis modifiés sur place (git status). @return list<string> */
    public function localChanges(): array
    {
        $status = $this->run(['git', 'status', '--porcelain', '--untracked-files=no']);

        return array_values(array_filter(array_map(fn (string $line) => trim(substr($line, 3)), explode("\n", rtrim($status->output())))));
    }

    public function fetch(string $branch): void
    {
        $result = $this->run(['git', 'fetch', '--quiet', 'origin', $branch], 300);

        if ($result->failed()) {
            throw new RuntimeException('Impossible de joindre le dépôt : '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    /** Versions à récupérer, de la plus récente à la plus ancienne. @return list<string> */
    public function pending(string $branch): array
    {
        $log = $this->run(['git', 'log', '--oneline', '--no-decorate', 'HEAD..origin/'.$branch]);

        return array_values(array_filter(explode("\n", trim($log->output()))));
    }

    public function head(): string
    {
        return trim($this->run(['git', 'rev-parse', 'HEAD'])->output());
    }

    /** « a1b2c3d Lot 36 — socle technique » */
    public function describe(string $ref = 'HEAD'): string
    {
        return trim($this->run(['git', 'log', '-1', '--format=%h %s', $ref])->output());
    }

    /** Avance jusqu'à origin/<branche>. Refuse si les historiques ont divergé. */
    public function fastForward(string $branch): void
    {
        $result = $this->run(['git', 'merge', '--ff-only', '--quiet', 'origin/'.$branch], 300);

        if ($result->failed()) {
            throw new RuntimeException('La mise à jour par avance rapide a échoué : '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    /** Fichiers changés entre deux versions. @return list<string> */
    public function changedFiles(string $from, string $to = 'HEAD'): array
    {
        $diff = $this->run(['git', 'diff', '--name-only', $from, $to]);

        return array_values(array_filter(explode("\n", trim($diff->output()))));
    }

    /**
     * « composer install » si composer est disponible (dans le PATH, ou composer.phar à côté de Bouffe).
     *
     * @return bool|null null : composer introuvable
     */
    public function composerInstall(): ?bool
    {
        $base = $this->directory ?? base_path();
        $candidates = [['composer'], [PHP_BINARY, $base.'/composer.phar'], [PHP_BINARY, dirname($base).'/composer.phar']];

        foreach ($candidates as $composer) {
            if (count($composer) === 2 && ! is_file($composer[1])) {
                continue;
            }

            if ($this->run([...$composer, '--version'])->failed()) {
                continue;
            }

            return $this->run([...$composer, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction'], 900)->successful();
        }

        return null;
    }
}
