<?php

namespace App\Enums;

/**
 * Provenance d'un article de liste de courses.
 */
enum ItemOrigin: string
{
    /** Calculé depuis les recettes du planning */
    case Generated = 'generated';

    /** Produit de base nécessaire : « à vérifier dans le placard » */
    case Staple = 'staple';

    /** Ajouté à la main */
    case Manual = 'manual';

    /** Article récurrent ajouté à chaque liste (Paramètres) */
    case Recurring = 'recurring';

    /** Ingrédient sous son stock minimum (lot 9) */
    case Restock = 'restock';

    public function isFromPlanning(): bool
    {
        return $this === self::Generated || $this === self::Staple;
    }
}
