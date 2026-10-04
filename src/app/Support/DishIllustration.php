<?php

namespace App\Support;

use App\Enums\Course;
use App\Models\Recipe;

/**
 * Illustration d'un plat (lot 29, 29.3) : à la place de la grande lettre des recettes sans photo.
 *
 * Deux choix, faits d'après ce que Bouffe sait déjà de la recette :
 *  - le **dessin** (soupe, salade, tarte, pâtes, mijoté, crêpes, gratin, gâteau, sinon une assiette),
 *    d'après des mots du titre puis les catégories ;
 *  - la **teinte** (entrée, plat, dessert, accompagnement), d'après la place du plat dans le repas,
 *    sinon les catégories.
 *
 * Ce ne sont pas des photos : un dessin simple, le même pour toutes les quiches. La vraie photo le
 * remplace dès qu'il y en a une.
 */
final class DishIllustration
{
    public const KINDS = ['soupe', 'salade', 'tarte', 'pates', 'curry', 'crepes', 'gratin', 'gateau', 'assiette'];

    public const TONES = ['entree', 'plat', 'dessert', 'accompagnement'];

    /** Mots du titre (normalisés : sans accents, au singulier) → dessin. Le premier qui correspond l'emporte : « pâte brisée » est une tarte, pas des pâtes. */
    private const WORDS = [
        'crepes' => ['crepe', 'galette', 'gaufre', 'pancake', 'blini'],
        'gateau' => ['gateau', 'cake', 'fondant', 'moelleux', 'brownie', 'muffin', 'cookie', 'biscuit', 'cheesecake', 'clafouti', 'tiramisu', 'charlotte', 'buche', 'financier', 'madeleine', 'mousse au chocolat'],
        'tarte' => ['tarte', 'quiche', 'tourte', 'pizza', 'flammekueche', 'feuillete', 'crumble', 'pate brisee', 'pate sablee', 'pate feuilletee'],
        'gratin' => ['gratin', 'lasagne', 'parmentier', 'moussaka', 'tian', 'hachi', 'cannelloni'],
        'soupe' => ['soupe', 'veloute', 'potage', 'bouillon', 'gaspacho', 'minestrone', 'creme de', 'bisque', 'pho', 'ramen'],
        'salade' => ['salade', 'taboule', 'carpaccio', 'bowl', 'coleslaw', 'tartine', 'sandwich', 'wrap'],
        'pates' => ['spaghetti', 'pate', 'tagliatelle', 'penne', 'fusilli', 'linguine', 'macaroni', 'raviol', 'gnocchi', 'nouille', 'risotto', 'carbonara', 'bolognaise'],
        'curry' => ['curry', 'chili', 'mijote', 'ragout', 'bourguignon', 'blanquette', 'tajine', 'couscou', 'daube', 'dahl', 'dal', 'goulash', 'pot au feu', 'cassoulet', 'colombo', 'riz', 'poelee', 'saute'],
    ];

    /** Catégories → dessin, quand le titre ne dit rien. */
    private const TAGS = [
        'soupe' => 'soupe',
        'salade' => 'salade',
        'dessert' => 'gateau',
        'petit dejeuner' => 'crepes',
        'accompagnement' => 'gratin',
    ];

    /**
     * @return array{kind: string, tone: string}
     */
    public static function for(Recipe $recipe, ?Course $course = null): array
    {
        $title = NameNormalizer::normalize($recipe->title);
        $tags = $recipe->relationLoaded('tags')
            ? $recipe->tags->map(fn ($tag) => NameNormalizer::normalize($tag->name))->all()
            : [];

        return ['kind' => self::kind($title, $tags), 'tone' => self::tone($tags, $course)];
    }

    /** @param  list<string>  $tags  noms de catégories normalisés */
    public static function kind(string $normalizedTitle, array $tags = []): string
    {
        foreach (self::WORDS as $kind => $words) {
            foreach ($words as $word) {
                if (preg_match('/(^|[^a-z])'.preg_quote($word, '/').'/', $normalizedTitle)) {
                    return $kind;
                }
            }
        }

        foreach (self::TAGS as $tag => $kind) {
            if (in_array($tag, $tags, true)) {
                return $kind;
            }
        }

        return 'assiette';
    }

    /** @param  list<string>  $tags  noms de catégories normalisés */
    public static function tone(array $tags = [], ?Course $course = null): string
    {
        return match (true) {
            $course === Course::Dessert => 'dessert',
            $course === Course::Starter, $course === Course::Aperitif => 'entree',
            $course === Course::Main, $course === Course::Cheese => 'plat',
            in_array('dessert', $tags, true), in_array('petit dejeuner', $tags, true) => 'dessert',
            in_array('accompagnement', $tags, true) => 'accompagnement',
            in_array('entree', $tags, true), in_array('soupe', $tags, true), in_array('salade', $tags, true), in_array('aperitif', $tags, true) => 'entree',
            default => 'plat',
        };
    }
}
