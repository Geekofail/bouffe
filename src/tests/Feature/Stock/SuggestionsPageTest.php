<?php

use App\Livewire\Stock\Suggestions;
use App\Models\Ingredient;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\ShoppingList;
use App\Models\User;
use App\Services\Stock\StockManager;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

require_once __DIR__.'/../Shopping/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00'));
    $this->actingAs(User::factory()->create());
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);
    $this->stock = app(StockManager::class);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner']);

    $this->omelette = recipeWith('Omelette', 2, [[4, 'piece', 'Œuf', false], [20, 'g', 'Beurre', false], [null, null, 'Sel', false]]);
    $this->poulet = recipeWith('Poulet à la crème', 2, [[2, 'piece', 'Blanc de poulet', false], [20, 'cl', 'Crème liquide', false], [1, 'piece', 'Oignon', false]]);

    $this->eggs = Ingredient::firstWhere('name', 'Œuf');
    $this->stock->add(['ingredient_id' => $this->eggs->id, 'quantity' => 6, 'expires_on' => '2026-09-17', 'expiry_type' => 'dlc']);
    $this->stock->add(['ingredient_id' => Ingredient::firstWhere('name', 'Beurre')->id, 'quantity' => 250]);
    $this->stock->add(['ingredient_id' => Ingredient::firstWhere('name', 'Oignon')->id, 'quantity' => 3]);
    $this->stock->add(['ingredient_id' => Ingredient::firstWhere('name', 'Crème liquide')->id, 'quantity' => 20, 'unit_id' => \App\Models\Unit::firstWhere('code', 'cl')->id]);
});

test('la page « Que cuisiner ? » affiche les groupes, la couverture et les manques', function () {
    $this->get(route('suggestions'))->assertOk()
        ->assertSeeInOrder(['Faisable tout de suite', 'Omelette', '3/3 ingrédients', 'utilise 1 produit à consommer vite', 'Presque', 'Poulet à la crème', '2/3 ingrédients', 'Manque : blanc de poulet 2 pièces'])
        ->assertSee('Doit utiliser');
});

test('portions, « doit utiliser » et contexte du planning', function () {
    Livewire::withQueryParams(['utiliser' => [Ingredient::firstWhere('name', 'Oignon')->id]])
        ->test(Suggestions::class)
        ->assertSet('servings', 2)
        ->assertDontSee('Omelette')
        ->assertSee('Poulet à la crème')
        ->call('toggleMustUse', Ingredient::firstWhere('name', 'Oignon')->id)
        ->assertSee('Omelette')
        ->set('servings', 4)
        ->assertSee('Manque : œuf 2 pièces');

    Livewire::withQueryParams(['date' => '2026-09-18', 'creneau' => $this->dinner->id])
        ->test(Suggestions::class)
        ->assertSee('Pour Vendredi 18 septembre · dîner')
        ->call('openPlan', $this->omelette->id)
        ->assertSet('planDate', '2026-09-18')
        ->assertSet('planSlotId', $this->dinner->id)
        ->call('plan')
        ->assertRedirect(route('planner.week', ['semaine' => '2026-09-14']));

    expect(PlannedMeal::where('recipe_id', $this->omelette->id)->whereDate('date', '2026-09-18')->value('servings'))->toBe(2.0);
});

test('planifier depuis la page : le stock réservé disparaît des suggestions', function () {
    Livewire::test(Suggestions::class)
        ->call('openPlan', $this->omelette->id)
        ->set('planDate', '2026-09-17')
        ->call('plan')
        ->assertHasNoErrors()
        ->assertDispatched('notify')
        ->assertSee('Le stock prévu pour 1 repas planifié')
        ->set('ignorePlanning', true)
        ->assertDontSee('Le stock prévu pour 1 repas planifié');
});

test('ajouter les manquants à la liste en cours (ou à une nouvelle liste)', function () {
    Livewire::test(Suggestions::class)
        ->call('addMissing', $this->poulet->id)
        ->assertDispatched('notify', message: '1 article ajouté à « Courses du 16 sept. au 22 sept. » : Blanc de poulet.');

    $list = ShoppingList::sole();
    $item = $list->items()->firstWhere('label', 'Blanc de poulet');

    expect((float) $item->quantity)->toBe(2.0)
        ->and($item->unit->code)->toBe('piece')
        ->and($item->stock_note)->toBe('Pour « Poulet à la crème »');

    Livewire::test(Suggestions::class)
        ->call('addMissing', $this->poulet->id)
        ->assertDispatched('notify', message: "Déjà dans « {$list->name} ».");

    expect(ShoppingList::count())->toBe(1);

    Livewire::test(Suggestions::class)->call('addMissing', $this->omelette->id)
        ->assertDispatched('notify', message: 'Rien ne manque pour cette recette.');
});

test('sélecteur de repas du planning : onglet « Avec mon stock »', function () {
    Livewire::test(\App\Livewire\Planner\MealPicker::class)
        ->call('open', '2026-09-18', $this->dinner->id)
        ->set('tab', 'stock')
        ->assertSee('Tout est en stock')
        ->assertSee('Manque : blanc de poulet 2 pièces')
        ->assertSee('Plus d\'options', false)
        ->call('pickRecipe', $this->omelette->id)
        ->assertDispatched('meal-planned');

    expect(PlannedMeal::where('recipe_id', $this->omelette->id)->whereDate('date', '2026-09-18')->exists())->toBeTrue();
});

test('fiche recette : disponibilité de chaque ingrédient et ajout des manquants', function () {
    $this->get(route('recipes.show', ['recipe' => $this->poulet, 'portions' => 4]))->assertOk()
        ->assertSee('Stock : 1/3 ingrédients')
        ->assertSee('4 portions')
        ->assertSee('✓ en stock')
        ->assertSee('◐ manque 200 ml')
        ->assertSee('✗ manquant');

    Livewire::withQueryParams(['portions' => 4])->test(\App\Livewire\Recipes\Show::class, ['recipe' => $this->poulet])
        ->call('addMissingToList')
        ->assertDispatched('notify');

    expect(ShoppingList::sole()->items()->pluck('label')->sort()->values()->all())->toBe(['Blanc de poulet', 'Crème liquide']);
});

test('fiche recette sans stock : pas de badges', function () {
    \App\Models\StockItem::query()->delete();
    \App\Models\StockMovement::query()->delete();

    $this->get(route('recipes.show', $this->poulet))->assertOk()->assertDontSee('Stock :')->assertDontSee('✗ manquant');
});

test('accueil : « À utiliser rapidement » et lien vers les recettes ; planning : « Idées de recettes »', function () {
    Livewire::test(\App\Livewire\Stock\ExpiryAlertsCard::class)
        ->assertSee('À utiliser rapidement')
        ->assertSee('1 recette possible')
        ->assertSee(route('suggestions', ['utiliser' => [$this->eggs->id]]), false)
        ->assertSee('Recette…');

    Livewire::test(\App\Livewire\Planner\Week::class)->assertSee('Idées de recettes');
});
