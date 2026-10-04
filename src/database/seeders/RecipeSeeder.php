<?php

namespace Database\Seeders;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\Unit;
use App\Support\NameNormalizer;
use Illuminate\Database\Seeder;

/**
 * Quelques recettes d'exemple pour tester le carnet (lot 2) et bientôt le planning.
 * Ré-exécutable : une recette déjà présente (même titre) n'est pas recréée.
 * Les exemples peuvent être supprimés ou archivés librement.
 */
class RecipeSeeder extends Seeder
{
    /**
     * ingrédients : [quantité|null, code unité|null, nom de l'ingrédient, précision|null, groupe|null, facultatif]
     */
    public const RECIPES = [
        [
            'title' => 'Spaghetti bolognaise',
            'description' => 'Le classique du dimanche, encore meilleur réchauffé le lendemain.',
            'servings' => 4, 'prep' => 15, 'cook' => 45, 'rest' => null, 'difficulty' => 'easy',
            'tags' => ['Plat', 'Viande', 'Batch cooking'],
            'ingredients' => [
                [400, 'g', 'Spaghetti', null, null, false],
                [500, 'g', 'Bœuf haché', null, null, false],
                [1, 'piece', 'Oignon', 'émincé', null, false],
                [2, 'gousse', 'Ail', 'haché', null, false],
                [1, 'piece', 'Carotte', 'en petits dés', null, false],
                [1, 'boite', 'Tomates concassées', null, null, false],
                [2, 'cs', 'Concentré de tomate', null, null, false],
                [2, 'cs', 'Huile d\'olive', null, null, false],
                [10, 'cl', 'Vin rouge', null, null, true],
                [1, 'cc', 'Herbes de Provence', null, null, false],
                [null, null, 'Sel', null, null, false],
                [null, null, 'Poivre', null, null, false],
                [40, 'g', 'Parmesan', 'râpé', null, true],
            ],
            'steps' => [
                'Faire revenir l\'oignon, l\'ail et la carotte dans l\'huile d\'olive pendant 5 minutes.',
                'Ajouter la viande hachée et la faire dorer en l\'émiettant.',
                'Déglacer au vin rouge, puis ajouter les tomates concassées, le concentré et les herbes. Saler, poivrer.',
                'Laisser mijoter à couvert 35 à 40 minutes à feu doux.',
                'Cuire les spaghetti al dente, égoutter et servir avec la sauce et le parmesan.',
            ],
        ],
        [
            'title' => 'Quiche lorraine',
            'description' => 'Pâte brisée, lardons et appareil crémeux.',
            'servings' => 6, 'prep' => 15, 'cook' => 35, 'rest' => null, 'difficulty' => 'easy',
            'tags' => ['Plat', 'Viande'],
            'ingredients' => [
                [1, 'piece', 'Pâte brisée', null, null, false],
                [200, 'g', 'Lardons', null, null, false],
                [3, 'piece', 'Œuf', null, null, false],
                [20, 'cl', 'Crème fraîche épaisse', null, null, false],
                [20, 'cl', 'Lait demi-écrémé', null, null, false],
                [1, 'pincee', 'Muscade', null, null, true],
                [null, null, 'Poivre', null, null, false],
            ],
            'steps' => [
                'Préchauffer le four à 200 °C. Foncer un moule avec la pâte et la piquer à la fourchette.',
                'Faire revenir les lardons à sec et les répartir sur la pâte.',
                'Battre les œufs avec la crème, le lait, la muscade et le poivre. Verser sur les lardons.',
                'Enfourner 30 à 35 minutes jusqu\'à ce que la quiche soit dorée.',
            ],
        ],
        [
            'title' => 'Curry de poulet au lait de coco',
            'description' => 'Doux et parfumé, prêt en 30 minutes.',
            'servings' => 4, 'prep' => 10, 'cook' => 20, 'rest' => null, 'difficulty' => 'easy',
            'tags' => ['Plat', 'Rapide'],
            'ingredients' => [
                [600, 'g', 'Blanc de poulet', 'en morceaux', null, false],
                [1, 'piece', 'Oignon', 'émincé', null, false],
                [2, 'gousse', 'Ail', null, null, false],
                [15, 'g', 'Gingembre frais', 'râpé', null, false],
                [2, 'cs', 'Curry en poudre', null, null, false],
                [400, 'ml', 'Lait de coco', null, null, false],
                [1, 'boite', 'Tomates concassées', null, null, false],
                [300, 'g', 'Riz basmati', null, null, false],
                [1, 'botte', 'Coriandre', null, null, true],
                [1, 'cs', 'Huile de tournesol', null, null, false],
            ],
            'steps' => [
                'Faire revenir l\'oignon, l\'ail et le gingembre dans l\'huile. Ajouter le curry et mélanger 1 minute.',
                'Ajouter le poulet et le faire dorer sur toutes les faces.',
                'Verser le lait de coco et les tomates, laisser mijoter 15 minutes.',
                'Pendant ce temps, cuire le riz. Servir parsemé de coriandre.',
            ],
        ],
        [
            'title' => 'Velouté de potiron',
            'description' => 'Une soupe d\'automne toute douce.',
            'servings' => 4, 'prep' => 15, 'cook' => 30, 'rest' => null, 'difficulty' => 'easy',
            'tags' => ['Soupe', 'Végétarien', 'Hiver'],
            'ingredients' => [
                [1, 'kg', 'Potiron', 'épluché, en cubes', null, false],
                [1, 'piece', 'Pomme de terre', null, null, false],
                [1, 'piece', 'Oignon', null, null, false],
                [1, 'piece', 'Bouillon de légumes', null, null, false],
                [10, 'cl', 'Crème liquide', null, null, true],
                [null, null, 'Sel', null, null, false],
                [1, 'pincee', 'Muscade', null, null, true],
            ],
            'steps' => [
                'Faire suer l\'oignon émincé dans une grande casserole.',
                'Ajouter le potiron, la pomme de terre, le bouillon et couvrir d\'eau. Cuire 25 minutes.',
                'Mixer finement, ajouter la crème, rectifier l\'assaisonnement.',
            ],
        ],
        [
            'title' => 'Salade grecque',
            'description' => 'Fraîche et croquante, parfaite l\'été.',
            'servings' => 2, 'prep' => 15, 'cook' => null, 'rest' => null, 'difficulty' => 'easy',
            'tags' => ['Salade', 'Végétarien', 'Été', 'Rapide'],
            'ingredients' => [
                [3, 'piece', 'Tomate', 'en quartiers', null, false],
                [1, 'piece', 'Concombre', 'en rondelles', null, false],
                [0.5, 'piece', 'Oignon rouge', 'émincé', null, false],
                [150, 'g', 'Feta', 'en cubes', null, false],
                [60, 'g', 'Olives noires', null, null, false],
                [3, 'cs', 'Huile d\'olive', null, null, false],
                [1, 'cc', 'Herbes de Provence', null, null, false],
            ],
            'steps' => [
                'Couper les légumes et les disposer dans un saladier.',
                'Ajouter la feta et les olives, arroser d\'huile d\'olive et parsemer d\'herbes.',
            ],
        ],
        [
            'title' => 'Gratin dauphinois',
            'description' => 'Pommes de terre fondantes, crème et une pointe d\'ail.',
            'servings' => 6, 'prep' => 20, 'cook' => 75, 'rest' => 10, 'difficulty' => 'medium',
            'tags' => ['Accompagnement', 'Végétarien', 'Hiver'],
            'ingredients' => [
                [1.2, 'kg', 'Pomme de terre', 'en fines rondelles', null, false],
                [50, 'cl', 'Crème liquide', null, null, false],
                [50, 'cl', 'Lait demi-écrémé', null, null, false],
                [2, 'gousse', 'Ail', null, null, false],
                [20, 'g', 'Beurre', null, null, false],
                [1, 'pincee', 'Muscade', null, null, false],
                [null, null, 'Sel', null, null, false],
            ],
            'steps' => [
                'Préchauffer le four à 160 °C. Frotter un plat à gratin avec l\'ail puis le beurrer.',
                'Porter le lait et la crème à frémissement avec l\'ail écrasé, le sel et la muscade.',
                'Disposer les pommes de terre en couches, couvrir du mélange lait-crème.',
                'Cuire 1 h 15 jusqu\'à ce que le dessus soit doré. Laisser reposer 10 minutes.',
            ],
        ],
        [
            'title' => 'Chili con carne',
            'servings' => 4, 'prep' => 15, 'cook' => 60, 'rest' => null, 'difficulty' => 'easy',
            'description' => 'Relevé juste ce qu\'il faut, idéal pour cuisiner à l\'avance.',
            'tags' => ['Plat', 'Viande', 'Batch cooking', 'Hiver'],
            'ingredients' => [
                [500, 'g', 'Bœuf haché', null, null, false],
                [2, 'boite', 'Haricots rouges en boîte', 'égouttés', null, false],
                [1, 'boite', 'Tomates concassées', null, null, false],
                [1, 'piece', 'Poivron rouge', 'en dés', null, false],
                [1, 'piece', 'Oignon', null, null, false],
                [2, 'gousse', 'Ail', null, null, false],
                [2, 'cc', 'Cumin', null, null, false],
                [1, 'cc', 'Paprika', null, null, false],
                [300, 'g', 'Riz basmati', null, null, true],
            ],
            'steps' => [
                'Faire revenir l\'oignon, l\'ail et le poivron. Ajouter la viande et la faire dorer.',
                'Ajouter les épices, les tomates et les haricots. Mijoter 50 minutes à couvert.',
                'Servir avec du riz.',
            ],
        ],
        [
            'title' => 'Crêpes',
            'description' => 'La pâte à crêpes inratable.',
            'servings' => 4, 'prep' => 10, 'cook' => 20, 'rest' => 60, 'difficulty' => 'easy',
            'tags' => ['Dessert', 'Petit-déjeuner', 'Végétarien'],
            'ingredients' => [
                [250, 'g', 'Farine', null, 'Pâte', false],
                [4, 'piece', 'Œuf', null, 'Pâte', false],
                [50, 'cl', 'Lait demi-écrémé', null, 'Pâte', false],
                [30, 'g', 'Beurre', 'fondu', 'Pâte', false],
                [1, 'sachet', 'Sucre vanillé', null, 'Pâte', true],
                [1, 'pincee', 'Sel', null, 'Pâte', false],
                [null, null, 'Confiture', null, 'Garniture', true],
                [null, null, 'Sucre', null, 'Garniture', true],
            ],
            'steps' => [
                'Mélanger la farine et le sel, creuser un puits et y casser les œufs.',
                'Incorporer le lait petit à petit en fouettant, puis le beurre fondu et le sucre vanillé.',
                'Laisser reposer la pâte 1 heure.',
                'Cuire les crêpes dans une poêle chaude légèrement beurrée, 1 minute de chaque côté.',
            ],
        ],
    ];

    public function run(): void
    {
        $ingredients = Ingredient::query()->pluck('id', 'search_name');
        $units = Unit::query()->pluck('id', 'code');
        $tags = Tag::query()->pluck('id', 'name');

        foreach (self::RECIPES as $data) {
            if (Recipe::where('title', $data['title'])->exists()) {
                continue;
            }

            $recipe = Recipe::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'servings' => $data['servings'],
                'prep_minutes' => $data['prep'],
                'cook_minutes' => $data['cook'],
                'rest_minutes' => $data['rest'],
                'difficulty' => $data['difficulty'],
            ]);

            $order = 1;
            foreach ($data['ingredients'] as [$quantity, $unitCode, $name, $preparation, $group, $optional]) {
                $ingredientId = $ingredients[NameNormalizer::normalize($name)] ?? null;

                if (! $ingredientId) {
                    continue; // ingrédient supprimé ou renommé entre-temps
                }

                $recipe->ingredients()->create([
                    'ingredient_id' => $ingredientId,
                    'quantity' => $quantity,
                    'unit_id' => $unitCode ? ($units[$unitCode] ?? null) : null,
                    'preparation' => $preparation,
                    'group_name' => $group,
                    'is_optional' => $optional,
                    'sort_order' => $order++,
                ]);
            }

            foreach ($data['steps'] as $index => $instruction) {
                $recipe->steps()->create(['position' => $index + 1, 'instruction' => $instruction]);
            }

            $recipe->tags()->sync(collect($data['tags'])->map(fn ($tag) => $tags[$tag] ?? null)->filter()->values());
        }
    }
}
