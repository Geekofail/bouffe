<?php

namespace App\Services;

/**
 * Règle R1 : mise à l'échelle d'une quantité selon le nombre de portions.
 *
 *   quantité nécessaire = quantité recette × (portions voulues / portions de base)
 *
 * Aucun arrondi : l'affichage est confié à QuantityFormatter.
 */
class QuantityScaler
{
    public function scale(float|string|null $quantity, int|float $baseServings, int|float $targetServings): ?float
    {
        if ($quantity === null || $quantity === '') {
            return null;
        }

        if ($baseServings <= 0 || $targetServings <= 0) {
            throw new \InvalidArgumentException('Le nombre de portions doit être positif.');
        }

        return (float) $quantity * $targetServings / $baseServings;
    }
}
