<?php

namespace App\Enums;

enum ListStatus: string
{
    case Active = 'active';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'En cours',
            self::Done => 'Terminée',
        };
    }
}
