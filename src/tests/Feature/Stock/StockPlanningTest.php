<?php

use App\Enums\ItemOrigin;
use App\Enums\MovementType;
use App\Livewire\Dashboard;
use App\Livewire\Planner\Week;
use App\Livewire\Settings\StockSettings;
use App\Livewire\Shopping\Show as ShoppingShow;
use App\Livewire\Stock\Index as StockIndex;
use App\Livewire\Stock\Inventory;
use App\Livewire\Stock\MealStockDialog;
use App\Models\Ingredient;
use App\Models\MealSlot;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Models\User;
use App\Services\Planning\WeekPlanner;
use App\Services\Shopping\ShoppingListManager;
use App\Services\Stock\MealStockService;
use App\Services\Stock\StockManager;
use App\Services\Stock\StockMinimum;
use App\Support\Settings;
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
    $this->service = app(MealStockService::class);
    $this->planner = app(WeekPlanner::class);
    $this->manager = app(ShoppingListManager::class);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner']);
    $this->fridge = StorageLocation::firstWhere('name', 'Réfrigérateur');

    // Omelette pour 2 : 4 œufs, 30 g de beurre, 10 cl de crème, sel
    $this->omelette = recipeWith('Omelette', 2, [[4, 'piece', 'Œuf', false], [30, 'g', 'Beurre', false], [10, 'cl', 'Crème fraîche épaisse', false], [null, null, 'Sel', false]]);
});

function sp(string $name): Ingredient
{
    return Ingredient::firstWhere('name', $name);
}

/** Stock de départ : 6 œufs (DLC 20/09) + 6 œufs (DLC 30/09), 250 g de beurre, crème (quantité inconnue), sel. */
function stockForOmelette(StockManager $stock): array
{
    return [
        'eggs1' => $stock->add(['ingredient_id' => sp('Œuf')->id, 'quantity' => 6, 'expires_on' => '2026-09-20', 'expiry_type' => 'dlc']),
        'eggs2' => $stock->add(['ingredient_id' => sp('Œuf')->id, 'quantity' => 6, 'expires_on' => '2026-09-30', 'expiry_type' => 'dlc']),
        'butter' => $stock->add(['ingredient_id' => sp('Beurre')->id, 'quantity' => 250]),
        'cream' => $stock->add(['ingredient_id' => sp('Crème fraîche épaisse')->id]),
        'salt' => $stock->add(['ingredient_id' => sp('Sel')->id]),
    ];
}

/* ================================================================ R9 : repas mangé */

test('proposition de retrait : plus anciens d\'abord, quantité inconnue et présence non cochées', function () {
    $items = stockForOmelette($this->stock);
    $meal = $this->planner->addRecipe('2026-09-16', $this->dinner, $this->omelette, 4);   // 8 œufs, 60 g, 20 cl

    $rows = collect($this->service->plan($meal))->keyBy('name');

    expect($rows->keys()->sort()->values()->all())->toBe(['Beurre', 'Crème fraîche épaisse', 'Sel', 'Œuf'])
        ->and($rows['Œuf']['status'])->toBe('ok')
        ->and($rows['Œuf']['include'])->toBeTrue()
        ->and($rows['Œuf']['allocations'])->toHaveCount(2)
        ->and($rows['Œuf']['allocations'][0])->toMatchArray(['stock_item_id' => $items['eggs1']->id, 'take' => 6.0, 'finish' => true])
        ->and($rows['Œuf']['allocations'][1])->toMatchArray(['stock_item_id' => $items['eggs2']->id, 'take' => 2.0, 'finish' => false])
        ->and($rows['Œuf']['text'])->toContain('(terminé)')->toContain('reste 4')
        ->and($rows['Beurre']['allocations'][0]['take'])->toBe(60.0)
        ->and($rows['Crème fraîche épaisse']['status'])->toBe('unknown')
        ->and($rows['Crème fraîche épaisse']['include'])->toBeFalse()
        ->and($rows['Sel']['status'])->toBe('presence')
        ->and($rows['Sel']['finish_unknown'])->toBeFalse();
});

test('pas assez en stock : retrait partiel ; ingrédient absent du stock : pas de ligne', function () {
    $this->stock->add(['ingredient_id' => sp('Œuf')->id, 'quantity' => 3]);
    $meal = $this->planner->addRecipe('2026-09-16', $this->dinner, $this->omelette, 2);

    $rows = collect($this->service->plan($meal))->keyBy('name');

    expect($rows->keys()->all())->toBe(['Œuf'])
        ->and($rows['Œuf']['status'])->toBe('partial')
        ->and($rows['Œuf']['allocations'][0])->toMatchArray(['take' => 3.0, 'finish' => true]);
});

test('application puis annulation quand on décoche « mangé »', function () {
    $items = stockForOmelette($this->stock);
    $meal = $this->planner->addRecipe('2026-09-16', $this->dinner, $this->omelette, 4);

    $rows = $this->service->plan($meal);
    $rows = array_map(fn ($r) => $r['name'] === 'Crème fraîche épaisse' ? [...$r, 'finish_unknown' => true] : $r, $rows);

    expect($this->service->apply($meal, $rows))->toBe(4)
        ->and($items['eggs1']->fresh()->finished_at)->not->toBeNull()
        ->and((float) $items['eggs2']->fresh()->quantity)->toBe(4.0)
        ->and((float) $items['butter']->fresh()->quantity)->toBe(190.0)
        ->and($items['cream']->fresh()->finished_at)->not->toBeNull()
        ->and($items['salt']->fresh()->finished_at)->toBeNull()
        ->and(StockMovement::where('planned_meal_id', $meal->id)->count())->toBe(4)
        ->and($this->service->hasDeduction($meal))->toBeTrue();

    // Le lendemain (hors délai d'annulation classique), on décoche : tout revient.
    $this->travel(1)->day();
    expect($this->service->revert($meal))->toBe(['restored' => 4, 'skipped' => 0])
        ->and($items['eggs1']->fresh()->finished_at)->toBeNull()
        ->and((float) $items['eggs2']->fresh()->quantity)->toBe(6.0)
        ->and((float) $items['butter']->fresh()->quantity)->toBe(250.0)
        ->and($items['cream']->fresh()->finished_at)->toBeNull()
        ->and($this->service->hasDeduction($meal))->toBeFalse()
        ->and(StockMovement::where('type', MovementType::Undo->value)->whereNotNull('reverts_movement_id')->count())->toBe(4);
});

test('annulation : un article modifié depuis le repas n\'est pas touché', function () {
    $items = stockForOmelette($this->stock);
    $meal = $this->planner->addRecipe('2026-09-16', $this->dinner, $this->omelette, 2);
    $this->service->apply($meal, $this->service->plan($meal));

    $this->stock->setQuantity($items['butter']->fresh(), 100);   // corrigé à la main ensuite

    expect($this->service->revert($meal))->toBe(['restored' => 1, 'skipped' => 1])
        ->and((float) $items['butter']->fresh()->quantity)->toBe(100.0)
        ->and((float) $items['eggs1']->fresh()->quantity)->toBe(6.0);
});

test('restes : proposés au réfrigérateur, rangés avec une DLC, consommés par le repas « restes »', function () {
    $meal = $this->planner->addRecipe('2026-09-16', $this->dinner, $this->omelette, 6);   // 6 portions, 2 convives

    expect($this->service->leftoversOffer($meal))->toBe(['portions' => 4.0, 'label' => 'Restes de omelette', 'expires_on' => '2026-09-19']);

    $item = $this->service->storeLeftovers($meal, 3);
    expect($item->planned_meal_id)->toBe($meal->id)
        ->and($item->ingredient_id)->toBeNull()
        ->and((float) $item->quantity)->toBe(3.0)
        ->and($item->unit->code)->toBe('portion')
        ->and($item->storage_location_id)->toBe($this->fridge->id)
        ->and($item->expires_on->toDateString())->toBe('2026-09-19')
        ->and($this->service->leftoversOffer($meal))->toBeNull();   // déjà rangés

    $leftover = $this->planner->addLeftover('2026-09-17', $this->dinner, $meal, 2);
    expect($this->service->leftoverConsumption($leftover))->toMatchArray(['stock_item_id' => $item->id, 'take' => 2, 'left' => 1.0]);

    $this->service->consumeLeftovers($leftover);
    expect((float) $item->fresh()->quantity)->toBe(1.0);

    // Décocher le repas d'origine : les restes rangés ont été modifiés depuis → laissés.
    expect($this->service->revert($meal))->toBe(['restored' => 0, 'skipped' => 1]);

    // Décocher le repas « restes » : la portion revient.
    expect($this->service->revert($leftover))->toBe(['restored' => 1, 'skipped' => 0])
        ->and((float) $item->fresh()->quantity)->toBe(3.0);
});

/* ================================================================ Fenêtre */

test('fenêtre « Mettre à jour le stock » : ouverte depuis le planning, validée avec les choix', function () {
    $items = stockForOmelette($this->stock);
    $meal = $this->planner->addRecipe('2026-09-16', $this->dinner, $this->omelette, 4);

    Livewire::test(Week::class)
        ->call('toggleCooked', $meal->id)
        ->assertDispatched('meal-cooked', mealId: $meal->id, cooked: true);

    Livewire::test(MealStockDialog::class)
        ->dispatch('meal-cooked', mealId: $meal->id, cooked: true)
        ->assertSet('show', true)
        ->assertSee('Mettre à jour le stock')
        ->assertSee('−6 pièces (terminé)')
        ->assertSee('Il n\'en reste plus', false)
        ->assertSee('Ranger « Restes de omelette » au réfrigérateur', false)
        ->set('include.i'.sp('Beurre')->id, false)
        ->set('finishUnknown.i'.sp('Sel')->id, true)
        ->set('leftoverPortions', 1)
        ->call('confirm')
        ->assertSet('show', false)
        ->assertDispatched('notify')
        ->assertDispatched('stock-changed');

    expect((float) $items['butter']->fresh()->quantity)->toBe(250.0)
        ->and($items['salt']->fresh()->finished_at)->not->toBeNull()
        ->and($items['eggs1']->fresh()->finished_at)->not->toBeNull()
        ->and((float) StockItem::active()->where('planned_meal_id', $meal->id)->value('quantity'))->toBe(1.0);
});

test('fenêtre : « Ne rien retirer » laisse le stock ; décocher propose de remettre', function () {
    $items = stockForOmelette($this->stock);
    $meal = $this->planner->addRecipe('2026-09-16', $this->dinner, $this->omelette, 2);

    Livewire::test(MealStockDialog::class)
        ->dispatch('meal-cooked', mealId: $meal->id, cooked: true)
        ->call('close')
        ->assertSet('show', false);
    expect((float) $items['butter']->fresh()->quantity)->toBe(250.0);

    $this->service->apply($meal, $this->service->plan($meal));

    Livewire::test(MealStockDialog::class)
        ->dispatch('meal-cooked', mealId: $meal->id, cooked: false)
        ->assertSet('show', true)
        ->assertSee('Remettre dans le stock ?')
        ->call('confirmRevert')
        ->assertDispatched('notify');

    expect((float) $items['butter']->fresh()->quantity)->toBe(250.0);
});

test('réglage « automatique » : retrait et restes sans fenêtre ; « jamais » : rien', function () {
    $items = stockForOmelette($this->stock);
    $meal = $this->planner->addRecipe('2026-09-16', $this->dinner, $this->omelette, 3);

    Settings::set('stock.deduction_mode', 'never');
    Livewire::test(MealStockDialog::class)->dispatch('meal-cooked', mealId: $meal->id, cooked: true)->assertSet('show', false);
    expect((float) $items['butter']->fresh()->quantity)->toBe(250.0);

    Settings::set('stock.deduction_mode', 'auto');
    Livewire::test(MealStockDialog::class)->dispatch('meal-cooked', mealId: $meal->id, cooked: true)
        ->assertSet('show', false)
        ->assertDispatched('notify');
    expect((float) $items['butter']->fresh()->quantity)->toBe(205.0)
        ->and(StockItem::active()->where('planned_meal_id', $meal->id)->exists())->toBeTrue();

    Livewire::test(MealStockDialog::class)->dispatch('meal-cooked', mealId: $meal->id, cooked: false)->assertSet('show', false);
    expect((float) $items['butter']->fresh()->quantity)->toBe(250.0)
        ->and(StockItem::active()->where('planned_meal_id', $meal->id)->exists())->toBeFalse();
});

test('réglages du stock : mode de retrait enregistré', function () {
    Livewire::test(StockSettings::class)
        ->assertSet('deductionMode', 'ask')
        ->set('deductionMode', 'auto')->call('save')->assertHasNoErrors();

    expect(Settings::get('stock.deduction_mode'))->toBe('auto');

    Livewire::test(StockSettings::class)->set('deductionMode', 'n-importe')->call('save')->assertHasErrors('deductionMode');
});

test('l\'accueil ouvre aussi la fenêtre quand on coche un repas du jour', function () {
    $meal = $this->planner->addRecipe('2026-09-16', $this->dinner, $this->omelette, 2);

    Livewire::test(Dashboard::class)
        ->call('toggleCooked', $meal->id)
        ->assertDispatched('meal-cooked', mealId: $meal->id, cooked: true);

    $this->get(route('dashboard'))->assertOk();
});

/* ================================================================ R8 : courses */

test('liste de courses : le stock est déduit (couvert, partiel, à vérifier, périmé avant le repas, produits de base)', function () {
    $recipe = recipeWith('Tarte', 4, [[250, 'g', 'Farine', false], [3, 'piece', 'Œuf', false], [2, 'piece', 'Tomate', false], [null, null, 'Sel', false], [20, 'cl', 'Crème fraîche épaisse', false], [200, 'g', 'Beurre', false]]);
    $this->planner->addRecipe('2026-09-18', $this->dinner, $recipe, 4);

    $this->stock->add(['ingredient_id' => sp('Farine')->id]);                                  // base présente
    $this->stock->setPresence(sp('Sel'), true);
    $this->stock->setPresence(sp('Sel'), false);                                                // base épuisée
    $this->stock->add(['ingredient_id' => sp('Œuf')->id, 'quantity' => 2]);                     // 2 sur 3
    $this->stock->add(['ingredient_id' => sp('Tomate')->id, 'quantity' => 5]);                  // couvert
    $this->stock->add(['ingredient_id' => sp('Crème fraîche épaisse')->id]);                    // quantité inconnue
    $this->stock->add(['ingredient_id' => sp('Beurre')->id, 'quantity' => 250, 'expires_on' => '2026-09-17', 'expiry_type' => 'dlc']); // périme avant

    $list = $this->manager->create(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20'));
    $items = $list->items()->get()->keyBy('label');

    expect($items['Farine']->stock_status)->toBe('covered')
        ->and($items['Sel']->stock_status)->toBe('out')
        ->and($items['Œuf']->stock_status)->toBe('partial')
        ->and((float) $items['Œuf']->quantity)->toBe(1.0)
        ->and((float) $items['Œuf']->stock_deducted)->toBe(2.0)
        ->and($items['Œuf']->stock_note)->toBe('Besoin 3 pièces − en stock 2 pièces')
        ->and($items['Tomate']->stock_status)->toBe('covered')
        ->and((float) $items['Tomate']->quantity)->toBe(2.0)
        ->and($items['Crème fraîche épaisse']->stock_status)->toBeNull()
        ->and($items['Crème fraîche épaisse']->stock_note)->toContain('vérifier')
        ->and($items['Beurre']->stock_status)->toBe('out')
        ->and($items['Beurre']->stock_note)->toContain('périme le 17/09, prévu le 18/09 — non compté');

    $grouped = $this->manager->grouped($list);
    $inAisles = $grouped['aisles']->flatMap(fn ($a) => $a['items'])->pluck('label');

    expect($grouped['covered']->pluck('label')->all())->toBe(['Tomate'])
        ->and($inAisles)->toContain('Sel')->toContain('Œuf')->not->toContain('Tomate')
        ->and($grouped['staples']->pluck('label')->all())->toBe(['Farine']);

    // « Acheter quand même »
    $tomato = $this->manager->setBuyAnyway($items['Tomate'], true);
    expect($tomato->stock_status)->toBeNull()->and((float) $tomato->quantity)->toBe(2.0);

    Livewire::test(ShoppingShow::class, ['shoppingList' => $list])
        ->assertSee('Besoin 3 pièces − en stock 2 pièces')
        ->call('buyAnyway', $tomato->id, false);
    expect($tomato->fresh()->stock_status)->toBe('covered');

    // Le stock bouge → régénérer met à jour
    $this->stock->add(['ingredient_id' => sp('Œuf')->id, 'quantity' => 6]);
    $this->manager->regenerate($list);
    expect($items['Œuf']->fresh()->stock_status)->toBe('covered');
});

test('liste sans déduction du stock', function () {
    $this->planner->addRecipe('2026-09-18', $this->dinner, $this->omelette, 2);
    $this->stock->add(['ingredient_id' => sp('Œuf')->id, 'quantity' => 12]);

    $list = $this->manager->create(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20'), deductStock: false);

    expect($list->deduct_stock)->toBeFalse()
        ->and($list->items()->firstWhere('label', 'Œuf')->stock_status)->toBeNull();
});

/* ================================================================ 9.10 Stock minimum */

test('stock minimum : ingrédients sous le seuil, ajoutés à la liste « Stock bas »', function () {
    sp('Beurre')->update(['min_stock_quantity' => 250, 'min_stock_unit_id' => Unit::firstWhere('code', 'g')->id]);
    sp('Pâtes')->update(['min_stock_quantity' => 1]);
    $this->stock->add(['ingredient_id' => sp('Beurre')->id, 'quantity' => 100]);
    $this->stock->add(['ingredient_id' => sp('Pâtes')->id]);

    $below = app(StockMinimum::class)->below()->keyBy(fn ($r) => $r['ingredient']->name);

    expect($below->has('Pâtes'))->toBeFalse()
        ->and($below['Beurre']['text'])->toBe('reste 100 g · minimum 250 g')
        ->and($below['Beurre']['missing'])->toBe(150.0);

    $list = $this->manager->create(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20'));
    $butter = $list->items()->firstWhere('label', 'Beurre');

    expect($butter->origin)->toBe(ItemOrigin::Restock)
        ->and((float) $butter->quantity)->toBe(150.0)
        ->and($butter->stock_note)->toBe('Stock bas : reste 100 g · minimum 250 g');

    // Page Stock : filtre et ajout à la liste en cours (déjà présent → rien d'ajouté)
    Livewire::test(StockIndex::class)
        ->assertSee('Sous le minimum')
        ->set('filter', 'minimum')
        ->assertSee('reste 100 g · minimum 250 g')
        ->assertSee('Ajouter à la liste en cours')
        ->call('addMinimumToShoppingList')
        ->assertDispatched('notify', message: "Tout est déjà dans « {$list->name} ».");

    $butter->delete();
    Livewire::test(StockIndex::class)->call('addMinimumToShoppingList')
        ->assertDispatched('notify', message: "1 article ajouté à « {$list->name} ».");
});

/* ================================================================ 9.11 Inventaire */

test('inventaire d\'un emplacement : toujours là, quantité, plus là, terminer', function () {
    $milk = $this->stock->add(['ingredient_id' => sp('Beurre')->id, 'quantity' => 250]);
    $eggs = $this->stock->add(['ingredient_id' => sp('Œuf')->id, 'quantity' => 6]);
    $cream = $this->stock->add(['ingredient_id' => sp('Crème fraîche épaisse')->id, 'quantity' => 20]);

    $this->get(route('stock.index', ['emplacement' => $this->fridge->id]))->assertOk()
        ->assertSee('Faire l\'inventaire', false)->assertSee('jamais inventorié');

    $this->get(route('stock.inventory', $this->fridge))->assertOk()->assertSee('Inventaire · Réfrigérateur');

    Livewire::test(Inventory::class, ['location' => $this->fridge])
        ->assertSee('0 / 3 vérifié')
        ->call('confirm', $milk->id)
        ->call('editQuantity', $eggs->id)
        ->assertSet('quantity', '6')
        ->set('quantity', 'abc')->call('saveQuantity')->assertHasErrors('quantity')
        ->set('quantity', '4')->call('saveQuantity')->assertHasNoErrors()
        ->call('gone', $cream->id)
        ->assertSee('3 / 3 vérifiés')
        ->assertSee('retiré du stock')
        ->call('finish')
        ->assertRedirect(route('stock.index', ['emplacement' => $this->fridge->id]));

    expect((float) $eggs->fresh()->quantity)->toBe(4.0)
        ->and($cream->fresh()->finished_at)->not->toBeNull()
        ->and(StockMovement::where('stock_item_id', $cream->id)->latest('id')->value('reason'))->toBe('inventaire')
        ->and($this->fridge->fresh()->last_inventory_at)->not->toBeNull();
});
