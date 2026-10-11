<?php

namespace App\Services\Nutrition;

/**
 * Repères par portion (lot 43) : une portion comparée à un **repas type**, c'est-à-dire un tiers
 * des apports de référence d'un adulte (règlement UE 1169/2011 ; fibres : 30 g/j, Anses).
 *
 * Ce sont des repères pour composer une semaine, pas des recommandations médicales : les besoins
 * varient d'une personne à l'autre (âge, activité, santé).
 */
class MealBenchmarks
{
    /** Apports de référence d'un adulte, par jour. */
    public const DAILY = [
        'energy_kcal' => 2000,
        'proteins' => 50,
        'carbs' => 260,
        'sugars' => 90,
        'fat' => 70,
        'saturated_fat' => 20,
        'fibres' => 30,
        'salt' => 6,
    ];

    /** Un repas = un tiers de la journée. */
    public const MEAL_SHARE = 1 / 3;

    /**
     * Mentions d'une portion : [clé, libellé, ton (good|warn|info)].
     *
     * @param  array<string, float|int|null>  $perPortion
     * @return list<array{key: string, label: string, tone: string}>
     */
    public function flags(array $perPortion): array
    {
        $energy = (float) ($perPortion['energy_kcal'] ?? 0);
        $flags = [];

        if ($energy > 0 && $energy < 400) {
            $flags[] = ['key' => 'light', 'label' => 'Léger', 'tone' => 'info'];
        } elseif ($energy > 900) {
            $flags[] = ['key' => 'hearty', 'label' => 'Copieux', 'tone' => 'warn'];
        }

        if ((float) ($perPortion['salt'] ?? 0) > 2) {
            $flags[] = ['key' => 'salt', 'label' => 'Riche en sel', 'tone' => 'warn'];
        }

        if ((float) ($perPortion['saturated_fat'] ?? 0) > $this->meal('saturated_fat')) {
            $flags[] = ['key' => 'saturated_fat', 'label' => 'Riche en graisses saturées', 'tone' => 'warn'];
        }

        if ((float) ($perPortion['sugars'] ?? 0) > 30) {
            $flags[] = ['key' => 'sugars', 'label' => 'Sucré', 'tone' => 'warn'];
        }

        if ((float) ($perPortion['fibres'] ?? 0) >= 6) {
            $flags[] = ['key' => 'fibres', 'label' => 'Bonne source de fibres', 'tone' => 'good'];
        }

        if ((float) ($perPortion['proteins'] ?? 0) >= 20) {
            $flags[] = ['key' => 'proteins', 'label' => 'Riche en protéines', 'tone' => 'good'];
        }

        return $flags;
    }

    /** Part d'un repas type, en % (une portion à 667 kcal = 100 % de l'énergie d'un repas). */
    public function share(string $nutrient, float|int|null $value): ?int
    {
        $meal = $this->meal($nutrient);

        return $meal > 0 && $value !== null ? (int) round((float) $value / $meal * 100) : null;
    }

    /** Repère d'un repas pour un constituant. */
    public function meal(string $nutrient): float
    {
        return round((self::DAILY[$nutrient] ?? 0) * self::MEAL_SHARE, 1);
    }
}
