<?php

namespace App\Enums;

/** Mouvement de stock (jamais modifié : une correction crée un nouveau mouvement). */
enum MovementType: string
{
    case In = 'in';
    case Consume = 'consume';
    case Waste = 'waste';
    case Adjust = 'adjust';
    case Move = 'move';
    case Freeze = 'freeze';
    case Thaw = 'thaw';
    case Open = 'open';
    case Undo = 'undo';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Entrée',
            self::Consume => 'Consommé',
            self::Waste => 'Jeté',
            self::Adjust => 'Quantité corrigée',
            self::Move => 'Déplacé',
            self::Freeze => 'Congelé',
            self::Thaw => 'Décongelé',
            self::Open => 'Ouvert',
            self::Undo => 'Annulation',
        };
    }
}
