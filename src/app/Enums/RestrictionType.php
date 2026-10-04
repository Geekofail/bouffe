<?php

namespace App\Enums;

/** Contrainte alimentaire d'un invité. */
enum RestrictionType: string
{
    case Allergy = 'allergy';   // ingrédient — alerte rouge
    case Dislike = 'dislike';   // ingrédient — alerte orange
    case Diet = 'diet';         // catégorie de recette requise (ex. Végétarien) — alerte orange

    public function label(): string
    {
        return match ($this) {
            self::Allergy => 'Allergie / intolérance',
            self::Dislike => 'N\'aime pas',
            self::Diet => 'Régime',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Allergy => 'Allergie',
            self::Dislike => 'N\'aime pas',
            self::Diet => 'Régime',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Allergy => 'red',
            self::Dislike => 'amber',
            self::Diet => 'green',
        };
    }

    public function usesIngredient(): bool
    {
        return $this !== self::Diet;
    }
}
