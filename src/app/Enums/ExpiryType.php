<?php

namespace App\Enums;

/** Type de date limite (module 11.1). */
enum ExpiryType: string
{
    case Dlc = 'dlc';    // « À consommer jusqu'au » : impératif
    case Ddm = 'ddm';    // « À consommer de préférence avant » : qualité
    case None = 'none';  // pas de date sur l'emballage (vrac, plat maison)

    public function label(): string
    {
        return match ($this) {
            self::Dlc => 'DLC — à consommer jusqu\'au',
            self::Ddm => 'DDM — de préférence avant',
            self::None => 'Sans date imprimée',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Dlc => 'DLC',
            self::Ddm => 'DDM',
            self::None => 'Estimée',
        };
    }
}
