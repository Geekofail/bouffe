<?php

namespace App\Services\Nutrition;

/**
 * Nutri-Score **estimé** d'une recette (lot 43).
 *
 * Calcul de Bouffe d'après la table Ciqual, avec l'algorithme « aliments généraux » en vigueur
 * depuis 2024 : des points pour ce qu'il faut limiter (énergie, sucres, graisses saturées, sel),
 * retirés des points pour ce qui est favorable (protéines, fibres, fruits-légumes-légumineuses).
 * Valeurs pour 100 g de recette, ingrédients pesés crus.
 *
 * Ce n'est **pas** le Nutri-Score officiel d'un produit : le logo officiel n'est jamais affiché,
 * la mention « estimé » accompagne toujours la lettre. Simplifications assumées : pas de règle
 * particulière pour la viande rouge, les fromages ou les matières grasses ajoutées, et le poids
 * cru ne tient pas compte de l'eau perdue ou absorbée à la cuisson.
 */
class NutriScore
{
    /** Seuils « strictement au-dessus de » : un point par seuil dépassé. */
    public const ENERGY_KJ = [335, 670, 1005, 1340, 1675, 2010, 2345, 2680, 3015, 3350];

    public const SUGARS = [3.4, 6.8, 10, 14, 17, 20, 24, 27, 31, 34, 37, 41, 44, 48, 51];

    public const SATURATED_FAT = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

    public const SALT = [0.2, 0.4, 0.6, 0.8, 1.0, 1.2, 1.4, 1.6, 1.8, 2.0, 2.2, 2.4, 2.6, 2.8, 3.0, 3.2, 3.4, 3.6, 3.8, 4.0];

    public const PROTEINS = [2.4, 4.8, 7.2, 9.6, 12, 14, 17];

    public const FIBRES = [3.0, 4.1, 5.2, 6.3, 7.4];

    /** Lettre => intitulé (les couleurs sont dans le composant x-nutri-score). */
    public const LETTERS = [
        'A' => 'Très bonne qualité nutritionnelle',
        'B' => 'Bonne qualité nutritionnelle',
        'C' => 'Qualité nutritionnelle moyenne',
        'D' => 'Qualité nutritionnelle médiocre',
        'E' => 'Qualité nutritionnelle faible',
    ];

    /** « Remplir la semaine » : bonus de score par lettre (doublé quand la semaine penche). */
    public const FILLER_BONUS = ['A' => 4, 'B' => 2, 'C' => 0, 'D' => -2, 'E' => -4];

    /** Une semaine « penche » quand sa moyenne est D ou pire (rang ≥ 3,5), sur au moins deux repas. */
    public const LEANING_RANK = 3.5;

    /** Kilojoules par kilocalorie. */
    private const KJ_PER_KCAL = 4.184;

    /**
     * @param  array<string, float|int|null>  $per100  valeurs pour 100 g (clés de NutritionFood::NUTRIENTS)
     * @param  int|float  $fruitVegetable  part de fruits, légumes et légumineuses, en %
     * @return array{letter: string, score: int, negative: int, positive: int, proteins_counted: bool, points: array<string, int>}
     */
    public function compute(array $per100, int|float $fruitVegetable): array
    {
        $points = [
            'energy' => $this->points(((float) ($per100['energy_kcal'] ?? 0)) * self::KJ_PER_KCAL, self::ENERGY_KJ),
            'sugars' => $this->points($per100['sugars'] ?? 0, self::SUGARS),
            'saturated_fat' => $this->points($per100['saturated_fat'] ?? 0, self::SATURATED_FAT),
            'salt' => $this->points($per100['salt'] ?? 0, self::SALT),
            'proteins' => $this->points($per100['proteins'] ?? 0, self::PROTEINS),
            'fibres' => $this->points($per100['fibres'] ?? 0, self::FIBRES),
            'fruit_vegetable' => $this->fruitVegetablePoints((float) $fruitVegetable),
        ];

        $negative = $points['energy'] + $points['sugars'] + $points['saturated_fat'] + $points['salt'];

        // Au-delà de 11 points défavorables, les protéines ne comptent que si la recette est
        // très riche en fruits et légumes (règle de l'algorithme).
        $proteinsCounted = $negative < 11 || $points['fruit_vegetable'] >= 5;
        $positive = $points['fibres'] + $points['fruit_vegetable'] + ($proteinsCounted ? $points['proteins'] : 0);
        $score = $negative - $positive;

        return [
            'letter' => $this->letter($score),
            'score' => $score,
            'negative' => $negative,
            'positive' => $positive,
            'proteins_counted' => $proteinsCounted,
            'points' => $points,
        ];
    }

    public function letter(int $score): string
    {
        return match (true) {
            $score <= 0 => 'A',
            $score <= 2 => 'B',
            $score <= 10 => 'C',
            $score <= 18 => 'D',
            default => 'E',
        };
    }

    /** Valeur moyenne d'une lettre (A = 1 … E = 5), pour faire des moyennes. */
    public static function rank(?string $letter): ?int
    {
        $index = $letter ? array_search($letter, array_keys(self::LETTERS), true) : false;

        return $index === false ? null : $index + 1;
    }

    /** @param  list<int>  $ranks */
    public static function leans(array $ranks): bool
    {
        return count($ranks) >= 2 && array_sum($ranks) / count($ranks) >= self::LEANING_RANK;
    }

    /** Lettre la plus proche d'un rang moyen (2,4 → B). */
    public static function fromRank(float $rank): string
    {
        $index = (int) max(0, min(4, round($rank) - 1));

        return array_keys(self::LETTERS)[$index];
    }

    /**
     * Détail lisible : [libellé, valeur, points, « défavorable » ou « favorable »].
     *
     * @param  array<string, float|int|null>  $per100
     * @return list<array{label: string, value: string, points: int, max: int, kind: string, counted: bool}>
     */
    public function breakdown(array $per100, int|float $fruitVegetable, array $result): array
    {
        $grams = fn ($value) => number_format((float) $value, 1, ',', "\u{202F}").' g';

        return [
            ['label' => 'Énergie', 'value' => number_format((float) ($per100['energy_kcal'] ?? 0), 0, ',', "\u{202F}").' kcal', 'points' => $result['points']['energy'], 'max' => 10, 'kind' => 'negative', 'counted' => true],
            ['label' => 'Sucres', 'value' => $grams($per100['sugars'] ?? 0), 'points' => $result['points']['sugars'], 'max' => 15, 'kind' => 'negative', 'counted' => true],
            ['label' => 'Graisses saturées', 'value' => $grams($per100['saturated_fat'] ?? 0), 'points' => $result['points']['saturated_fat'], 'max' => 10, 'kind' => 'negative', 'counted' => true],
            ['label' => 'Sel', 'value' => number_format((float) ($per100['salt'] ?? 0), 2, ',', "\u{202F}").' g', 'points' => $result['points']['salt'], 'max' => 20, 'kind' => 'negative', 'counted' => true],
            ['label' => 'Fruits, légumes, légumineuses', 'value' => (int) round($fruitVegetable).' %', 'points' => $result['points']['fruit_vegetable'], 'max' => 5, 'kind' => 'positive', 'counted' => true],
            ['label' => 'Fibres', 'value' => $grams($per100['fibres'] ?? 0), 'points' => $result['points']['fibres'], 'max' => 5, 'kind' => 'positive', 'counted' => true],
            ['label' => 'Protéines', 'value' => $grams($per100['proteins'] ?? 0), 'points' => $result['points']['proteins'], 'max' => 7, 'kind' => 'positive', 'counted' => $result['proteins_counted']],
        ];
    }

    /** @param  list<int|float>  $thresholds */
    private function points(float|int|null $value, array $thresholds): int
    {
        $value = (float) ($value ?? 0);

        return count(array_filter($thresholds, fn ($threshold) => $value > $threshold));
    }

    private function fruitVegetablePoints(float $percent): int
    {
        return match (true) {
            $percent > 80 => 5,
            $percent > 60 => 2,
            $percent > 40 => 1,
            default => 0,
        };
    }
}
