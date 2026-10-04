<?php

namespace App\Enums;

/**
 * Types de rappels de préparation anticipée (règle R21).
 */
enum ReminderType: string
{
    case Thaw = 'thaw';         // sortir du congélateur
    case Soak = 'soak';         // mettre à tremper
    case Marinate = 'marinate'; // mettre à mariner
    case Rest = 'rest';         // pâte à préparer la veille, repos long
    case DayBefore = 'before';  // étape explicitement « la veille »
    case Reception = 'reception'; // rétroplanning d'une réception (module 21)

    public function label(): string
    {
        return match ($this) {
            self::Thaw => 'Décongeler',
            self::Soak => 'Faire tremper',
            self::Marinate => 'Mariner',
            self::Rest => 'Préparer à l\'avance',
            self::DayBefore => 'La veille',
            self::Reception => 'Réception',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Thaw => 'pantry',
            self::Soak, self::Marinate => 'scale',
            self::Reception => 'users',
            default => 'clock',
        };
    }
}
