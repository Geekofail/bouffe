<?php

use App\Livewire\Recipes\Variants as VariantsPage;
use App\Livewire\Settings\Nutrition as NutritionPage;
use App\Models\Ingredient;
use App\Models\NutritionFood;
use App\Models\RecipeVariant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Nutrition\CiqualImporter;
use App\Services\Nutrition\NutritionCalculator;
use App\Services\Planning\WeekBalance;
use App\Services\Recipes\VariantService;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Nutrition (17.4, règle R19), équilibre de la semaine (17.5) et variantes (13.7).
 *
 * Aucune donnée nutritionnelle n'est livrée avec Bouffe : ces tests importent un extrait
 * de démonstration au format Ciqual, et vérifient surtout ce qui compte — qu'on n'affiche
 * rien quand on ne sait pas.
 */

require_once __DIR__.'/../Shopping/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00'));
    $this->actingAs(User::factory()->create(['name' => 'Pierre']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, IngredientSeeder::class]);

    $this->importer = app(CiqualImporter::class);
    $this->calculator = app(NutritionCalculator::class);
});

/* ================================================================ Import (17.4) */

test('l\'import lit un fichier au format Ciqual, quels que soient les intitulés', function () {
    $result = $this->importer->importCsv(base_path('tests/Fixtures/ciqual-extrait.csv'));

    expect($result['imported'])->toBe(5)
        ->and($result['skipped'])->toBe(1)              // code non numérique : ligne écartée
        ->and($result['missing'])->toBe([])
        ->and(NutritionFood::count())->toBe(5);

    $carotte = NutritionFood::firstWhere('ciqual_code', '20009');

    expect($carotte->name)->toBe('Carotte, crue')
        ->and($carotte->energy_kcal)->toBe(36.3)
        ->and($carotte->fibres)->toBe(2.8);
});

test('les écritures particulières de Ciqual sont comprises', function () {
    expect($this->importer->parseValue('12,4'))->toBe(12.4)
        ->and($this->importer->parseValue('traces'))->toBe(0.0)
        ->and($this->importer->parseValue('< 0,5'))->toBe(0.5)
        ->and($this->importer->parseValue('-'))->toBeNull()
        ->and($this->importer->parseValue(''))->toBeNull();
});

test('un fichier qui n\'est pas une table Ciqual est refusé clairement', function () {
    $path = base_path('tests/Fixtures/pas-ciqual.csv');
    file_put_contents($path, "nom;prix\nPain;1,20\n");

    expect(fn () => $this->importer->importCsv($path))
        ->toThrow(RuntimeException::class, 'ne ressemble pas à un export Ciqual');

    unlink($path);
});

test('les ingrédients sont rattachés automatiquement, sans écraser un choix fait à la main', function () {
    $this->importer->importCsv(base_path('tests/Fixtures/ciqual-extrait.csv'));

    $beurre = Ingredient::firstWhere('name', 'Beurre');
    $beurre->forceFill(['ciqual_code' => '20009'])->save();     // choix volontaire (carotte, absurde mais explicite)

    $this->importer->matchIngredients(false);

    expect($beurre->fresh()->ciqual_code)->toBe('20009')
        ->and(Ingredient::firstWhere('name', 'Œuf')->ciqual_code)->toBe('22000');
});

/* ================================================================ Calcul (R19) */

test('les valeurs par portion sont calculées sur le poids réel des ingrédients', function () {
    $this->importer->importCsv(base_path('tests/Fixtures/ciqual-extrait.csv'));
    $this->importer->matchIngredients();

    // 4 œufs (50 g pièce) et 20 g de beurre, pour 2 portions.
    $oeuf = Ingredient::firstWhere('name', 'Œuf');
    $oeuf->forceFill(['piece_weight_g' => 50])->save();

    $recipe = recipeWith('Omelette', 2, [[4, 'piece', 'Œuf', false], [20, 'g', 'Beurre', false]]);

    $result = $this->calculator->recipe($recipe);

    // 200 g d'œuf à 137 kcal/100 g + 20 g de beurre à 753 kcal/100 g = 274 + 150,6, / 2 portions
    expect($result['known'])->toBeTrue()
        ->and($result['coverage'])->toBe(100)
        ->and($result['values']['energy_kcal'])->toBe(212.3)
        ->and($this->calculator->format('energy_kcal', $result['values']['energy_kcal']))->toBe('212 kcal');
});

test('un volume est converti en grammes avec la densité de l\'ingrédient', function () {
    $this->importer->importCsv(base_path('tests/Fixtures/ciqual-extrait.csv'));
    $this->importer->matchIngredients();

    $lait = Ingredient::firstWhere('name', 'Lait demi-écrémé');
    $lait->forceFill(['density' => 1.03])->save();

    $grams = $this->calculator->grams(500, Unit::firstWhere('code', 'ml'), $lait->fresh());

    expect($grams)->toBe(515.0);
});

test('en dessous de 70 % de couverture, rien n\'est affiché', function () {
    $this->importer->importCsv(base_path('tests/Fixtures/ciqual-extrait.csv'));
    $this->importer->matchIngredients();

    // Le bœuf haché n'est pas dans l'extrait : la couverture s'effondre.
    $recipe = recipeWith('Steak beurre', 2, [[400, 'g', 'Bœuf haché', false], [20, 'g', 'Beurre', false]]);

    $result = $this->calculator->recipe($recipe);

    expect($result['known'])->toBeFalse()
        ->and($result['coverage'])->toBeLessThan(70)
        ->and($this->calculator->explain($result))->toContain('Données insuffisantes');
});

test('une ligne impossible à peser empêche l\'affichage plutôt que de fausser le calcul', function () {
    $this->importer->importCsv(base_path('tests/Fixtures/ciqual-extrait.csv'));
    $this->importer->matchIngredients();

    $recipe = recipeWith('Omelette au sel', 2, [[4, 'piece', 'Œuf', false], [null, null, 'Sel', false]]);
    Ingredient::firstWhere('name', 'Œuf')->forceFill(['piece_weight_g' => 50])->save();

    $result = $this->calculator->recipe($recipe);

    expect($result['unweighable'])->toBe(1)
        ->and($result['known'])->toBeFalse()
        ->and($this->calculator->explain($result))->toContain('sans quantité chiffrable');
});

test('sans table importée, la fiche recette le dit au lieu d\'afficher des zéros', function () {
    $recipe = recipeWith('Omelette', 2, [[4, 'piece', 'Œuf', false]]);
    $result = $this->calculator->recipe($recipe);

    expect($this->calculator->hasTable())->toBeFalse()
        ->and($result['known'])->toBeFalse()
        ->and($this->calculator->explain($result))->toContain('pas encore importée');
});

test('l\'écran Nutrition rattache et détache un aliment', function () {
    $this->importer->importCsv(base_path('tests/Fixtures/ciqual-extrait.csv'));

    $beurre = Ingredient::firstWhere('name', 'Beurre');

    Livewire::test(NutritionPage::class)
        ->call('edit', $beurre->id)
        ->call('attach', '19024');

    expect($beurre->fresh()->ciqual_code)->toBe('19024');

    Livewire::test(NutritionPage::class)->call('detach', $beurre->id);

    expect($beurre->fresh()->ciqual_code)->toBeNull();
});

/* ================================================================ Équilibre (17.5) */

test('l\'équilibre de la semaine classe les repas par famille', function () {
    $balance = app(WeekBalance::class);

    $poisson = recipeWith('Saumon au four', 2, [[300, 'g', 'Pavé de saumon', false], [400, 'g', 'Pomme de terre', false]]);
    $legumes = recipeWith('Soupe de potiron', 4, [[800, 'g', 'Potiron', false]]);

    expect($balance->familiesOf($poisson))->toContain('poisson')
        ->and($balance->familiesOf($poisson))->toContain('feculents')
        ->and($balance->familiesOf($legumes))->toContain('legumes')
        ->and($balance->familiesOf($legumes))->toContain('vegetarien')    // ni viande ni poisson
        ->and($balance->familiesOf($legumes))->not->toContain('viande');
});

/* ================================================================ Variantes (13.7) */

test('une variante remplace une ligne sans toucher à la recette', function () {
    $recipe = recipeWith('Carbonara', 4, [[200, 'g', 'Lardons', false], [400, 'g', 'Spaghetti', false]]);
    $service = app(VariantService::class);

    $variant = RecipeVariant::create(['recipe_id' => $recipe->id, 'name' => 'Végétarienne']);
    $line = $recipe->ingredients->firstWhere('ingredient.name', 'Lardons');
    $tofu = Ingredient::firstWhere('name', 'Tofu') ?? Ingredient::factory()->create(['name' => 'Tofu']);

    $service->setSwap($variant, $line->id, $tofu->id, 180, Unit::firstWhere('code', 'g')->id);

    $lines = $service->lines($recipe->fresh(), $variant->fresh());
    $swapped = $lines->firstWhere('swapped', true);

    expect($swapped['ingredient']->name)->toBe('Tofu')
        ->and((float) $swapped['quantity'])->toBe(180.0)
        ->and($lines)->toHaveCount(2)
        // La recette elle-même n'a pas bougé.
        ->and($recipe->fresh()->ingredients->pluck('ingredient.name'))->toContain('Lardons');
});

test('une variante peut retirer une ligne', function () {
    $recipe = recipeWith('Carbonara', 4, [[200, 'g', 'Lardons', false], [400, 'g', 'Spaghetti', false]]);
    $service = app(VariantService::class);

    $variant = RecipeVariant::create(['recipe_id' => $recipe->id, 'name' => 'Sans lardons']);
    $line = $recipe->ingredients->firstWhere('ingredient.name', 'Lardons');

    $service->setSwap($variant, $line->id, null, null, null);

    $lines = $service->lines($recipe->fresh(), $variant->fresh());

    expect($lines->firstWhere('removed', true)['line']->id)->toBe($line->id)
        ->and($lines->reject(fn ($row) => $row['removed']))->toHaveCount(1);
});

test('l\'écran des variantes crée une variante et enregistre un remplacement', function () {
    $recipe = recipeWith('Carbonara', 4, [[200, 'g', 'Lardons', false]]);
    $tofu = Ingredient::factory()->create(['name' => 'Tofu fumé']);
    $line = $recipe->ingredients->first();

    Livewire::test(VariantsPage::class, ['recipe' => $recipe])
        ->set('newName', 'Végétarienne')
        ->call('add')
        ->call('swap', $line->id, (string) $tofu->id);

    $variant = RecipeVariant::firstWhere('name', 'Végétarienne');

    expect($variant)->not->toBeNull()
        ->and($variant->swaps()->first()->ingredient_id)->toBe($tofu->id);
});
