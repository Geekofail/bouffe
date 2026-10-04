<?php

namespace Database\Seeders;

use App\Models\Ingredient;
use Illuminate\Database\Seeder;

/**
 * Mois de saison de départ pour les fruits et légumes (17.1, règle R18).
 *
 * ⚠️ Ce sont des **valeurs de départ raisonnables pour le Luxembourg et sa région**, pas une
 * référence officielle : les saisons varient avec le climat, les variétés et les producteurs.
 * Elles sont là pour que la fonction serve tout de suite, et se modifient une par une dans
 * Paramètres → Saisons.
 *
 * Principe retenu : la **pleine saison locale** (ce qu'on trouve frais, produit près d'ici),
 * pas la disponibilité en rayon — sinon tout serait « de saison » toute l'année.
 *
 * Un ingrédient absent de cette liste n'a pas de saison : il n'est jamais signalé hors saison.
 */
class SeasonSeeder extends Seeder
{
    /** nom de l'ingrédient => mois de pleine saison */
    public const SEASONS = [
        // ---------------------------------------------------------------- Légumes
        'Aubergine' => [7, 8, 9],
        'Betterave cuite' => [6, 7, 8, 9, 10, 11, 12],
        'Brocoli' => [6, 7, 8, 9, 10, 11],
        'Carotte' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
        'Céleri branche' => [8, 9, 10, 11, 12, 1, 2],
        'Champignon de Paris' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
        'Chou-fleur' => [4, 5, 6, 9, 10, 11],
        'Concombre' => [5, 6, 7, 8, 9],
        'Courgette' => [6, 7, 8, 9],
        'Épinards frais' => [3, 4, 5, 9, 10, 11],
        'Fenouil' => [6, 7, 8, 9, 10],
        'Haricots verts' => [6, 7, 8, 9],
        'Navet' => [9, 10, 11, 12, 1, 2, 3],
        'Oignon' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
        'Oignon rouge' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
        'Petits pois frais' => [5, 6, 7],
        'Poireau' => [9, 10, 11, 12, 1, 2, 3, 4],
        'Poivron rouge' => [7, 8, 9, 10],
        'Poivron vert' => [7, 8, 9, 10],
        'Patate douce' => [9, 10, 11, 12, 1],
        'Échalote' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
        'Ail' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
        'Potiron' => [9, 10, 11, 12],
        'Radis' => [4, 5, 6, 7, 8, 9],
        'Salade verte' => [4, 5, 6, 7, 8, 9, 10],
        'Tomate' => [6, 7, 8, 9, 10],
        'Tomate cerise' => [6, 7, 8, 9, 10],
        'Pomme de terre' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
        'Butternut' => [9, 10, 11, 12, 1],

        // ---------------------------------------------------------------- Fruits
        'Citron' => [11, 12, 1, 2, 3, 4],
        'Citron vert' => [11, 12, 1, 2, 3, 4],
        'Orange' => [11, 12, 1, 2, 3, 4],
        'Fraise' => [5, 6, 7],
        'Framboise' => [6, 7, 8, 9],
        'Poire' => [8, 9, 10, 11, 12, 1],
        'Pomme' => [8, 9, 10, 11, 12, 1, 2, 3],
        // Les fruits et légumes absents du référentiel de départ (abricot, melon, asperge…)
        // se règlent dans Paramètres → Saisons une fois l'ingrédient créé.
    ];

    public function run(): void
    {
        foreach (self::SEASONS as $name => $months) {
            $ingredient = Ingredient::findByName($name);

            // On ne crée rien : seuls les ingrédients déjà présents sont complétés.
            if (! $ingredient || $ingredient->season_months) {
                continue;
            }

            $ingredient->forceFill(['season_months' => array_values($months)])->save();
        }
    }
}
