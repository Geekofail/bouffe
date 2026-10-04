<?php

use App\Livewire\Recipes\Cook;
use App\Livewire\Recipes\Show as RecipeShow;
use App\Models\Ingredient;
use App\Models\MealSlot;
use App\Models\RecipeCookNote;
use App\Models\User;
use App\Services\Planning\WeekPlanner;
use App\Services\Stock\StockManager;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

require_once __DIR__.'/../Shopping/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 18:00'));
    $this->actingAs($this->pierre = User::factory()->create(['name' => 'Pierre']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);
    $this->planner = app(WeekPlanner::class);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner']);

    $this->recipe = recipeWith('Poulet à la crème', 2, [[2, 'piece', 'Blanc de poulet', false], [20, 'cl', 'Crème liquide', false], [1, 'piece', 'Oignon', true]]);
    $this->recipe->steps()->createMany([
        ['position' => 1, 'instruction' => 'Émincer l\'oignon et couper les blancs de poulet en morceaux.'],
        ['position' => 2, 'instruction' => 'Faire revenir 5 min, puis ajouter la crème et laisser mijoter 20 min.'],
        ['position' => 3, 'instruction' => 'Servir avec du riz.'],
    ]);
});

test('mode cuisine : mise en place, étapes, minuteurs et ingrédients de l\'étape', function () {
    $this->get(route('recipes.cook', $this->recipe))->assertOk()->assertSee('Mise en place');

    $component = Livewire::test(Cook::class, ['recipe' => $this->recipe])
        ->assertSet('servings', 2)
        ->assertSet('step', 0)
        ->assertSee('blancs de poulet')
        ->assertSee('étape 1')                       // l'oignon est cité à l'étape 1
        ->call('changeServings', 2)
        ->call('next')->assertSet('step', 1)
        ->assertSee('Émincer')
        ->assertSee('Pour cette étape')
        ->call('next')->assertSet('step', 2)
        ->assertSee("Minuteur 5\u{00A0}min", false)
        ->assertSee("Minuteur 20\u{00A0}min", false)
        ->call('next')->call('next')->assertSet('step', 4)
        ->assertSee('C\'est prêt !', false);

    expect($component->get('done'))->toBe([1, 2, 3])
        ->and(collect($component->instance()->lines)->firstWhere('name', 'blancs de poulet')['quantity'])->toBe('4')
        ->and(collect($component->instance()->lines)->firstWhere('ingredient_id', Ingredient::firstWhere('name', 'Oignon')->id)['steps'])->toBe([1]);

    $component->call('goTo', 99)->assertSet('step', 4)
        ->call('goTo', -5)->assertSet('step', 0)
        ->call('toggleLine', $this->recipe->ingredients()->value('id'))
        ->assertSee('line-through');
});

test('mode cuisine depuis un repas planifié : portions, « mangé » et fenêtre de stock', function () {
    app(StockManager::class)->add(['ingredient_id' => Ingredient::firstWhere('name', 'Blanc de poulet')->id, 'quantity' => 4]);
    $meal = $this->planner->addRecipe('2026-09-16', $this->dinner, $this->recipe, 4);

    Livewire::withQueryParams(['repas' => $meal->id])
        ->test(Cook::class, ['recipe' => $this->recipe])
        ->assertSet('servings', 4)
        ->assertSee('dîner')
        ->call('goTo', 4)
        ->call('markCooked')
        ->assertDispatched('meal-cooked', mealId: $meal->id, cooked: true)
        ->assertDispatched('notify');

    expect($meal->fresh()->cooked_at)->not->toBeNull();

    // Un repas d'une autre recette est ignoré
    $other = $this->planner->addRecipe('2026-09-17', $this->dinner, recipeWith('Autre', 2, []), 2);
    Livewire::withQueryParams(['repas' => $other->id])->test(Cook::class, ['recipe' => $this->recipe])->assertSet('mealId', 0);
});

test('notes de cuisine : enregistrées en fin de cuisson, visibles sur la fiche et supprimables', function () {
    Livewire::test(Cook::class, ['recipe' => $this->recipe])
        ->call('goTo', 4)
        ->call('saveNote')->assertHasErrors('note')
        ->set('note', 'Trop salé, moitié moins de sel')
        ->call('saveNote')->assertHasNoErrors()
        ->assertSet('saved', true)
        ->assertSet('note', '');

    $note = RecipeCookNote::sole();
    expect($note->recipe_id)->toBe($this->recipe->id)
        ->and($note->user_id)->toBe($this->pierre->id)
        ->and($note->servings)->toBe(2.0);

    $this->get(route('recipes.show', $this->recipe))->assertOk()
        ->assertSee('Notes de cuisine')
        ->assertSee('Trop salé, moitié moins de sel');

    Livewire::test(Cook::class, ['recipe' => $this->recipe])->assertSee('La dernière fois');

    Livewire::test(RecipeShow::class, ['recipe' => $this->recipe])->call('deleteCookNote', $note->id);
    expect(RecipeCookNote::count())->toBe(0);
});

test('une recette archivée n\'a pas de mode cuisine', function () {
    $this->recipe->update(['archived_at' => now()]);

    $this->get(route('recipes.cook', $this->recipe))->assertNotFound();
});

/* ================================================================ Impressions */

test('impression d\'une fiche : portions, photo et notes en option', function () {
    RecipeCookNote::create(['recipe_id' => $this->recipe->id, 'user_id' => $this->pierre->id, 'note' => 'Doubler la sauce']);

    $this->get(route('recipes.print', ['recipe' => $this->recipe, 'portions' => 6]))->assertOk()
        ->assertSee('Poulet à la crème')
        ->assertSee('6 portions')
        ->assertSee('blancs de poulet')
        ->assertSee('Émincer')
        ->assertSee('Doubler la sauce')
        ->assertSee('window.print()', false);

    $this->get(route('recipes.print', ['recipe' => $this->recipe, 'notes' => 0]))->assertOk()->assertDontSee('Doubler la sauce');
});

test('impression du menu de la semaine, avec ou sans la liste de courses', function () {
    $this->planner->addRecipe('2026-09-16', $this->dinner, $this->recipe, 4);
    app(\App\Services\Planning\OccasionService::class)->save('2026-09-16', $this->dinner, ['extra_adults' => 2, 'title' => 'Voisins']);
    $list = app(\App\Services\Shopping\ShoppingListManager::class)->create(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20'));

    $this->get(route('planner.print', ['semaine' => '2026-09-14']))->assertOk()
        ->assertSee('Menu de la semaine')
        ->assertSeeInOrder(['Lundi', 'Mercredi', 'Poulet à la crème', 'Voisins', 'Dimanche'])
        ->assertDontSee($list->name);

    $this->get(route('planner.print', ['semaine' => '2026-09-14', 'courses' => 1]))->assertOk()
        ->assertSee($list->name)
        ->assertSee('blancs de poulet');
});
