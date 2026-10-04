<?php

namespace App\Services\Backup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Création, liste, suppression et restauration des sauvegardes.
 *
 * Contenu d'une archive :
 *   manifest.json   date, version, pilote de base, dernière migration, nombre de lignes par table
 *   database.sql    structure + données (SqlDumper)
 *   photos/…        photos des recettes et des réceptions (dossier recipes), tickets de caisse
 *                   (dossier receipts, depuis le lot 25) — disque « local »
 */
class BackupManager
{
    public const PHOTO_DIRECTORY = 'recipes';

    /** Dossiers du disque privé sauvegardés (lot 25 : les tickets de caisse en plus des photos). */
    public const FILE_DIRECTORIES = ['recipes', 'receipts'];

    public function __construct(
        private readonly SqlDumper $dumper,
        private readonly SqlStatementSplitter $splitter,
    ) {}

    public function directory(): string
    {
        return rtrim((string) config('bouffe.backups.path'), '/\\');
    }

    /** @param  'manual'|'auto'|'restore'|'merge'  $type */
    public function create(string $type = 'manual'): Backup
    {
        $this->ensureZipAvailable();
        File::ensureDirectoryExists($this->directory());

        $now = Carbon::now();
        $filename = 'bouffe-'.$now->format('Y-m-d_His')."-{$type}.zip";
        $path = $this->directory().DIRECTORY_SEPARATOR.$filename;

        if (File::exists($path)) {
            throw new RuntimeException('Une sauvegarde vient déjà d\'être créée à cette seconde. Réessayez.');
        }

        $sqlFile = tempnam(sys_get_temp_dir(), 'bouffe-sql-');
        $zip = new ZipArchive;

        try {
            $stream = fopen($sqlFile, 'wb');
            $rows = $this->dumper->dump($stream, config('bouffe.backups.structure_only', []));
            fclose($stream);

            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Impossible de créer le fichier {$path}.");
            }

            $zip->addFile($sqlFile, 'database.sql');

            $photos = 0;
            $disk = Storage::disk('local');

            foreach (self::FILE_DIRECTORIES as $directory) {
                foreach ($disk->allFiles($directory) as $photo) {
                    $zip->addFile($disk->path($photo), 'photos/'.$photo);
                    $photos++;
                }
            }

            $zip->addFromString('manifest.json', json_encode([
                'application' => 'bouffe',
                'version' => config('bouffe.version'),
                'created_at' => $now->toIso8601String(),
                'type' => $type,
                'driver' => DB::connection()->getDriverName(),
                'database' => DB::connection()->getDatabaseName(),
                // Base encore vide (première restauration chez l'hébergeur) : pas de table des migrations.
                'last_migration' => \Illuminate\Support\Facades\Schema::hasTable('migrations') ? DB::table('migrations')->orderByDesc('id')->value('migration') : null,
                'rows' => $rows,
                'photos' => $photos,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            if (! $zip->close()) {
                throw new RuntimeException("Écriture de l'archive {$filename} impossible.");
            }
        } catch (\Throwable $e) {
            File::delete($path);

            throw $e;
        } finally {
            File::delete($sqlFile);
        }

        if ($type === 'auto') {
            $this->prune();
        }

        $this->mirror($path, $filename);

        return $this->find($filename);
    }

    /* ============================================================ Copie hors du PC (20.4) */

    /** Dossier de la copie externe (clé USB, disque, OneDrive…), ou null s'il n'y en a pas. */
    public function mirrorDirectory(): ?string
    {
        $path = trim((string) config('bouffe.backups.mirror'));

        return $path === '' ? null : rtrim($path, '/\\');
    }

    /**
     * État de la copie externe, pour l'écran des sauvegardes et le diagnostic.
     *
     * @return array{configured: bool, path: string|null, available: bool, copies: int, last: string|null, error: string|null}
     */
    public function mirrorStatus(): array
    {
        $directory = $this->mirrorDirectory();

        if (! $directory) {
            return ['configured' => false, 'path' => null, 'available' => false, 'copies' => 0, 'last' => null, 'error' => null];
        }

        if (! File::isDirectory($directory)) {
            return [
                'configured' => true, 'path' => $directory, 'available' => false, 'copies' => 0, 'last' => null,
                'error' => 'Dossier introuvable : le disque est peut-être débranché.',
            ];
        }

        $copies = collect(File::files($directory))
            ->filter(fn (\SplFileInfo $file) => Backup::isValidFilename($file->getFilename()))
            ->sortByDesc(fn (\SplFileInfo $file) => $file->getFilename())
            ->values();

        return [
            'configured' => true,
            'path' => $directory,
            'available' => File::isWritable($directory),
            'copies' => $copies->count(),
            'last' => $copies->first()?->getFilename(),
            'error' => File::isWritable($directory) ? null : 'Dossier en lecture seule.',
        ];
    }

    /**
     * Recopie une sauvegarde dans le dossier externe.
     *
     * Un disque débranché n'est pas une erreur : la sauvegarde du PC est faite, c'est l'essentiel.
     * L'écran des sauvegardes signale que la copie n'a pas pu être faite.
     */
    public function mirror(string $path, string $filename): bool
    {
        $directory = $this->mirrorDirectory();

        if (! $directory || ! File::isDirectory($directory) || ! File::isWritable($directory)) {
            return false;
        }

        try {
            File::copy($path, $directory.DIRECTORY_SEPARATOR.$filename);
            $this->pruneMirror($directory);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Ne garde que les N copies les plus récentes dans le dossier externe (place limitée sur une clé USB). */
    private function pruneMirror(string $directory): void
    {
        $keep = (int) config('bouffe.backups.mirror_keep', 5);

        if ($keep <= 0) {
            return;
        }

        collect(File::files($directory))
            ->filter(fn (\SplFileInfo $file) => Backup::isValidFilename($file->getFilename()))
            ->sortByDesc(fn (\SplFileInfo $file) => $file->getFilename())
            ->slice($keep)
            ->each(fn (\SplFileInfo $file) => File::delete($file->getPathname()));
    }

    /** @return Collection<int, Backup> du plus récent au plus ancien */
    public function all(): Collection
    {
        if (! File::isDirectory($this->directory())) {
            return collect();
        }

        return collect(File::files($this->directory()))
            ->filter(fn (\SplFileInfo $file) => Backup::isValidFilename($file->getFilename()))
            ->map(fn (\SplFileInfo $file) => $this->find($file->getFilename()))
            ->filter()
            ->sortByDesc(fn (Backup $backup) => $backup->filename)
            ->values();
    }

    public function find(string $filename): ?Backup
    {
        if (! preg_match(Backup::PATTERN, $filename, $matches)) {
            return null;
        }

        $path = $this->directory().DIRECTORY_SEPARATOR.$filename;

        if (! File::isFile($path)) {
            return null;
        }

        return new Backup(
            filename: $filename,
            path: $path,
            type: $matches[2],
            createdAt: Carbon::createFromFormat('Y-m-d_His', $matches[1]),
            size: (int) File::size($path),
            manifest: $this->readManifest($path),
        );
    }

    public function latest(): ?Backup
    {
        return $this->all()->first(fn (Backup $backup) => ! in_array($backup->type, ['restore', 'merge'], true));
    }

    public function delete(string $filename): void
    {
        $backup = $this->find($filename) ?? throw new RuntimeException('Sauvegarde introuvable.');

        File::delete($backup->path);
    }

    /** Ne garde que les N sauvegardes automatiques les plus récentes. */
    public function prune(): int
    {
        $keep = max(1, (int) config('bouffe.backups.keep', 10));
        $old = $this->all()->where('type', 'auto')->slice($keep);

        $old->each(fn (Backup $backup) => File::delete($backup->path));

        return $old->count();
    }

    public function needsAutomaticBackup(): bool
    {
        $days = (int) config('bouffe.backups.auto_days', 7);

        if ($days <= 0 || ! class_exists(ZipArchive::class)) {
            return false;
        }

        $latest = $this->latest();

        return $latest === null || $latest->createdAt->lt(Carbon::now()->subDays($days));
    }

    /**
     * Remplace toute la base et les photos par le contenu de la sauvegarde.
     * Une sauvegarde « avant restauration » de l'état actuel est créée d'abord.
     *
     * @return array{safety: Backup|null, statements: int}
     */
    public function restore(string $filename, bool $safetyBackup = true): array
    {
        $this->ensureZipAvailable();
        $backup = $this->find($filename) ?? throw new RuntimeException('Sauvegarde introuvable.');

        $driver = DB::connection()->getDriverName();
        $backupDriver = $backup->manifest['driver'] ?? null;
        $compatible = fn (?string $d) => in_array($d, ['mysql', 'mariadb'], true) ? 'mysql' : $d;

        if ($compatible($backupDriver) !== $compatible($driver)) {
            throw new RuntimeException("Cette sauvegarde vient d'une base « {$backupDriver} », la base actuelle est « {$driver} ».");
        }

        $zip = new ZipArchive;

        if ($zip->open($backup->path) !== true || ($sql = $zip->getFromName('database.sql')) === false) {
            throw new RuntimeException('Archive illisible ou incomplète (database.sql manquant).');
        }

        $safety = $safetyBackup ? $this->create('restore') : null;

        $connection = DB::connection();
        $statements = 0;
        // MariaDB ↔ MySQL (lot 25, 27.8) : la structure est adaptée au serveur qui reçoit.
        $dialect = $driver === 'sqlite' ? null : new SqlDialect($connection);

        $this->dropAllTables();

        try {
            foreach ($this->splitter->split($sql, $driver !== 'sqlite') as $statement) {
                $connection->unprepared($dialect ? $dialect->adapt($statement) : $statement);
                $statements++;
            }
        } finally {
            $connection->unprepared($driver === 'sqlite' ? 'PRAGMA foreign_keys=ON' : 'SET FOREIGN_KEY_CHECKS=1');
        }

        $this->restorePhotos($zip);
        $zip->close();

        // Sauvegarde plus ancienne que le code : on ajoute les tables apparues depuis.
        Artisan::call('migrate', ['--force' => true]);

        return ['safety' => $safety, 'statements' => $statements];
    }

    public function zipAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    private function dropAllTables(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        $connection->unprepared($driver === 'sqlite' ? 'PRAGMA foreign_keys=OFF' : 'SET FOREIGN_KEY_CHECKS=0');

        foreach ($this->dumper->tables($connection) as $table) {
            $quoted = $driver === 'sqlite' ? '"'.$table.'"' : '`'.$table.'`';
            $connection->unprepared("DROP TABLE IF EXISTS {$quoted}");
        }
    }

    private function restorePhotos(ZipArchive $zip): void
    {
        $disk = Storage::disk('local');

        // Une sauvegarde d'avant le lot 25 ne contient pas les tickets : on garde ceux qui sont là.
        foreach (self::FILE_DIRECTORIES as $directory) {
            if ($directory === self::PHOTO_DIRECTORY || $this->zipHasPrefix($zip, 'photos/'.$directory.'/')) {
                $disk->deleteDirectory($directory);
            }
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (! str_starts_with($name, 'photos/') || str_ends_with($name, '/') || str_contains($name, '..')) {
                continue;
            }

            $disk->put(substr($name, strlen('photos/')), $zip->getFromIndex($i));
        }
    }

    private function zipHasPrefix(ZipArchive $zip, string $prefix): bool
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with((string) $zip->getNameIndex($i), $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function readManifest(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            return [];
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return [];
        }

        $json = $zip->getFromName('manifest.json');
        $zip->close();

        return is_string($json) ? (json_decode($json, true) ?: []) : [];
    }

    private function ensureZipAvailable(): void
    {
        if (! $this->zipAvailable()) {
            throw new RuntimeException('L\'extension PHP « zip » est désactivée. Wamp → PHP → Extensions PHP → cocher zip (et dans le php.ini de la ligne de commande).');
        }
    }
}
