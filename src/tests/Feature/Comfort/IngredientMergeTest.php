<?php

use App\Livewire\Settings\IngredientDuplicates;
use App\Livewire\Settings\Ingredients;
use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\IngredientAlias;
use App\Models\MealSlot;
use App\Models\RecurringItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Ingredients\IngredientMerger;
use App\Services\Planning\GuestManager;
use App\Services\Planning\WeekPlanner;
use App\Services\Shopping\ShoppingListManager;
use App\Services\Stock\QuickAddParser;
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
    $this->merger = app(IngredientMerger::class);
    $this->stock = app(StockManager::class);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner']);

    // Doublon créé par erreur : « Tomatte » dans le même rayon que « Tomate »
    $this->tomate = Ingredient::firstWhere('name', 'Tomate');
    $this->tomatte = Ingredient::create(['name' => 'Tomatte', 'name_plural' => 'Tomattes', 'aisle_id' => $this->tomate->aisle_id, 'default_unit_id' => $this->tomate->default_unit_id]);
});

test('fusion : recettes, stock, mouvements, listes, récurrents et contraintes passent sur l\'ingrédient gardé ; l\'ancien nom devient un alias', function () {
    $salade = recipeWith('Salade', 2, [[2, 'piece', 'Tomatte', false], [1, 'piece', 'Concombre', false]]);
    $sauce = recipeWith('Sauce', 2, [[3, 'piece', 'Tomate', false]]);
    $item = $this->stock->add(['ingredient_id' => $this->tomatte->id, 'quantity' => 4]);
    RecurringItem::create(['label' => 'Tomattes', 'ingredient_id' => $this->tomatte->id]);
    $julie = app(GuestManager::class)->save(null, ['name' => 'Julie'], [['type' => 'dislike', 'ingredient' => 'Tomatte'], ['type' => 'dislike', 'ingredient' => 'Tomate']]);

    // Liste en cours avec les deux lignes générées
    app(WeekPlanner::class)->addRecipe('2026-09-17', $this->dinner, $salade, 2);
    app(WeekPlanner::class)->addRecipe('2026-09-18', $this->dinner, $sauce, 2);
    $list = app(ShoppingListManager::class)->create(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20'), deductStock: false);
    expect($list->items()->whereIn('ingredient_id', [$this->tomate->id, $this->tomatte->id])->count())->toBe(2);

    expect($this->merger->impact($this->tomatte))->toBe(['recipes' => 1, 'stock' => 1, 'lists' => 1, 'restrictions' => 1]);

    $merge = $this->merger->merge($this->tomatte, $this->tomate);

    expect(Ingredient::find($this->tomatte->id))->toBeNull()
        ->and($salade->ingredients()->pluck('ingredient_id')->all())->toContain($this->tomate->id)
        ->and($item->fresh()->ingredient_id)->toBe($this->tomate->id)
        ->and(StockMovement::where('stock_item_id', $item->id)->value('ingredient_id'))->toBe($this->tomate->id)
        ->and(RecurringItem::first()->ingredient_id)->toBe($this->tomate->id)
        ->and($julie->restrictions()->where('ingredient_id', $this->tomate->id)->count())->toBe(1)
        ->and($this->tomate->aliases()->pluck('name')->all())->toBe(['Tomatte'])
        ->and($merge->payload['moved'])->toHaveKeys(['recipe_ingredients', 'stock_items', 'stock_movements', 'recurring_items']);

    // Une seule ligne « Tomate » sur la liste, quantités additionnées (2 + 3)
    $tomatoes = $list->items()->where('ingredient_id', $this->tomate->id)->get();
    expect($tomatoes)->toHaveCount(1)->and((float) $tomatoes->first()->quantity)->toBe(5.0);

    // L'ancien nom est reconnu partout
    expect(Ingredient::findByName('tomattes')->id)->toBe($this->tomate->id)
        ->and(app(QuickAddParser::class)->parse('3 tomattes')['ingredient']->id)->toBe($this->tomate->id)
        ->and(app(ShoppingListManager::class)->matchIngredient('Tomatte')->id)->toBe($this->tomate->id)
        ->and(Ingredient::query()->search('tomatt')->pluck('id')->all())->toBe([$this->tomate->id]);
});

test('annulation de la dernière fusion : l\'ingrédient revient avec ses références', function () {
    $salade = recipeWith('Salade', 2, [[2, 'piece', 'Tomatte', false]]);
    $item = $this->stock->add(['ingredient_id' => $this->tomatte->id, 'quantity' => 4]);
    $julie = app(GuestManager::class)->save(null, ['name' => 'Julie'], [['type' => 'dislike', 'ingredient' => 'Tomatte'], ['type' => 'dislike', 'ingredient' => 'Tomate']]);

    $merge = $this->merger->merge($this->tomatte, $this->tomate);
    $restored = $this->merger->undo($merge);

    expect($restored->id)->toBe($this->tomatte->id)
        ->and($restored->name)->toBe('Tomatte')
        ->and($salade->ingredients()->value('ingredient_id'))->toBe($this->tomatte->id)
        ->and($item->fresh()->ingredient_id)->toBe($this->tomatte->id)
        ->and($julie->restrictions()->count())->toBe(2)
        ->and(IngredientAlias::count())->toBe(0)
        ->and($merge->fresh()->undone_at)->not->toBeNull()
        ->and($this->merger->lastUndoable())->toBeNull();

    expect(fn () => $this->merger->undo($merge->fresh()))->toThrow(InvalidArgumentException::class);
});

test('doublons probables : faute de frappe dans le même rayon, paires écartées', function () {
    $pairs = $this->merger->duplicates()->map(fn ($p) => [$p['a']->name, $p['b']->name])->all();

    expect($pairs)->toContain(['Tomate', 'Tomatte']);
    expect(collect($pairs)->flatten()->contains('Tomate cerise'))->toBeFalse();

    $this->merger->ignore($this->tomatte->id, $this->tomate->id);
    expect($this->merger->duplicates()->contains(fn ($p) => $p['a']->is($this->tomate) && $p['b']->is($this->tomatte)))->toBeFalse();
});

test('page Doublons : fusion avec confirmation puis annulation', function () {
    $this->get(route('settings.ingredient-duplicates'))->assertOk()->assertSee('Doublons probables')->assertSee('Tomatte');

    Livewire::test(IngredientDuplicates::class)
        ->call('prepare', $this->tomatte->id, $this->tomate->id)
        ->assertSet('confirming', true)
        ->assertSee('sera supprimé et remplacé par')
        ->call('merge')
        ->assertDispatched('notify')
        ->assertSee('Dernière fusion')
        ->call('undo')
        ->assertDispatched('notify', message: 'Fusion annulée : « Tomatte » est de retour.');

    Livewire::test(IngredientDuplicates::class)
        ->set('sourceId', $this->tomate->id)->set('targetId', $this->tomate->id)
        ->call('review')->assertHasErrors('targetId')->assertSet('confirming', false);

    expect(Ingredient::whereKey($this->tomatte->id)->exists())->toBeTrue();
});

test('autres noms dans la fiche ingrédient ; un alias ne peut pas devenir un nouvel ingrédient', function () {
    Livewire::test(Ingredients::class)
        ->call('edit', $this->tomate->id)
        ->set('newAlias', 'Pomodoro')->call('addAlias')->assertHasNoErrors()
        ->set('newAlias', 'Concombre')->call('addAlias')->assertHasErrors('newAlias')
        ->assertSee('Pomodoro');

    expect(Ingredient::findByName('pomodoro')->is($this->tomate))->toBeTrue();

    Livewire::test(Ingredients::class)
        ->call('create')
        ->set('form.name', 'Pomodoro')
        ->call('save')
        ->assertHasErrors(['form.name']);

    Livewire::test(Ingredients::class)->call('edit', $this->tomate->id)
        ->call('removeAlias', IngredientAlias::first()->id);
    expect(IngredientAlias::count())->toBe(0);
});

test('une sauvegarde est créée avant la fusion si c\'est activé', function () {
    $dir = storage_path('framework/testing/merge-backups-'.uniqid());
    config(['bouffe.backups.path' => $dir, 'bouffe.backups.before_merge' => true]);

    $this->merger->merge($this->tomatte, $this->tomate);

    expect(glob($dir.'/bouffe-*-merge.zip'))->toHaveCount(1);
    \Illuminate\Support\Facades\File::deleteDirectory($dir);
})->skip(fn () => ! class_exists(ZipArchive::class), 'Extension zip absente');

test('doublons : deux noms qui ne diffèrent que par un mot différent ne sont pas proposés', function () {
    $porc = Aisle::firstWhere('name', 'Boucherie & volaille') ?? Aisle::first();
    Ingredient::create(['name' => 'Côte de porcc', 'aisle_id' => $porc->id]);
    Ingredient::create(['name' => 'Rôti de veau', 'aisle_id' => $porc->id]);

    $names = $this->merger->duplicates()->map(fn ($p) => $p['a']->name.' | '.$p['b']->name)->all();

    expect($names)->toContain('Côte de porc | Côte de porcc')
        ->and(collect($names)->filter(fn ($n) => str_contains($n, 'Rôti'))->all())->toBe([]);
});
