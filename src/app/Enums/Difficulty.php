<?php

namespace App\Enums;

enum Difficulty: string
{
    case Easy = 'easy';
    case Medium = 'medium';
    case Hard = 'hard';

    public function label(): string
    {
        return match ($this) {
            self::Easy => 'Facile',
            self::Medium => 'Moyen',
            self::Hard => 'Difficile',
        };
    }

    /** Nombre de « toques » affichées (1 à 3). */
    public function level(): int
    {
        return match ($this) {
            self::Easy => 1,
            self::Medium => 2,
            self::Hard => 3,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $d) => [$d->value => $d->label()])->all();
    }
}
