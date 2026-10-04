<?php

namespace App\Services\Backup;

use Illuminate\Support\Carbon;

/**
 * Une sauvegarde présente dans le dossier des sauvegardes.
 *
 * Nom de fichier : bouffe-AAAA-MM-JJ_HHMMSS-<type>.zip
 */
final class Backup
{
    public const PATTERN = '/^bouffe-(\d{4}-\d{2}-\d{2}_\d{6})-(manual|auto|restore|merge)\.zip$/';

    public const TYPES = [
        'manual' => 'Manuelle',
        'auto' => 'Automatique',
        'restore' => 'Avant restauration',
        'merge' => 'Avant fusion d\'ingrédients',
    ];

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function __construct(
        public readonly string $filename,
        public readonly string $path,
        public readonly string $type,
        public readonly Carbon $createdAt,
        public readonly int $size,
        public readonly array $manifest = [],
    ) {}

    public static function isValidFilename(string $filename): bool
    {
        return (bool) preg_match(self::PATTERN, $filename);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function humanSize(): string
    {
        return match (true) {
            $this->size >= 1048576 => number_format($this->size / 1048576, 1, ',', ' ').' Mo',
            $this->size >= 1024 => number_format($this->size / 1024, 0, ',', ' ').' Ko',
            default => $this->size.' o',
        };
    }

    /** « 12 recettes · 34 repas · 3 photos » */
    public function summary(): string
    {
        $rows = $this->manifest['rows'] ?? [];
        $parts = [];

        foreach (['recipes' => ['recette', 'recettes'], 'planned_meals' => ['repas', 'repas'], 'shopping_lists' => ['liste', 'listes']] as $table => [$one, $many]) {
            if (isset($rows[$table])) {
                $parts[] = $rows[$table].' '.($rows[$table] > 1 ? $many : $one);
            }
        }

        if (isset($this->manifest['photos'])) {
            $count = (int) $this->manifest['photos'];
            $parts[] = $count.' photo'.($count > 1 ? 's' : '');
        }

        return implode(' · ', $parts);
    }
}
