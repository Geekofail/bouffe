<?php

namespace App\Services\Recipes;

use App\Models\RecipeStep;
use App\Support\NameNormalizer;

/**
 * « Ils cuisinent » (lot 39, 39.4) : les étapes qui demandent un adulte (four, couteau, plaque,
 * friture, eau bouillante), repérées d'après les mots de l'étape. Une étape peut être corrigée à la
 * main (`recipe_steps.adult_help`) : la correction l'emporte.
 */
class AdultSteps
{
    /** Débuts de mots (sans accents) et ce qu'ils signalent. */
    private const WORDS = [
        'four' => 'four', 'enfourn' => 'four', 'gratin' => 'four', 'grill' => 'four', 'prechauff' => 'four',
        'couteau' => 'couteau', 'couper' => 'couteau', 'coupez' => 'couteau', 'coupe ' => 'couteau', 'emincer' => 'couteau', 'emincez' => 'couteau',
        'hacher' => 'couteau', 'hachez' => 'couteau', 'trancher' => 'couteau', 'tranchez' => 'couteau', 'ciseler' => 'couteau', 'ciselez' => 'couteau',
        'desosser' => 'couteau', 'eplucher' => 'couteau', 'epluchez' => 'couteau', 'peler' => 'couteau', 'pelez' => 'couteau',
        'mandoline' => 'couteau', 'raper' => 'couteau', 'rapez' => 'couteau', 'detailler' => 'couteau', 'detaillez' => 'couteau',
        'poele' => 'plaque', 'sauteuse' => 'plaque', 'casserole' => 'plaque', 'faire revenir' => 'plaque', 'faites revenir' => 'plaque',
        'faire dorer' => 'plaque', 'faites dorer' => 'plaque', 'rissol' => 'plaque', 'saisir' => 'plaque', 'feu vif' => 'plaque', 'feu moyen' => 'plaque', 'plaque' => 'plaque',
        'caramel' => 'plaque', 'flamb' => 'plaque',
        'frit' => 'friture', 'friture' => 'friture', 'bain d huile' => 'friture', 'huile chaude' => 'friture',
        'bouillant' => 'chaud', 'ebullition' => 'chaud', 'egoutt' => 'chaud', 'cocotte minute' => 'chaud', 'autocuiseur' => 'chaud',
        'mixer' => 'mixeur', 'mixez' => 'mixeur', 'mixeur' => 'mixeur', 'blender' => 'mixeur', 'robot' => 'mixeur',
    ];

    public const REASONS = [
        'four' => 'four',
        'couteau' => 'couteau',
        'plaque' => 'plaque de cuisson',
        'friture' => 'friture',
        'chaud' => 'eau bouillante',
        'mixeur' => 'mixeur',
    ];

    /** La raison repérée dans le texte (« four », « couteau »…), ou null. */
    public function reason(string $text): ?string
    {
        $plain = ' '.str_replace(['\'', '’'], ' ', NameNormalizer::normalize($text)).' ';

        foreach (self::WORDS as $word => $reason) {
            if (preg_match('/[^a-z]'.preg_quote($word, '/').'/', $plain)) {
                return self::REASONS[$reason];
            }
        }

        return null;
    }

    /** L'étape demande-t-elle un adulte ? La correction faite à la main l'emporte sur les mots. */
    public function needsAdult(RecipeStep $step): bool
    {
        return $step->adult_help ?? $this->reason($step->instruction) !== null;
    }

    /** Corrige une étape : avec un adulte, sans, ou retour au repérage automatique (null). */
    public function correct(RecipeStep $step, ?bool $adult): void
    {
        $automatic = $this->reason($step->instruction) !== null;
        $step->update(['adult_help' => $adult === null || $adult === $automatic ? null : $adult]);
    }
}
