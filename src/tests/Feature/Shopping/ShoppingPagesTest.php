<?php

use App\Enums\ListStatus;
use App\Livewire\Settings\RecurringItems;
use App\Livewire\Shopping\Index;
use App\Livewire\Shopping\Show;
use App\Models\Aisle;
use App\Models\MealSlot;
use App\Models\RecurringItem;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Services\Planning\WeekPlanner;
use App\Services\Shopping\ShoppingListManager;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00')); // mercredi
    $this->actingAs($this->user = User::factory()->create(['name' => 'Monique']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, IngredientSeeder::class]);
    $this->planner = app(WeekPlanner::class);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner']);

    $this->recipe = recipeWith('Omelette', 2, [[4, 'piece', 'Œuf', false], [20, 'g', 'Beurre', false], [null, null, 'Sel', false]]);
    $this->past = $this->planner->addRecipe('2026-09-14', $this->dinner, $this->recipe);
    $this->meal = $this->planner->addRecipe('2026-09-17', $this->dinner, $this->recipe, 4);
    $this->other = $this->planner->addRecipe('2026-09-19', $this->dinner, recipeWith('Guacamole', 2, [[2, 'piece', 'Avocat', false]]));
});

test('la fenêtre de génération s\'ouvre depuis le lien du planning avec la bonne période', function () {
    Livewire::withQueryParams(['generer' => '2026-09-14'])->test(Index::class)
        ->assertSet('showGenerate', true)
        ->assertSet('from', '2026-09-16')     // semaine en cours : à partir d'aujourd'hui
        ->assertSet('to', '2026-09-20')
        ->assertSee('Omelette')->assertSee('Guacamole')
        ->assertSet('preview.meals', 2)
        ->assertSet('preview.ingredients', 4);

    Livewire::test(Index::class)->call('openGenerate', '2026-09-21')
        ->assertSet('from', '2026-09-21')->assertSet('to', '2026-09-27');
});

test('générer une liste en décochant un repas', function () {
    Livewire::test(Index::class)
        ->call('openGenerate')
        ->call('toggleMeal', $this->other->id)
        ->assertSet('preview.meals', 1)
        ->set('name', 'Courses de jeudi')
        ->call('generate')
        ->assertRedirect(route('shopping.show', ShoppingList::sole()));

    $list = ShoppingList::sole();
    expect($list->name)->toBe('Courses de jeudi')
        ->and($list->excluded_meal_ids)->toBe([$this->other->id])
        ->and($list->items()->pluck('label')->sort()->values()->all())->toBe(['Beurre', 'Sel', 'Œuf']);
});

test('période invalide', function () {
    Livewire::test(Index::class)->call('openGenerate')
        ->set('from', '2026-09-20')->set('to', '2026-09-10')
        ->call('generate')->assertHasErrors('to');
});

test('la page liste affiche les rayons, le placard, la progression et cocher fonctionne', function () {
    $list = app(ShoppingListManager::class)->create(Carbon::parse('2026-09-16'), Carbon::parse('2026-09-20'), name: 'Semaine');

    $this->get(route('shopping.show', $list))
        ->assertOk()
        ->assertSee('Semaine')
        ->assertSee('Crèmerie &amp; œufs', false)
        ->assertSee('8 œufs')->assertSee('2 avocats')
        ->assertSee('À vérifier dans le placard')->assertSee('Sel')
        ->assertSee('0 / 4');

    $egg = $list->items()->where('label', 'Œuf')->sole();

    Livewire::test(Show::class, ['shoppingList' => $list])
        ->call('toggle', $egg->id)
        ->assertSee('1 / 4')
        ->set('hideChecked', true)
        ->assertDontSee('8 œufs')
        ->call('edit', $egg->id)
        ->assertSee('Omelette')
        ->assertSee('Coché par Monique');
});

test('ajout manuel, modification, retrait et restauration depuis la page', function () {
    $list = app(ShoppingListManager::class)->create(Carbon::parse('2026-09-16'), Carbon::parse('2026-09-20'));
    $avocado = $list->items()->where('label', 'Avocat')->sole();

    Livewire::test(Show::class, ['shoppingList' => $list])
        ->set('newItem', '2 baguettes')->call('addItem')->assertHasNoErrors()->assertSee('Boulangerie')
        ->set('newItem', '')->call('addItem')->assertHasErrors('newItem')
        ->call('edit', $avocado->id)
        ->set('editQuantity', '3')
        ->call('saveEdit')
        ->assertHasNoErrors()
        ->assertSee('3 avocats')
        ->call('edit', $avocado->id)->set('editQuantity', 'plein')->call('saveEdit')->assertHasErrors('editQuantity')
        ->call('remove', $avocado->id)
        ->assertSee('Articles retirés (1)')
        ->call('restore', $avocado->id)
        ->assertDontSee('Articles retirés');

    expect($list->items()->where('label', '2 baguettes')->sole()->aisle->name)->toBe('Boulangerie');
});

test('mettre à jour depuis le planning affiche les changements', function () {
    $list = app(ShoppingListManager::class)->create(Carbon::parse('2026-09-16'), Carbon::parse('2026-09-20'));
    $this->planner->update($this->meal, ['servings' => 6]);

    Livewire::test(Show::class, ['shoppingList' => $list])
        ->call('regenerate')
        ->assertSee('Modifié : 8 œufs → 12 œufs')
        ->assertDispatched('notify');
});

test('copie texte, terminer, rouvrir, tout décocher, supprimer', function () {
    $list = app(ShoppingListManager::class)->create(Carbon::parse('2026-09-16'), Carbon::parse('2026-09-20'), name: 'Liste test');
    app(ShoppingListManager::class)->toggleCheck($list->items()->where('label', 'Beurre')->sole());

    Livewire::test(Show::class, ['shoppingList' => $list])
        ->set('showText', true)
        ->assertSee('FRUITS &amp; LÉGUMES', false)->assertSee('- 2 avocats')
        ->call('toggleStatus')
        ->tap(fn () => expect($list->fresh()->status)->toBe(ListStatus::Done))
        ->call('toggleStatus')
        ->call('uncheckAll')
        ->tap(fn () => expect($list->items()->where('is_checked', true)->count())->toBe(0))
        ->call('deleteList')
        ->assertRedirect(route('shopping.index'));

    expect(ShoppingList::count())->toBe(0)->and(ShoppingListItem::count())->toBe(0);
});

test('page des listes : en cours et terminées', function () {
    $manager = app(ShoppingListManager::class);
    $manager->create(Carbon::parse('2026-09-16'), Carbon::parse('2026-09-20'), name: 'En cours');
    $done = $manager->create(Carbon::parse('2026-09-07'), Carbon::parse('2026-09-13'), true, name: 'Ancienne');
    $manager->setStatus($done, ListStatus::Done);

    $this->get(route('shopping.index'))->assertOk()->assertSee('En cours')->assertSee('Listes terminées')->assertSee('Ancienne');
});

test('un article d\'une autre liste n\'est pas modifiable', function () {
    $manager = app(ShoppingListManager::class);
    $a = $manager->create(Carbon::parse('2026-09-16'), Carbon::parse('2026-09-20'));
    $b = $manager->create(Carbon::parse('2026-09-16'), Carbon::parse('2026-09-20'));

    Livewire::test(Show::class, ['shoppingList' => $a])->call('toggle', $b->items()->first()->id);
})->throws(Illuminate\Database\Eloquent\ModelNotFoundException::class);

test('paramètres : articles récurrents', function () {
    Livewire::test(RecurringItems::class)
        ->set('label', 'café')->call('add')->assertHasNoErrors()
        ->set('label', 'Tomates')->call('add')->assertHasNoErrors()
        ->set('label', 'Café')->call('add')->assertHasErrors('label');

    $tomato = RecurringItem::firstWhere('label', 'Tomates');
    expect(RecurringItem::firstWhere('label', 'Café'))->not->toBeNull()
        ->and($tomato->ingredient->name)->toBe('Tomate')
        ->and($tomato->aisle->name)->toBe('Fruits & légumes');

    Livewire::test(RecurringItems::class)
        ->call('toggle', $tomato->id)
        ->call('updateAisle', $tomato->id, (string) Aisle::firstWhere('name', 'Divers')->id)
        ->call('delete', RecurringItem::firstWhere('label', 'Café')->id);

    expect($tomato->fresh())->is_active->toBeFalse()->and($tomato->fresh()->aisle->name)->toBe('Divers')
        ->and(RecurringItem::count())->toBe(1);

    $this->get(route('settings.recurring'))->assertOk()->assertSee('Tomates');
});

test('le tableau de bord montre la liste en cours', function () {
    app(ShoppingListManager::class)->create(Carbon::parse('2026-09-16'), Carbon::parse('2026-09-20'), name: 'Liste du tableau de bord');

    $this->get(route('dashboard'))->assertOk()->assertSee('Liste du tableau de bord')->assertSee('Ouvrir la liste');
});
