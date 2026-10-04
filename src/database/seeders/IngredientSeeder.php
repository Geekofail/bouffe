<?php

namespace Database\Seeders;

use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\Unit;
use App\Support\NameNormalizer;
use Illuminate\Database\Seeder;

/**
 * ~150 ingrédients courants. Ré-exécutable : un ingrédient déjà présent (même renommé
 * au pluriel ou sans accent) n'est pas recréé.
 */
class IngredientSeeder extends Seeder
{
    /**
     * [nom, pluriel, rayon (clé AisleSeeder), unité par défaut, poids d'une pièce en g, produit de base]
     */
    public const INGREDIENTS = [
        // Fruits & légumes
        ['Ail', null, 'fruits', 'gousse', 5, false],
        ['Oignon', 'oignons', 'fruits', 'piece', 150, false],
        ['Oignon rouge', 'oignons rouges', 'fruits', 'piece', 150, false],
        ['Échalote', 'échalotes', 'fruits', 'piece', 30, false],
        ['Tomate', 'tomates', 'fruits', 'piece', 120, false],
        ['Tomate cerise', 'tomates cerises', 'fruits', 'g', 15, false],
        ['Carotte', 'carottes', 'fruits', 'piece', 100, false],
        ['Pomme de terre', 'pommes de terre', 'fruits', 'g', 150, false],
        ['Patate douce', 'patates douces', 'fruits', 'piece', 300, false],
        ['Courgette', 'courgettes', 'fruits', 'piece', 250, false],
        ['Aubergine', 'aubergines', 'fruits', 'piece', 300, false],
        ['Poivron rouge', 'poivrons rouges', 'fruits', 'piece', 180, false],
        ['Poivron vert', 'poivrons verts', 'fruits', 'piece', 180, false],
        ['Poireau', 'poireaux', 'fruits', 'piece', 250, false],
        ['Céleri branche', null, 'fruits', 'piece', 40, false],
        ['Champignon de Paris', 'champignons de Paris', 'fruits', 'g', 20, false],
        ['Brocoli', 'brocolis', 'fruits', 'piece', 400, false],
        ['Chou-fleur', 'choux-fleurs', 'fruits', 'piece', 800, false],
        ['Épinards frais', null, 'fruits', 'g', null, false],
        ['Salade verte', 'salades vertes', 'fruits', 'piece', 300, false],
        ['Concombre', 'concombres', 'fruits', 'piece', 300, false],
        ['Avocat', 'avocats', 'fruits', 'piece', 200, false],
        ['Haricots verts', null, 'fruits', 'g', null, false],
        ['Petits pois frais', null, 'fruits', 'g', null, false],
        ['Butternut', 'butternuts', 'fruits', 'piece', 1000, false],
        ['Potiron', 'potirons', 'fruits', 'g', null, false],
        ['Navet', 'navets', 'fruits', 'piece', 150, false],
        ['Betterave cuite', 'betteraves cuites', 'fruits', 'piece', 150, false],
        ['Radis', null, 'fruits', 'botte', null, false],
        ['Fenouil', 'fenouils', 'fruits', 'piece', 300, false],
        ['Gingembre frais', null, 'fruits', 'g', null, false],
        ['Citron', 'citrons', 'fruits', 'piece', 120, false],
        ['Citron vert', 'citrons verts', 'fruits', 'piece', 70, false],
        ['Orange', 'oranges', 'fruits', 'piece', 200, false],
        ['Pomme', 'pommes', 'fruits', 'piece', 180, false],
        ['Poire', 'poires', 'fruits', 'piece', 180, false],
        ['Banane', 'bananes', 'fruits', 'piece', 120, false],
        ['Fraise', 'fraises', 'fruits', 'g', null, false],
        ['Framboise', 'framboises', 'fruits', 'g', null, false],
        ['Persil', null, 'fruits', 'botte', null, false],
        ['Coriandre', null, 'fruits', 'botte', null, false],
        ['Ciboulette', null, 'fruits', 'botte', null, false],
        ['Basilic', null, 'fruits', 'botte', null, false],
        ['Menthe', null, 'fruits', 'botte', null, false],
        ['Thym frais', null, 'fruits', 'brin', null, false],
        ['Romarin frais', null, 'fruits', 'brin', null, false],

        // Boulangerie
        ['Baguette', 'baguettes', 'bakery', 'piece', 250, false],
        ['Pain de mie', null, 'bakery', 'tranche', 25, false],
        ['Pain complet', null, 'bakery', 'piece', 500, false],
        ['Pain burger', 'pains burger', 'bakery', 'piece', 60, false],
        ['Tortilla', 'tortillas', 'bakery', 'piece', 40, false],

        // Boucherie & volaille
        ['Blanc de poulet', 'blancs de poulet', 'butcher', 'piece', 150, false],
        ['Cuisse de poulet', 'cuisses de poulet', 'butcher', 'piece', 250, false],
        ['Poulet entier', 'poulets entiers', 'butcher', 'piece', 1500, false],
        ['Bœuf haché', null, 'butcher', 'g', null, false],
        ['Steak haché', 'steaks hachés', 'butcher', 'piece', 125, false],
        ['Bœuf à braiser', null, 'butcher', 'g', null, false],
        ['Escalope de dinde', 'escalopes de dinde', 'butcher', 'piece', 130, false],
        ['Rôti de porc', 'rôtis de porc', 'butcher', 'g', null, false],
        ['Côte de porc', 'côtes de porc', 'butcher', 'piece', 200, false],
        ['Saucisse', 'saucisses', 'butcher', 'piece', 100, false],
        ['Agneau (épaule)', null, 'butcher', 'g', null, false],

        // Poissonnerie
        ['Pavé de saumon', 'pavés de saumon', 'fish', 'piece', 130, false],
        ['Filet de cabillaud', 'filets de cabillaud', 'fish', 'piece', 140, false],
        ['Crevette', 'crevettes', 'fish', 'g', null, false],
        ['Moules', null, 'fish', 'kg', null, false],
        ['Saumon fumé', null, 'fish', 'tranche', 25, false],

        // Charcuterie & traiteur
        ['Lardons', null, 'deli', 'g', null, false],
        ['Jambon blanc', null, 'deli', 'tranche', 45, false],
        ['Jambon cru', null, 'deli', 'tranche', 15, false],
        ['Chorizo', null, 'deli', 'g', null, false],
        ['Pâte feuilletée', 'pâtes feuilletées', 'deli', 'piece', 230, false],
        ['Pâte brisée', 'pâtes brisées', 'deli', 'piece', 230, false],
        ['Pâte à pizza', 'pâtes à pizza', 'deli', 'piece', 260, false],
        ['Gnocchis', null, 'deli', 'g', null, false],
        ['Tofu', null, 'deli', 'g', null, false],

        // Crèmerie & œufs
        ['Œuf', 'œufs', 'dairy', 'piece', 55, false],
        ['Lait demi-écrémé', null, 'dairy', 'l', null, false],
        ['Beurre', null, 'dairy', 'g', null, false],
        ['Crème fraîche épaisse', null, 'dairy', 'cl', null, false],
        ['Crème liquide', null, 'dairy', 'cl', null, false],
        ['Yaourt nature', 'yaourts nature', 'dairy', 'piece', 125, false],
        ['Fromage blanc', null, 'dairy', 'g', null, false],
        ['Lait de coco', null, 'dairy', 'ml', null, false],

        // Fromages
        ['Emmental râpé', null, 'cheese', 'g', null, false],
        ['Parmesan', null, 'cheese', 'g', null, false],
        ['Mozzarella', 'mozzarellas', 'cheese', 'piece', 125, false],
        ['Feta', null, 'cheese', 'g', null, false],
        ['Chèvre frais', null, 'cheese', 'g', null, false],
        ['Comté', null, 'cheese', 'g', null, false],
        ['Reblochon', 'reblochons', 'cheese', 'piece', 450, false],
        ['Ricotta', null, 'cheese', 'g', null, false],

        // Épicerie salée
        ['Pâtes', null, 'savory', 'g', null, false],
        ['Spaghetti', null, 'savory', 'g', null, false],
        ['Lasagnes (feuilles)', null, 'savory', 'g', null, false],
        ['Riz basmati', null, 'savory', 'g', null, false],
        ['Riz arborio', null, 'savory', 'g', null, false],
        ['Semoule', null, 'savory', 'g', null, false],
        ['Quinoa', null, 'savory', 'g', null, false],
        ['Lentilles corail', null, 'savory', 'g', null, false],
        ['Lentilles vertes', null, 'savory', 'g', null, false],
        ['Pois chiches secs', null, 'savory', 'g', null, false],
        ['Farine', null, 'savory', 'g', null, true],
        ['Chapelure', null, 'savory', 'g', null, false],
        ['Bouillon de volaille', null, 'savory', 'piece', 10, false],
        ['Bouillon de légumes', null, 'savory', 'piece', 10, false],
        ['Huile d\'olive', null, 'savory', 'cs', null, true],
        ['Huile de tournesol', null, 'savory', 'cs', null, true],
        ['Vinaigre balsamique', null, 'savory', 'cs', null, true],
        ['Vinaigre de vin', null, 'savory', 'cs', null, true],

        // Épicerie sucrée
        ['Sucre', null, 'sweet', 'g', null, true],
        ['Sucre roux', null, 'sweet', 'g', null, false],
        ['Sucre vanillé', null, 'sweet', 'sachet', 7.5, false],
        ['Levure chimique', null, 'sweet', 'sachet', 11, false],
        ['Chocolat noir', null, 'sweet', 'g', null, false],
        ['Miel', null, 'sweet', 'cs', null, false],
        ['Confiture', 'confitures', 'sweet', 'pot', null, false],
        ['Flocons d\'avoine', null, 'sweet', 'g', null, false],
        ['Poudre d\'amande', null, 'sweet', 'g', null, false],
        ['Cacao en poudre', null, 'sweet', 'g', null, false],

        // Conserves & bocaux
        ['Tomates concassées', null, 'cans', 'boite', null, false],
        ['Concentré de tomate', null, 'cans', 'cs', null, false],
        ['Coulis de tomate', null, 'cans', 'ml', null, false],
        ['Pois chiches en boîte', null, 'cans', 'boite', null, false],
        ['Haricots rouges en boîte', null, 'cans', 'boite', null, false],
        ['Maïs en boîte', null, 'cans', 'boite', null, false],
        ['Thon au naturel', null, 'cans', 'boite', null, false],
        ['Olives noires', null, 'cans', 'g', null, false],
        ['Cornichons', null, 'cans', 'pot', null, false],
        ['Câpres', null, 'cans', 'cs', null, false],

        // Condiments & épices
        ['Sel', null, 'spices', 'pincee', null, true],
        ['Poivre', null, 'spices', 'pincee', null, true],
        ['Moutarde', null, 'spices', 'cs', null, true],
        ['Mayonnaise', null, 'spices', 'cs', null, false],
        ['Ketchup', null, 'spices', 'cs', null, false],
        ['Sauce soja', null, 'spices', 'cs', null, true],
        ['Paprika', null, 'spices', 'cc', null, true],
        ['Cumin', null, 'spices', 'cc', null, true],
        ['Curry en poudre', null, 'spices', 'cc', null, true],
        ['Curcuma', null, 'spices', 'cc', null, true],
        ['Piment d\'Espelette', null, 'spices', 'pincee', null, true],
        ['Herbes de Provence', null, 'spices', 'cc', null, true],
        ['Thym séché', null, 'spices', 'cc', null, true],
        ['Laurier', null, 'spices', 'piece', null, true],
        ['Muscade', null, 'spices', 'pincee', null, true],
        ['Cannelle', null, 'spices', 'cc', null, true],
        ['Vanille (gousse)', null, 'spices', 'piece', null, false],

        // Surgelés
        ['Petits pois surgelés', null, 'frozen', 'g', null, false],
        ['Épinards surgelés', null, 'frozen', 'g', null, false],
        ['Poêlée de légumes surgelée', null, 'frozen', 'g', null, false],
        ['Frites surgelées', null, 'frozen', 'g', null, false],

        // Boissons
        ['Vin blanc', null, 'drinks', 'cl', null, false],
        ['Vin rouge', null, 'drinks', 'cl', null, false],
        ['Bière', 'bières', 'drinks', 'cl', null, false],
    ];

    public function run(): void
    {
        $aisles = collect(AisleSeeder::AISLES)->map(
            fn (array $aisle) => Aisle::firstOrCreate(['name' => $aisle[0]], ['color' => $aisle[1]])
        );
        $units = Unit::query()->pluck('id', 'code');
        $existing = Ingredient::query()->pluck('search_name')->flip();

        foreach (self::INGREDIENTS as [$name, $plural, $aisleKey, $unitCode, $pieceWeight, $staple]) {
            if ($existing->has(NameNormalizer::normalize($name))) {
                continue;
            }

            Ingredient::create([
                'name' => $name,
                'name_plural' => $plural,
                'aisle_id' => $aisles[$aisleKey]->id,
                'default_unit_id' => $units[$unitCode] ?? null,
                'piece_weight_g' => $pieceWeight,
                'is_staple' => $staple,
            ]);
        }
    }
}
