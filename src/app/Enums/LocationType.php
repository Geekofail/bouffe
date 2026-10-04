<?php

namespace App\Enums;

enum LocationType: string
{
    case Fresh = 'fresh';
    case Freezer = 'freezer';
    case Ambient = 'ambient';

    public function label(): string
    {
        return match ($this) {
            self::Fresh => 'Frais (réfrigérateur)',
            self::Freezer => 'Congélation',
            self::Ambient => 'Température ambiante',
        };
    }
}
