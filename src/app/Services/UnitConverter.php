<?php

namespace App\Services;

use App\Enums\UnitType;
use App\Models\Ingredient;
use App\Models\Unit;

/**
 * Conversions entre unités de mesure.
 *
 * Règles :
 *  - masse ↔ masse et volume ↔ volume via `factor_to_base` (g ou ml) ;
 *  - comptage → masse (et inverse) uniquement si l'ingrédient a un poids moyen par pièce
 *    (1 oignon ≈ 150 g) et que son unité par défaut n'est pas une autre unité de comptage
 *    (le poids d'une « tranche » de pain de mie ne vaut pas pour une « pièce ») ;
 *  - toute autre combinaison est incompatible (on ne convertit pas une boîte en grammes).
 *
 * Les calculs ne sont jamais arrondis ici : l'arrondi est fait à l'affichage (QuantityFormatter).
 */
class UnitConverter
{
    public function canConvert(Unit $from, Unit $to, ?Ingredient $ingredient = null): bool
    {
        if ($from->code === $to->code) {
            return true;
        }

        if ($from->type === $to->type) {
            return $from->type->isConvertible() && $from->factor_to_base !== null && $to->factor_to_base !== null;
        }

        return $this->pieceWeightBridge($from, $to, $ingredient) !== null;
    }

    /**
     * @throws IncompatibleUnitsException
     */
    public function convert(float $quantity, Unit $from, Unit $to, ?Ingredient $ingredient = null): float
    {
        if ($from->code === $to->code) {
            return $quantity;
        }

        if (! $this->canConvert($from, $to, $ingredient)) {
            throw new IncompatibleUnitsException($from, $to);
        }

        if ($from->type === $to->type) {
            return $quantity * (float) $from->factor_to_base / (float) $to->factor_to_base;
        }

        $pieceWeight = $this->pieceWeightBridge($from, $to, $ingredient);

        // comptage → masse
        if ($from->type === UnitType::Piece) {
            return $quantity * $pieceWeight / (float) $to->factor_to_base;
        }

        // masse → comptage
        return $quantity * (float) $from->factor_to_base / $pieceWeight;
    }

    /**
     * Quantité exprimée dans l'unité de base de sa famille (g ou ml).
     * Renvoie null si l'unité n'est ni une masse ni un volume convertible.
     */
    public function toBase(float $quantity, Unit $unit): ?float
    {
        if (! $unit->type->isConvertible() || $unit->factor_to_base === null) {
            return null;
        }

        return $quantity * (float) $unit->factor_to_base;
    }

    /** Poids d'une pièce (en g) permettant de passer du comptage à la masse, ou null. */
    private function pieceWeightBridge(Unit $from, Unit $to, ?Ingredient $ingredient): ?float
    {
        if ($ingredient === null || $ingredient->piece_weight_g === null || (float) $ingredient->piece_weight_g <= 0) {
            return null;
        }

        [$piece, $mass] = match (true) {
            $from->type === UnitType::Piece && $to->type === UnitType::Mass => [$from, $to],
            $from->type === UnitType::Mass && $to->type === UnitType::Piece => [$to, $from],
            default => [null, null],
        };

        if ($piece === null || $mass->factor_to_base === null) {
            return null;
        }

        // Si l'unité par défaut de l'ingrédient est une AUTRE unité de comptage (ex. « tranche »
        // pour le pain de mie), le poids moyen ne correspond pas à cette unité : on refuse.
        $defaultUnit = $ingredient->default_unit_id !== null ? $ingredient->defaultUnit : null;

        if ($defaultUnit !== null && $defaultUnit->type === UnitType::Piece && $defaultUnit->code !== $piece->code) {
            return null;
        }

        return (float) $ingredient->piece_weight_g;
    }
}
