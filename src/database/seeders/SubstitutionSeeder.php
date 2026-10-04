<?php

namespace Database\Seeders;

use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\Unit;
use App\Support\NameNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Remplacements courants (lot 40, 40.1, Q58) : une liste commune, modifiable par chaque foyer
 * (Paramètres › Remplacements). Ré-exécutable : un remplacement déjà présent n'est pas recréé, et
 * rien n'est créé sans les deux ingrédients.
 *
 * Quelques ingrédients utiles aux régimes (crème de soja, boisson végétale, margarine…) entrent au
 * catalogue s'ils n'y sont pas.
 */
class SubstitutionSeeder extends Seeder
{
    /** [nom, pluriel, rayon (nom), unité, poids d'une pièce] */
    public const NEW_INGREDIENTS = [
        ['Crème de soja', null, 'Crèmerie & œufs', 'cl', null],
        ['Boisson végétale', null, 'Crèmerie & œufs', 'ml', null],
        ['Margarine', null, 'Crèmerie & œufs', 'g', null],
        ['Fécule de maïs', null, 'Épicerie salée', 'g', null],
        ['Farine sans gluten', null, 'Épicerie salée', 'g', null],
        ['Graines de lin moulues', null, 'Épicerie salée', 'g', null],
    ];

    /** [ingrédient, remplacement, rapport, note] */
    public const SUBSTITUTIONS = [
        ['Crème liquide', 'Crème de soja', 1, 'sans lactose'],
        ['Crème liquide', 'Lait de coco', 1, 'goût plus doux, sans lactose'],
        ['Crème liquide', 'Crème fraîche épaisse', 1, 'plus épaisse'],
        ['Crème fraîche épaisse', 'Crème de soja', 1, 'sans lactose'],
        ['Crème fraîche épaisse', 'Fromage blanc', 1, 'à ajouter hors du feu'],
        ['Crème fraîche épaisse', 'Yaourt nature', 1, 'à ajouter hors du feu'],
        ['Lait demi-écrémé', 'Boisson végétale', 1, 'sans lactose'],
        ['Beurre', 'Margarine', 1, 'sans lactose'],
        ['Beurre', 'Huile d\'olive', 0.8, 'pour cuire, pas pour une pâte'],
        ['Beurre', 'Huile de tournesol', 0.8, 'dans un gâteau'],
        ['Œuf', 'Graines de lin moulues', 1, 'par œuf : 10 g de graines et 3 cuillères à soupe d\'eau, dans un gâteau'],
        ['Farine', 'Farine sans gluten', 1, 'sans gluten'],
        ['Farine', 'Fécule de maïs', 0.5, 'pour lier une sauce'],
        ['Chapelure', 'Flocons d\'avoine', 1, 'mixés'],
        ['Chapelure', 'Poudre d\'amande', 1, 'sans gluten'],
        ['Sucre', 'Miel', 0.75, null],
        ['Sucre', 'Sucre roux', 1, null],
        ['Sucre roux', 'Sucre', 1, null],
        ['Fromage blanc', 'Yaourt nature', 1, null],
        ['Yaourt nature', 'Fromage blanc', 1, null],
        ['Ricotta', 'Fromage blanc', 1, 'égoutté'],
        ['Parmesan', 'Comté', 1, null],
        ['Emmental râpé', 'Comté', 1, 'râpé'],
        ['Comté', 'Emmental râpé', 1, null],
        ['Lardons', 'Jambon blanc', 1, 'en dés'],
        ['Échalote', 'Oignon', 0.5, null],
        ['Oignon', 'Échalote', 2, null],
        ['Oignon rouge', 'Oignon', 1, null],
        ['Citron', 'Citron vert', 1, null],
        ['Citron vert', 'Citron', 1, null],
        ['Vinaigre balsamique', 'Vinaigre de vin', 1, 'avec une pincée de sucre'],
        ['Vin blanc', 'Bouillon de volaille', 1, 'sans alcool'],
        ['Bouillon de volaille', 'Bouillon de légumes', 1, null],
        ['Bouillon de légumes', 'Bouillon de volaille', 1, null],
        ['Thym frais', 'Thym séché', 0.33, 'un tiers de la quantité'],
        ['Épinards frais', 'Épinards surgelés', 1, null],
        ['Petits pois frais', 'Petits pois surgelés', 1, null],
        ['Pois chiches secs', 'Pois chiches en boîte', 2.5, 'égouttés'],
        ['Coulis de tomate', 'Tomates concassées', 1, null],
        ['Tomates concassées', 'Coulis de tomate', 1, null],
        ['Steak haché', 'Bœuf haché', 1, null],
        ['Bœuf haché', 'Steak haché', 1, null],
        ['Blanc de poulet', 'Escalope de dinde', 1, null],
        ['Spaghetti', 'Pâtes', 1, null],
    ];

    public function run(): void
    {
        $this->addIngredients();

        $ids = Ingredient::query()->pluck('id', 'search_name');
        $find = fn (string $name) => $ids->get(NameNormalizer::normalize($name));
        $now = now();

        foreach (self::SUBSTITUTIONS as [$from, $to, $ratio, $note]) {
            $fromId = $find($from);
            $toId = $find($to);

            if (! $fromId || ! $toId) {
                continue;
            }

            $exists = DB::table('ingredient_substitutions')->whereNull('household_id')->whereNull('recipe_id')
                ->where('ingredient_id', $fromId)->where('substitute_id', $toId)->exists();

            if (! $exists) {
                DB::table('ingredient_substitutions')->insert([
                    'household_id' => null, 'ingredient_id' => $fromId, 'substitute_id' => $toId, 'ratio' => $ratio,
                    'recipe_id' => null, 'note' => $note, 'source' => 'seed', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    private function addIngredients(): void
    {
        $existing = Ingredient::query()->pluck('search_name')->flip();
        $units = Unit::query()->pluck('id', 'code');

        foreach (self::NEW_INGREDIENTS as [$name, $plural, $aisleName, $unit, $pieceWeight]) {
            $aisle = Aisle::query()->where('name', $aisleName)->first();

            // Sans le catalogue de départ (une base vide), rien n'est ajouté.
            if (! $aisle || $existing->has(NameNormalizer::normalize($name)) || ! $existing->has(NameNormalizer::normalize('Farine'))) {
                continue;
            }

            Ingredient::create([
                'name' => $name,
                'name_plural' => $plural,
                'aisle_id' => $aisle->id,
                'default_unit_id' => $units[$unit] ?? null,
                'piece_weight_g' => $pieceWeight,
                'is_staple' => false,
            ]);
        }
    }
}
