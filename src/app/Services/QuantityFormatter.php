<?php

namespace App\Services;

use App\Enums\UnitType;
use App\Models\Unit;

/**
 * Mise en forme des quantités pour l'affichage (règle R3 de l'analyse).
 *
 * Deux contextes :
 *  - RECIPE   : lecture d'une recette → arrondi au plus proche ;
 *  - SHOPPING : liste de courses      → arrondi au supérieur (on n'achète pas ½ oignon).
 *
 * Unités métriques (g, kg, ml, cl, l) : la quantité est ramenée en g/ml puis réexprimée
 * dans l'unité la plus lisible (1 250 g → « 1,25 kg »).
 * Autres unités : fractions lisibles (½, ¼, ¾) pour les cuillères, pièces, etc.
 *
 * Les calculs internes ne passent jamais par ce service : seul l'affichage est arrondi.
 */
class QuantityFormatter
{
    public const RECIPE = 'recipe';

    public const SHOPPING = 'shopping';

    private const FRACTIONS = [
        '0.25' => '¼',
        '0.5' => '½',
        '0.75' => '¾',
    ];

    /**
     * « 1,25 kg », « 3 pièces », « ½ c. à soupe ». Chaîne vide si la quantité est nulle.
     */
    public function format(?float $quantity, ?Unit $unit, string $context = self::RECIPE): string
    {
        if ($quantity === null) {
            return '';
        }

        if ($unit !== null && $this->isMetric($unit)) {
            return $this->formatMetric($quantity * (float) $unit->factor_to_base, $unit->type, $context);
        }

        $rounded = $this->rounded($quantity, $unit, $context);

        return $unit === null
            ? $this->formatFraction($rounded)
            : $this->formatFraction($rounded).' '.$unit->labelFor($rounded);
    }

    /**
     * Valeur arrondie telle qu'elle sera affichée, pour les unités non métriques
     * (utile pour accorder un nom : « 1 ½ oignon » / « 2 oignons »).
     */
    public function rounded(float $quantity, ?Unit $unit, string $context = self::RECIPE): float
    {
        // Cuillères : au quart. Sans unité, pièces, boîtes, sachets… : à la demie dans une
        // recette, à l'entier supérieur dans la liste de courses.
        $step = match (true) {
            $unit?->type === UnitType::Volume => 0.25,
            $context === self::SHOPPING => 1.0,
            default => 0.5,
        };

        return $this->roundToStep($quantity, $step, $context);
    }

    private function isMetric(Unit $unit): bool
    {
        return $unit->is_metric && $unit->type->isConvertible() && $unit->factor_to_base !== null;
    }

    /** Nombre au format français, sans zéros inutiles : 1.50 → « 1,5 ». */
    public function number(float $value, int $maxDecimals = 2): string
    {
        $formatted = number_format($value, $maxDecimals, ',', "\u{202F}");

        if ($maxDecimals > 0) {
            $formatted = rtrim(rtrim($formatted, '0'), ',');
        }

        return $formatted;
    }

    private function formatMetric(float $baseQuantity, UnitType $type, string $context): string
    {
        [$small, $large] = $type === UnitType::Mass ? ['g', 'kg'] : ['ml', 'l'];

        $step = $baseQuantity < 20 ? 1 : 5;
        $rounded = $this->roundToStep($baseQuantity, $step, $context);

        if ($rounded >= 1000) {
            $inLarge = $rounded / 1000;
            $inLarge = $context === self::SHOPPING
                ? ceil(round($inLarge * 100, 6)) / 100
                : round($inLarge, 2);

            return $this->number($inLarge).' '.$large;
        }

        return $this->number($rounded, 0).' '.$small;
    }

    /**
     * Arrondit à un multiple de $step (au plus proche ou au supérieur selon le contexte).
     * Une quantité strictement positive n'est jamais arrondie à 0.
     */
    private function roundToStep(float $value, float $step, string $context): float
    {
        if ($value <= 0) {
            return 0.0;
        }

        // round(…, 6) neutralise les erreurs de virgule flottante (0.1 + 0.2)
        $ratio = round($value / $step, 6);
        $rounded = ($context === self::SHOPPING ? ceil($ratio) : round($ratio)) * $step;

        return $rounded > 0 ? $rounded : $step;
    }

    /** 1.5 → « 1 ½ », 0.25 → « ¼ », 2 → « 2 ». */
    private function formatFraction(float $value): string
    {
        $whole = (int) floor($value);
        $decimal = round($value - $whole, 2);
        $symbol = self::FRACTIONS[(string) $decimal] ?? null;

        if ($decimal == 0.0) {
            return (string) $whole;
        }

        if ($symbol === null) {
            return $this->number($value);
        }

        return $whole > 0 ? $whole.' '.$symbol : $symbol;
    }
}
