<?php

namespace App\Services\Recipes;

use App\Models\Recipe;
use App\Models\Stay;
use App\Support\NameNormalizer;
use App\Support\Settings;

/**
 * Équipement de la cuisine (lot 40, 40.3, Q57).
 *
 * Une recette dit ce qu'elle demande : ce qui est enregistré sur la recette, sinon ce que le texte
 * des étapes laisse deviner (« enfourner », « mixer »…). La maison dit ce qu'elle a (Paramètres ›
 * Équipement ; tant que rien n'est réglé, elle a tout). Un séjour a le sien (« pas de four au
 * chalet ») : son planning écarte les recettes impossibles, comme « Remplir » et « Autre idée » à
 * la maison.
 */
class KitchenEquipment
{
    /** clé => [libellé, mots repérés dans les étapes (début de mot, sans accents ; « four  » : le mot entier)] — Q57 */
    public const ITEMS = [
        'four' => ['Four', ['four ', 'enfourn', 'gratiner', 'prechauff', 'au grill']],
        'micro-ondes' => ['Micro-ondes', ['micro onde', 'microonde']],
        'plaques' => ['Plaques de cuisson', ['poele', 'casserole', 'sauteuse', 'faire revenir', 'faites revenir', 'faire dorer', 'faites dorer', 'feu vif', 'feu moyen', 'feu doux', 'porter a ebullition', 'wok', 'cocotte']],
        'robot' => ['Robot pâtissier ou culinaire', ['robot', 'petrin', 'batteur', 'fouet electrique', 'thermomix']],
        'mixeur' => ['Mixeur plongeant ou blender', ['mixer', 'mixez', 'mixeur', 'blender', 'mouliner']],
        'vapeur' => ['Cuiseur vapeur', ['cuiseur vapeur', 'a la vapeur', 'cuire a la vapeur', 'panier vapeur']],
        'friteuse-air' => ['Friteuse à air', ['friteuse a air', 'air fryer', 'airfryer']],
        'multicuiseur' => ['Multicuiseur', ['multicuiseur', 'cookeo', 'autocuiseur', 'cocotte minute']],
        'barbecue' => ['Barbecue', ['barbecue', 'plancha']],
    ];

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::ITEMS);
    }

    public function label(string $key): string
    {
        return self::ITEMS[$key][0] ?? $key;
    }

    /** @param  list<string>  $keys */
    public function labels(array $keys): string
    {
        return collect($keys)->map(fn (string $key) => mb_strtolower($this->label($key)))->join(', ');
    }

    /**
     * Ce que demande le texte des étapes.
     *
     * @return list<string>
     */
    public function detect(Recipe $recipe): array
    {
        $recipe->loadMissing('steps');
        $text = ' '.str_replace(['\'', '’'], ' ', NameNormalizer::normalize($recipe->steps->pluck('instruction')->join(' '))).' ';
        $found = [];

        foreach (self::ITEMS as $key => [, $words]) {
            foreach ($words as $word) {
                if (str_contains($text, ' '.$word)) {
                    $found[] = $key;

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Ce que demande la recette : son réglage, sinon le texte des étapes.
     *
     * @return list<string>
     */
    public function required(Recipe $recipe): array
    {
        return is_array($recipe->equipment) ? array_values(array_intersect($recipe->equipment, $this->keys())) : $this->detect($recipe);
    }

    /**
     * L'équipement disponible : celui d'un séjour, sinon celui de la maison (tout, si rien n'est réglé).
     *
     * @return list<string>
     */
    public function available(?Stay $stay = null): array
    {
        if ($stay && is_array($stay->equipment)) {
            return array_values(array_intersect($stay->equipment, $this->keys()));
        }

        return Settings::stored('kitchen.equipment')
            ? array_values(array_intersect((array) Settings::get('kitchen.equipment', []), $this->keys()))
            : $this->keys();
    }

    /**
     * Ce qui manque pour faire la recette.
     *
     * @return list<string>
     */
    public function missing(Recipe $recipe, ?Stay $stay = null): array
    {
        return array_values(array_diff($this->required($recipe), $this->available($stay)));
    }

    public function possible(Recipe $recipe, ?Stay $stay = null): bool
    {
        return $this->missing($recipe, $stay) === [];
    }

    /** @param  list<string>  $keys */
    public function saveHome(array $keys): void
    {
        Settings::set('kitchen.equipment', array_values(array_intersect($keys, $this->keys())));
    }

    /**
     * Enregistre ce que demande une recette ; null revient au repérage automatique.
     *
     * @param  list<string>|null  $keys
     */
    public function saveRecipe(Recipe $recipe, ?array $keys): void
    {
        $recipe->forceFill(['equipment' => $keys === null ? null : array_values(array_intersect($keys, $this->keys()))])->save();
    }
}
