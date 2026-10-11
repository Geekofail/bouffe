<?php

use App\Livewire\Recipes\Index as RecipesIndex;
use App\Livewire\Settings\Nutrition as NutritionPage;
use App\Models\Ingredient;
use App\Models\MealSlot;
use App\Models\NutritionFood;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Nutrition\CiqualCatalogue;
use App\Services\Nutrition\CiqualImporter;
use App\Services\Nutrition\MealBenchmarks;
use App\Services\Nutrition\NutriScore;
use App\Services\Nutrition\RecipeNutrition;
use App\Services\Nutrition\ReferenceData;
use App\Services\Planning\WeekBalance;
use App\Services\Planning\WeekFiller;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Lot 43 — catalogue Ciqual, Nutri-Score estimé, repères par portion, équilibre de la semaine.
 *
 * Les valeurs viennent de la table Ciqual 2025 (Anses) : extraits réels dans tests/Fixtures,
 * table complète et catalogue livrés dans database/data.
 */

require_once __DIR__.'/../Shopping/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-25 10:00'));
    $this->actingAs(User::factory()->create(['name' => 'Pierre']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, IngredientSeeder::class]);

    $this->importer = app(CiqualImporter::class);
    $this->score = app(NutriScore::class);
});

/* ================================================================ Import de la table 2025 */

test('la table Ciqual 2025 est lue avec les bonnes colonnes : kcal (et non kJ), fibres alimentaires, sous-groupe', function () {
    $result = $this->importer->importCsv(base_path('tests/Fixtures/ciqual-2025-extrait.csv'));

    $carotte = NutritionFood::firstWhere('ciqual_code', '20009');

    expect($result['imported'])->toBe(8)
        ->and($result['missing'])->toBe([])
        ->and($carotte->energy_kcal)->toBe(30.2)            // la colonne kJ voisine dit 127
        ->and($carotte->fibres)->toBe(2.9)                  // et non « Énergie, … avec fibres »
        ->and($carotte->food_subgroup)->toBe('légumes')
        ->and($carotte->isFruitOrVegetable())->toBeTrue()
        ->and(NutritionFood::firstWhere('ciqual_code', '9810')->isFruitOrVegetable())->toBeFalse();
});

test('la correspondance par le nom respecte les mots : « Poire » ne donne plus « Poireau »', function () {
    NutritionFood::create(['ciqual_code' => '20026', 'name' => 'Poireau, cru', 'food_subgroup' => 'légumes', 'energy_kcal' => 29]);
    NutritionFood::create(['ciqual_code' => '90001', 'name' => 'Pâte sablée, préemballée, crue', 'energy_kcal' => 470]);
    NutritionFood::create(['ciqual_code' => '9810', 'name' => 'Pâtes sèches, standard, crues', 'energy_kcal' => 364]);
    NutritionFood::create(['ciqual_code' => '20385', 'name' => 'Tomate sans précision, crue (aliment moyen)', 'energy_kcal' => 19]);
    NutritionFood::create(['ciqual_code' => '20090', 'name' => 'Tomate verte, crue', 'energy_kcal' => 23]);

    expect($this->importer->suggestFor(Ingredient::firstWhere('name', 'Poire')))->toBeNull()          // pas « Poireau »
        ->and($this->importer->suggestFor(Ingredient::firstWhere('name', 'Pâtes'))->ciqual_code)->toBe('9810')   // le catalogue, pas « Pâte sablée »
        ->and($this->importer->suggestFor(Ingredient::firstWhere('name', 'Tomate'))->ciqual_code)->toBe('20385');
});

test('un nouvel ingrédient reçoit son aliment tout de suite, d\'après le catalogue', function () {
    $this->importer->importCsv(base_path('tests/Fixtures/ciqual-2025-extrait.csv'));

    // « Piments » (pluriel) : le catalogue connaît « Piment » → 20151.
    $piments = Ingredient::factory()->create(['name' => 'Piments']);

    expect($piments->fresh()->ciqual_code)->toBe('20151')
        ->and($piments->fresh()->ciqual_source)->toBe('auto');
});

/* ================================================================ Catalogue */

test('le catalogue rattache les ingrédients existants, ajoute les manquants et respecte les choix faits à la main', function () {
    $beurre = Ingredient::firstWhere('name', 'Beurre');
    $beurre->forceFill(['ciqual_code' => '19024', 'ciqual_source' => 'manuel'])->save();
    $huile = Ingredient::firstWhere('name', 'Huile d\'olive');
    $huile->forceFill(['density' => null])->save();

    $result = app(CiqualCatalogue::class)->apply();

    expect($result['created'])->toBeGreaterThan(900)
        ->and($result['kept'])->toBe(1)
        ->and($beurre->fresh()->ciqual_code)->toBe('19024')                       // choix conservé
        ->and(Ingredient::firstWhere('name', 'Œuf')->ciqual_code)->toBe('22000')
        ->and(Ingredient::firstWhere('name', 'Œuf')->ciqual_source)->toBe('catalogue')
        ->and((float) $huile->fresh()->density)->toBe(0.92)                         // complété car absent
        ->and(Ingredient::firstWhere('name', 'Huile d\'olive')->is_staple)->toBeTrue()
        ->and(Ingredient::where('name', 'Lentilles en boîte')->exists())->toBeTrue()
        ->and(Ingredient::whereNull('ciqual_code')->count())->toBe(0);
});

test('un ingrédient du catalogue supprimé n\'est pas recréé par la version suivante', function () {
    $first = app(CiqualCatalogue::class)->apply();
    Ingredient::where('name', 'Salicorne')->delete();

    $second = app(CiqualCatalogue::class)->apply($first['names']);

    expect($second['created'])->toBe(0)
        ->and(Ingredient::where('name', 'Salicorne')->exists())->toBeFalse();
});

test('les données de référence ne s\'appliquent qu\'une fois par version', function () {
    $reference = app(ReferenceData::class);

    expect($reference->pending())->toHaveKeys(['ciqual', 'catalogue']);

    $result = $reference->sync(force: true);

    expect($result['skipped'])->toBeFalse()
        ->and($result['foods'])->toBe(3484)
        ->and(NutritionFood::firstWhere('ciqual_code', '20009')->energy_kcal)->toBe(30.2)
        ->and($reference->pending())->toBe([])
        ->and(DB::table('reference_versions')->count())->toBe(2);
})->group('lent');

/* ================================================================ Nutri-Score estimé */

test('le Nutri-Score suit l\'algorithme : points à limiter moins points favorables', function () {
    // 100 kcal (418 kJ : 1 pt), sel 0,3 g (1 pt) ; protéines 3 g (1), fibres 3,5 g (1), 85 % de légumes (5).
    $good = $this->score->compute(['energy_kcal' => 100, 'sugars' => 2, 'saturated_fat' => 0.5, 'salt' => 0.3, 'proteins' => 3, 'fibres' => 3.5], 85);

    expect($good['negative'])->toBe(2)
        ->and($good['positive'])->toBe(7)
        ->and($good['score'])->toBe(-5)
        ->and($good['letter'])->toBe('A');

    // Très gras, sucré, salé : les protéines ne comptent plus (11 points ou plus, peu de légumes).
    $bad = $this->score->compute(['energy_kcal' => 500, 'sugars' => 40, 'saturated_fat' => 12, 'salt' => 1, 'proteins' => 6, 'fibres' => 1], 0);

    expect($bad['points'])->toMatchArray(['energy' => 6, 'sugars' => 11, 'saturated_fat' => 10, 'salt' => 4, 'proteins' => 2])
        ->and($bad['proteins_counted'])->toBeFalse()
        ->and($bad['score'])->toBe(31)
        ->and($bad['letter'])->toBe('E');
});

test('les lettres suivent les seuils des aliments généraux', function () {
    expect(collect([-3, 0, 1, 2, 3, 10, 11, 18, 19])->map(fn ($score) => $this->score->letter($score))->all())
        ->toBe(['A', 'A', 'B', 'B', 'C', 'C', 'D', 'D', 'E'])
        ->and(NutriScore::fromRank(2.4))->toBe('B')
        ->and(NutriScore::leans([4, 4, 3]))->toBeTrue()
        ->and(NutriScore::leans([5]))->toBeFalse();     // un seul repas ne fait pas une tendance
});

test('les repères comparent une portion à un repas type', function () {
    $benchmarks = app(MealBenchmarks::class);
    $flags = collect($benchmarks->flags(['energy_kcal' => 950, 'salt' => 2.4, 'saturated_fat' => 4, 'sugars' => 10, 'fibres' => 7, 'proteins' => 25]))->pluck('key')->all();

    expect($flags)->toBe(['hearty', 'salt', 'fibres', 'proteins'])
        ->and($benchmarks->meal('salt'))->toBe(2.0)
        ->and($benchmarks->share('energy_kcal', 667))->toBe(100);
});

/* ================================================================ Calcul mis de côté */

test('la recette garde sa lettre, recalculée quand ses lignes changent', function () {
    $this->importer->importCsv(base_path('tests/Fixtures/ciqual-2025-extrait.csv'));
    $this->importer->matchIngredients();
    Ingredient::firstWhere('name', 'Carotte')->forceFill(['ciqual_code' => '20009'])->save();

    $recipe = recipeWith('Carottes râpées', 2, [[400, 'g', 'Carotte', false], [10, 'g', 'Citron', false]]);
    $data = app(RecipeNutrition::class)->refresh($recipe);
    $recipe->refresh();

    expect($data['known'])->toBeTrue()
        ->and($data['fruit_vegetable'])->toBe(100)
        ->and($recipe->nutri_score)->toBe('A')
        ->and($recipe->nutrition['per_portion']['energy_kcal'])->toBe(61.6)
        ->and($recipe->nutrition_at)->not->toBeNull();

    // Une ligne de plus : le calcul est périmé, puis refait par les tâches régulières.
    $recipe->ingredients()->create(['ingredient_id' => Ingredient::firstWhere('name', 'Beurre')->id, 'quantity' => 100, 'unit_id' => null, 'sort_order' => 3]);

    expect($recipe->fresh()->nutrition_at)->toBeNull()
        ->and(app(RecipeNutrition::class)->refreshStale())->toBe(1)
        ->and($recipe->fresh()->nutri_score)->not->toBe('A');
});

test('la liste des recettes filtre et trie par Nutri-Score estimé', function () {
    foreach (['Taboule du jardin' => 'A', 'Gratin du soir' => 'D', 'Minestrone' => 'B', 'Plat mystere' => null] as $title => $letter) {
        Recipe::factory()->create(['title' => $title])->forceFill(['nutri_score' => $letter])->saveQuietly();
    }

    Livewire::test(RecipesIndex::class)
        ->set('nutri', 'AB')
        ->assertSee('Taboule du jardin')->assertSee('Minestrone')->assertDontSee('Gratin du soir')
        ->set('nutri', '')
        ->set('sort', 'nutri')
        ->assertSeeInOrder(['Taboule du jardin', 'Minestrone', 'Gratin du soir', 'Plat mystere']);
});

test('la fiche recette montre la lettre, les repères et le détail du calcul', function () {
    $this->importer->importCsv(base_path('tests/Fixtures/ciqual-2025-extrait.csv'));
    Ingredient::firstWhere('name', 'Carotte')->forceFill(['ciqual_code' => '20009'])->save();
    $recipe = recipeWith('Carottes nature', 2, [[400, 'g', 'Carotte', false], [null, null, 'Sel', false]]);

    $this->get(route('recipes.show', $recipe))
        ->assertOk()
        ->assertSee('data-nutri-score="A"', false)
        ->assertSee('Pourquoi « A » ?')
        ->assertSee('Non compté (sans quantité) : sel.')
        ->assertSee('Source : Anses, Table de composition nutritionnelle des aliments Ciqual 2025.');
});

/* ================================================================ Semaine */

test('l\'équilibre de la semaine donne le Nutri-Score moyen, et « Remplir la semaine » rééquilibre', function () {
    $dinner = MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 2]);
    $monday = Carbon::parse('2026-11-23');

    foreach (['Frites maison' => 'E', 'Raclette' => 'D'] as $title => $letter) {
        $recipe = recipeWith($title, 2, [[200, 'g', 'Pomme de terre', false]]);
        $recipe->forceFill(['nutri_score' => $letter, 'nutrition_at' => now(), 'nutrition' => [
            'known' => true, 'score' => ['letter' => $letter], 'per_portion' => ['salt' => 2.5, 'fibres' => 3, 'energy_kcal' => 800],
        ]])->saveQuietly();
        PlannedMeal::factory()->create(['date' => $monday->copy()->addDays($letter === 'E' ? 0 : 1)->toDateString(), 'meal_slot_id' => $dinner->id, 'recipe_id' => $recipe->id]);
    }

    $balance = app(WeekBalance::class)->week($monday, $monday->copy()->addDays(6));

    expect($balance['nutrition']['scored'])->toBe(2)
        ->and($balance['nutrition']['letter'])->toBe('E')            // (4 + 5) / 2 = 4,5 → E
        ->and($balance['nutrition']['leans'])->toBeTrue()
        ->and($balance['nutrition']['salt'])->toBe(2.5);

    $light = recipeWith('Velouté de légumes', 2, [[400, 'g', 'Carotte', false]]);
    $light->forceFill(['nutri_score' => 'A'])->saveQuietly();
    $heavy = recipeWith('Tartiflette', 2, [[400, 'g', 'Pomme de terre', false]]);
    $heavy->forceFill(['nutri_score' => 'E'])->saveQuietly();

    $ideas = collect(app(WeekFiller::class)->ideasFor('2026-11-26', $dinner, 2))->keyBy('title');

    expect($ideas['Velouté de légumes']['reasons'])->toContain('équilibre de la semaine')
        ->and($ideas['Velouté de légumes']['score'])->toBeGreaterThan($ideas['Tartiflette']['score']);
});

test('Paramètres → Nutrition cite la source et dit d\'où vient chaque association', function () {
    $this->importer->importCsv(base_path('tests/Fixtures/ciqual-2025-extrait.csv'));
    Ingredient::firstWhere('name', 'Œuf')->forceFill(['ciqual_code' => '22000', 'ciqual_source' => 'catalogue'])->save();

    Livewire::test(NutritionPage::class)
        ->set('filter', 'associes')
        ->assertSee('Source : Anses')
        ->assertSee('Licence ouverte Etalab')
        ->assertSee('catalogue');
});
