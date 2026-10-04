<?php

namespace App\Enums;

/** Façon de suivre un ingrédient dans le stock. */
enum StockMode: string
{
    case None = 'none';          // pas suivi (eau, lessive…)
    case Presence = 'presence';  // on en a / on n'en a plus (sel, farine, épices)
    case Quantity = 'quantity';  // quantité et date (frais)

    public function label(): string
    {
        return match ($this) {
            self::None => 'Non suivi',
            self::Presence => 'Présence (on en a / plus rien)',
            self::Quantity => 'Quantité et date',
        };
    }
}
