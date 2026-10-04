<?php

namespace App\Support;

/**
 * Les 14 allergènes à déclarer (règlement UE 1169/2011), tels qu'Open Food Facts les étiquette
 * (`en:milk`…), et les mots qui y rattachent un ingrédient de Bouffe (lot 40, 40.1 et 40.4).
 *
 * Sert à deux choses :
 *  - savoir qu'une allergie notée sur « Lait demi-écrémé » concerne le groupe « lait », donc aussi
 *    le beurre d'un remplacement ou un produit du stock qui « contient du lait » ;
 *  - lire les allergènes d'un produit scanné.
 *
 * Le rapprochement se fait sur les mots du nom de l'ingrédient : c'est indicatif, jamais une
 * garantie (R44).
 */
final class AllergenGroups
{
    /** étiquette Open Food Facts => [libellé, mots (sans accents) qui rattachent un ingrédient] */
    public const GROUPS = [
        'en:gluten' => ['gluten', ['farine', 'ble', 'pate', 'pates', 'spaghetti', 'lasagne', 'gnocchi', 'pain', 'baguette', 'chapelure', 'semoule', 'seigle', 'orge', 'epeautre', 'biere', 'tortilla', 'brioche', 'avoine']],
        'en:crustaceans' => ['crustacés', ['crevette', 'crabe', 'homard', 'langoustine', 'ecrevisse']],
        'en:eggs' => ['œufs', ['oeuf', 'mayonnaise']],
        'en:fish' => ['poisson', ['poisson', 'saumon', 'cabillaud', 'thon', 'truite', 'colin', 'sardine', 'maquereau', 'lieu', 'anchois', 'merlu']],
        'en:peanuts' => ['arachides', ['arachide', 'cacahuete', 'beurre de cacahuete']],
        'en:soybeans' => ['soja', ['soja', 'tofu', 'edamame']],
        'en:milk' => ['lait', ['lait', 'beurre', 'creme', 'fromage', 'yaourt', 'mozzarella', 'parmesan', 'comte', 'emmental', 'ricotta', 'mascarpone', 'feta', 'chevre', 'reblochon', 'gruyere', 'raclette', 'lactose']],
        'en:nuts' => ['fruits à coque', ['noix', 'noisette', 'amande', 'cajou', 'pistache', 'pecan', 'macadamia', 'praline']],
        'en:celery' => ['céleri', ['celeri']],
        'en:mustard' => ['moutarde', ['moutarde']],
        'en:sesame-seeds' => ['sésame', ['sesame', 'tahini', 'tahin']],
        'en:sulphur-dioxide-and-sulphites' => ['sulfites', ['sulfite', 'vin blanc', 'vin rouge', 'vinaigre']],
        'en:lupin' => ['lupin', ['lupin']],
        'en:molluscs' => ['mollusques', ['moule', 'huitre', 'calamar', 'seiche', 'poulpe', 'coquille saint jacques', 'escargot']],
    ];

    /** Mots qui annulent un groupe : « lait de coco » n'est pas du lait, « noix de coco » n'est pas une noix. */
    private const EXCEPTIONS = [
        'en:milk' => ['lait de coco', 'boisson vegetale', 'creme de soja', 'beurre de cacahuete'],
        'en:nuts' => ['noix de coco', 'noix de muscade', 'muscade'],
        'en:gluten' => ['sans gluten', 'pate de curry'],
    ];

    /** @return list<string> groupes d'un ingrédient d'après son nom */
    public static function ofName(?string $name): array
    {
        $plain = ' '.\Illuminate\Support\Str::of((string) $name)->replace(['œ', 'Œ'], 'oe')->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->value().' ';

        if (trim($plain) === '') {
            return [];
        }

        $groups = [];

        foreach (self::GROUPS as $tag => [, $words]) {
            foreach (self::EXCEPTIONS[$tag] ?? [] as $exception) {
                if (str_contains($plain, ' '.$exception.' ')) {
                    continue 2;
                }
            }

            foreach ($words as $word) {
                if (preg_match('/ '.preg_quote($word, '/').'s? /', $plain)) {
                    $groups[] = $tag;

                    break;
                }
            }
        }

        return $groups;
    }

    public static function label(string $tag): string
    {
        return self::GROUPS[$tag][0] ?? str_replace(['en:', 'fr:', '-'], ['', '', ' '], $tag);
    }

    /**
     * Les étiquettes d'Open Food Facts gardées : les 14 groupes connus, et les autres telles quelles.
     *
     * @param  iterable<string>  $tags
     * @return list<string>
     */
    public static function clean(iterable $tags): array
    {
        $clean = [];

        foreach ($tags as $tag) {
            $tag = mb_strtolower(trim((string) $tag));

            if ($tag !== '' && preg_match('/^[a-z]{2}:[a-z0-9\-]+$/', $tag)) {
                $clean[] = $tag;
            }
        }

        return array_values(array_unique($clean));
    }

    /** « lait, gluten » */
    public static function labels(iterable $tags): string
    {
        return collect($tags)->map(fn (string $tag) => self::label($tag))->unique()->join(', ');
    }
}
