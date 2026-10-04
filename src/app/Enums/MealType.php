<?php

namespace App\Enums;

enum MealType: string
{
    case Recipe = 'recipe';
    case Leftover = 'leftover';
    case Free = 'free';

    public function label(): string
    {
        return match ($this) {
            self::Recipe => 'Recette',
            self::Leftover => 'Restes',
            self::Free => 'Repas libre',
        };
    }
}
