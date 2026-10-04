<?php

use App\Livewire\Recipes\Edit as RecipeEdit;
use App\Livewire\Recipes\Import;
use App\Livewire\Recipes\Index as RecipeIndex;
use App\Models\Ingredient;
use App\Models\MealSlot;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Planning\WeekPlanner;
use App\Services\Recipes\RecipeArchive;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['name' => 'Pierre']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, IngredientSeeder::class]);
});

function recipePage(): string
{
    return '<!doctype html><html><head><script type="application/ld+json">'.json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Recipe',
        'name' => 'Gratin dauphinois',
        'recipeYield' => '6 personnes',
        'prepTime' => 'PT20M',
        'cookTime' => 'PT1H15M',
        'recipeIngredient' => ['1,5 kg de pommes de terre', '50 cl de crème liquide', '3 boutons de guêtre'],
        'recipeInstructions' => [
            ['@type' => 'HowToStep', 'text' => 'Éplucher les pommes de terre.'],
            ['@type' => 'HowToStep', 'text' => 'Enfourner 1 h 15.'],
        ],
    ], JSON_UNESCAPED_UNICODE).'</script></head><body></body></html>';
}

/* ================================================================ 13.1 — écran d'import */

test('l\'import depuis une adresse propose une ébauche relue puis enregistrée', function () {
    Http::fake(['exemple.test/*' => Http::response(recipePage())]);

    $component = Livewire::test(Import::class)
        ->set('url', 'https://exemple.test/gratin')
        ->call('fetch')
        ->assertSet('reviewing', true)
        ->assertSet('form.title', 'Gratin dauphinois')
        ->assertSet('form.servings', 6)
        ->assertSet('form.prep_minutes', 20)
        ->assertSet('form.cook_minutes', 75);

    // Un ingrédient inconnu est signalé et attend son rayon.
    expect($component->get('unknownCount'))->toBe(1);

    $component->call('save')->assertHasNoErrors()->assertRedirect();

    $recipe = Recipe::firstWhere('title', 'Gratin dauphinois');

    expect($recipe)->not->toBeNull()
        ->and($recipe->is_to_test)->toBeTrue()
        ->and($recipe->source)->toBe('https://exemple.test/gratin')
        ->and($recipe->ingredients)->toHaveCount(3)
        ->and($recipe->steps)->toHaveCount(2);

    expect(Ingredient::findByName('Bouton de guêtre'))->not->toBeNull();
});

test('une adresse illisible affiche un message et ne bascule pas en relecture', function () {
    Http::fake(['*' => Http::response('<html><body>rien</body></html>')]);

    Livewire::test(Import::class)
        ->set('url', 'https://exemple.test/rien')
        ->call('fetch')
        ->assertHasErrors('url')
        ->assertSet('reviewing', false);
});

test('une adresse vide ou invalide est refusée avant tout appel réseau', function () {
    Http::fake();

    Livewire::test(Import::class)->set('url', 'bonjour')->call('fetch')->assertHasErrors('url');
    Http::assertNothingSent();
});

test('un doublon bloque l\'enregistrement jusqu\'à confirmation', function () {
    Recipe::create(['title' => 'Gratin dauphinois', 'servings' => 4]);
    Http::fake(['*' => Http::response(recipePage())]);

    $component = Livewire::test(Import::class)
        ->set('url', 'https://exemple.test/gratin')
        ->call('fetch')
        ->assertSee('est déjà dans votre carnet', false)
        ->call('save')
        ->assertHasErrors('form.title');

    expect(Recipe::count())->toBe(1);

    // « Créer quand même » : le titre doit alors être modifié (les titres sont uniques).
    $component->set('ignoreExisting', true)
        ->set('form.title', 'Gratin dauphinois du dimanche')
        ->call('save')
        ->assertHasNoErrors();

    expect(Recipe::count())->toBe(2);
});

/* ================================================================ 13.2 — collage */

test('le collage de texte remplit la relecture', function () {
    Livewire::test(Import::class)
        ->set('tab', 'texte')
        ->set('text', "Omelette aux herbes\nPour 2 personnes\n\nIngrédients\n4 œufs\n20 g de beurre\n\nPréparation\nBattre les œufs.\nCuire à feu doux.")
        ->call('analyse')
        ->assertSet('reviewing', true)
        ->assertSet('form.title', 'Omelette aux herbes')
        ->assertSet('form.servings', 2)
        ->assertCount('form.ingredients', 2)
        ->assertCount('form.steps', 2);
});

/* ================================================================ 13.3 — saisie rapide */

test('la saisie rapide transforme un bloc de lignes en ingrédients', function () {
    Livewire::test(RecipeEdit::class)
        ->set('quickLines', "200 g de farine\n3 œufs\n1 c. à soupe d'huile d'olive")
        ->call('parseQuickLines')
        ->assertSet('quickOpen', false)
        ->assertSet('quickLines', '')
        ->assertCount('form.ingredients', 3)
        ->assertSet('form.ingredients.0.name', 'Farine')
        ->assertSet('form.ingredients.0.quantity', '200')
        ->assertSet('form.ingredients.1.name', 'Œuf');
});

/* ================================================================ 13.13 — à tester */

test('une recette à tester ne l\'est plus une fois cuisinée', function () {
    $slot = MealSlot::factory()->create();
    $recipe = Recipe::create(['title' => 'Tarte aux poireaux', 'servings' => 4, 'is_to_test' => true]);

    $planner = app(WeekPlanner::class);
    $meal = $planner->addRecipe(now()->toDateString(), $slot, $recipe->id, 4);
    $planner->toggleCooked($meal);

    expect($recipe->fresh()->is_to_test)->toBeFalse();
});

test('le filtre « à tester » du carnet ne montre que celles-là', function () {
    Recipe::create(['title' => 'Soupe à tester', 'servings' => 2, 'is_to_test' => true]);
    Recipe::create(['title' => 'Soupe connue', 'servings' => 2]);

    Livewire::test(RecipeIndex::class)
        ->assertSee('Soupe connue')
        ->set('toTestOnly', true)
        ->assertSee('Soupe à tester')
        ->assertDontSee('Soupe connue');
});

/* ================================================================ 13.10 — export / import JSON */

test('l\'export contient les recettes du carnet', function () {
    $recipe = Recipe::create(['title' => 'Riz au lait', 'servings' => 4, 'notes' => 'Du bon riz rond.']);
    $recipe->steps()->create(['position' => 1, 'instruction' => 'Cuire le riz dans le lait.']);
    $recipe->ingredients()->create([
        'ingredient_id' => Ingredient::findByName('Lait')?->id ?? Ingredient::first()->id,
        'quantity' => 1, 'sort_order' => 1,
    ]);

    $response = $this->get(route('recipes.export'))->assertOk();
    $payload = json_decode($response->streamedContent(), true);

    expect($payload['format'])->toBe(RecipeArchive::FORMAT)
        ->and($payload['recipes'])->toHaveCount(1)
        ->and($payload['recipes'][0]['title'])->toBe('Riz au lait')
        ->and($payload['recipes'][0]['steps'][0]['instruction'])->toBe('Cuire le riz dans le lait.');
});

test('un export peut être réimporté, les doublons étant ignorés', function () {
    $recipe = Recipe::create(['title' => 'Riz au lait', 'servings' => 4]);
    $recipe->steps()->create(['position' => 1, 'group_name' => 'Le riz', 'instruction' => 'Cuire le riz dans le lait.']);

    $archive = app(RecipeArchive::class);
    $payload = $archive->export();
    $payload['recipes'][] = array_merge($payload['recipes'][0], ['title' => 'Riz au lait de coco']);

    $report = $archive->import(json_encode($payload));

    expect($report['created'])->toBe(['Riz au lait de coco'])
        ->and($report['skipped'])->toBe(['Riz au lait']);

    $imported = Recipe::firstWhere('title', 'Riz au lait de coco');

    expect($imported->is_to_test)->toBeTrue()
        ->and($imported->steps->first()->group_name)->toBe('Le riz');
});

test('un fichier d\'un autre format est refusé avec un message clair', function () {
    $file = UploadedFile::fake()->createWithContent('autre.json', json_encode(['format' => 'autre-chose']));

    Livewire::test(Import::class)
        ->set('tab', 'json')
        ->set('file', $file)
        ->call('importJson')
        ->assertHasErrors('file');
});

test('l\'écran d\'import importe un fichier d\'export', function () {
    Recipe::create(['title' => 'Riz au lait', 'servings' => 4]);
    $json = json_encode(app(RecipeArchive::class)->export());
    Recipe::query()->delete();

    Livewire::test(Import::class)
        ->set('tab', 'json')
        ->set('file', UploadedFile::fake()->createWithContent('carnet.json', $json))
        ->call('importJson')
        ->assertHasNoErrors();

    expect(Recipe::firstWhere('title', 'Riz au lait'))->not->toBeNull();
});

test('le nom trouvé sur le site devient un alias de l\'ingrédient retenu', function () {
    Http::fake(['*' => Http::response('<html><head><script type="application/ld+json">'.json_encode([
        '@type' => 'Recipe',
        'name' => 'Poulet à l\'ail',
        'recipeIngredient' => ['2 gousses d\'ail rose de Lautrec'],
        'recipeInstructions' => ['Cuire le poulet.'],
    ], JSON_UNESCAPED_UNICODE).'</script></head></html>')]);

    Livewire::test(Import::class)
        ->set('url', 'https://exemple.test/poulet')
        ->call('fetch')
        ->assertSet('form.ingredients.0.name', 'Ail rose de Lautrec')
        ->set('form.ingredients.0.name', 'Ail')
        ->call('save')
        ->assertHasNoErrors();

    expect(Ingredient::findByName('ail rose de Lautrec')?->name)->toBe('Ail');
});
