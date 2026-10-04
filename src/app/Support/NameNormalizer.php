<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Normalise un nom (ingrédient, catégorie…) pour la recherche et la détection de doublons.
 *
 *   « Tomates cerises » → « tomate cerise »
 *   « Œufs »            → « oeuf »
 *   « Poireaux »        → « poireau »
 *
 * Le résultat n'est jamais affiché : il sert uniquement à comparer. Le « singulier » est
 * volontairement naïf (suppression d'un s/x final) : l'important est que deux saisies
 * proches donnent la même clé.
 */
final class NameNormalizer
{
    public static function normalize(?string $name): string
    {
        $name = Str::of((string) $name)
            ->replace(['œ', 'Œ', 'æ', 'Æ'], ['oe', 'oe', 'ae', 'ae'])
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->value();

        if ($name === '') {
            return '';
        }

        return collect(explode(' ', $name))
            ->map(fn (string $word) => self::singular($word))
            ->implode(' ');
    }

    private static function singular(string $word): string
    {
        if (strlen($word) > 3 && in_array(substr($word, -1), ['s', 'x'], true)) {
            return substr($word, 0, -1);
        }

        return $word;
    }

    /**
     * Indique si deux noms déjà normalisés sont « proches » (faute de frappe, mot en plus…).
     */
    public static function isSimilar(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        if (str_contains($a, $b) || str_contains($b, $a)) {
            return min(strlen($a), strlen($b)) >= 4;
        }

        $maxDistance = max(strlen($a), strlen($b)) >= 8 ? 2 : 1;

        return levenshtein($a, $b) <= $maxDistance;
    }
}
