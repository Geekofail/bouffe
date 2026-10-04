<?php

use App\Livewire\Layout\Notifications as Bell;
use App\Livewire\Layout\QuickAdd;
use App\Livewire\Planner\Week;
use App\Livewire\Recipes\Cook;
use App\Livewire\Settings\StockSettings;
use App\Livewire\Stock\Index as StockIndex;
use App\Livewire\Stock\MealStockDialog;
use App\Models\Ingredient;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\StockItem;
use App\Models\StockUsageRule;
use App\Models\User;
use App\Services\Planning\MealClosing;
use App\Services\Planning\WeekPlanner;
use App\Services\Shopping\ShoppingListManager;
use App\Services\Stock\MealStockService;
use App\Services\Stock\StockDiscrepancies;
use App\Services\Stock\StockManager;
use App\Services\Stock\StockReservations;
use App\Services\Stock\StockUsage;
use App\Support\Settings;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Stock toujours juste (lot 21 — module 22 du document 07, règles R23 et R24).
 */

require_once __DIR__.'/../Shopping/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00'));      // mercredi
    $this->actingAs(User::factory()->create(['name' => 'Pierre']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);

    $this->stock = app(StockManager::class);
    $this->planner = app(WeekPlanner::class);
    $this->closing = app(MealClosing::class);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner']);

    // Omelette pour 2 : 4 œufs.
    $this->omelette = recipeWith('Omelette', 2, [[4, 'piece', 'Œuf', false]]);
    $this->oeuf = Ingredient::firstWhere('name', 'Œuf');
});

function eggs(StockManager $stock, int $count = 6): StockItem
{
    return $stock->add(['ingredient_id' => Ingredient::firstWhere('name', 'Œuf')->id, 'quantity' => $count]);
}

/* ================================================================ Clôture des repas (R23) */

test('un repas d\'hier ni mangé ni « pas fait » est à clôturer', function () {
    $hier = $this->planner->addRecipe('2026-10-13', $this->dinner, $this->omelette, 2);
    $this->planner->addFree('2026-10-13', $this->dinner, 'Restaurant');
    $vieux = $this->planner->addRecipe('2026-09-20', $this->dinner, $this->omelette, 2);      // plus de 14 jours
    $mange = $this->planner->addRecipe('2026-10-12', $this->dinner, $this->omelette, 2);
    $this->planner->toggleCooked($mange);

    expect($this->closing->pending()->pluck('id')->all())->toBe([$hier->id]);

    $this->closing->skip($hier);

    expect($this->closing->pending())->toBeEmpty()
        ->and($hier->fresh()->isSkipped())->toBeTrue();
});

test('avant 7 h, le repas de la veille n\'est pas encore demandé', function () {
    $this->planner->addRecipe('2026-10-13', $this->dinner, $this->omelette, 2);
    $this->travelTo(Carbon::parse('2026-10-14 06:30'));

    expect($this->closing->pending())->toBeEmpty();
});

test('la clôture automatique marque mangé et retire le stock au bout de N jours', function () {
    $item = eggs($this->stock);
    $ancien = $this->planner->addRecipe('2026-10-11', $this->dinner, $this->omelette, 2);
    $hier = $this->planner->addRecipe('2026-10-13', $this->dinner, $this->omelette, 2);

    expect($this->closing->autoClose())->toBe(0);                 // « Toujours demander » par défaut

    Settings::set('stock.auto_close_days', 2);

    expect($this->closing->autoClose())->toBe(1)
        ->and($ancien->fresh()->cooked_at)->not->toBeNull()
        ->and($ancien->fresh()->closed_automatically)->toBeTrue()
        ->and((float) $item->fresh()->quantity)->toBe(2.0)
        ->and($hier->fresh()->cooked_at)->toBeNull();

    // « Pas mangé, en fait » : le stock revient, le repas est noté non fait.
    $this->closing->undoAutoClose($ancien->fresh());

    expect((float) $item->fresh()->quantity)->toBe(6.0)
        ->and($ancien->fresh()->isSkipped())->toBeTrue();
});

test('un repas non fait se replace dans la prochaine case libre', function () {
    $hier = $this->planner->addRecipe('2026-10-13', $this->dinner, $this->omelette, 2);
    $this->planner->addFree('2026-10-14', $this->dinner, 'Restaurant');

    $copy = $this->closing->reschedule($hier);

    expect($copy->date->toDateString())->toBe('2026-10-15')
        ->and($copy->recipe_id)->toBe($this->omelette->id)
        ->and($hier->fresh()->isSkipped())->toBeTrue();
});

test('la cloche et l\'accueil demandent « C\'était mangé ? »', function () {
    $hier = $this->planner->addRecipe('2026-10-13', $this->dinner, $this->omelette, 2);

    Livewire::test(Bell::class)
        ->call('show')
        ->assertSee('C\'était mangé ?', false)
        ->call('closeEaten', $hier->id)
        ->assertDispatched('meal-cooked');

    expect($hier->fresh()->cooked_at)->not->toBeNull();

    $other = $this->planner->addRecipe('2026-10-12', $this->dinner, $this->omelette, 2);

    Livewire::test(\App\Livewire\Dashboard::class)
        ->assertSee('C\'était mangé ?', false)
        ->call('closeSkipped', $other->id)
        ->assertDispatched('notify');

    expect($other->fresh()->isSkipped())->toBeTrue();
});

test('« Pas mangé » depuis le planning', function () {
    $hier = $this->planner->addRecipe('2026-10-13', $this->dinner, $this->omelette, 2);

    Livewire::test(Week::class)->call('toggleSkipped', $hier->id);
    expect($hier->fresh()->isSkipped())->toBeTrue();

    // Marquer mangé ensuite efface le « pas fait ».
    $this->planner->toggleCooked($hier->fresh());
    expect($hier->fresh()->isSkipped())->toBeFalse();
});

/* ================================================================ Retrait jamais perdu (22.2) */

test('une fenêtre de retrait fermée sans réponse laisse le repas « à régulariser »', function () {
    Settings::set('stock.deduction_mode', 'ask');
    eggs($this->stock);
    $meal = $this->planner->addRecipe('2026-10-14', $this->dinner, $this->omelette, 2);
    $this->planner->toggleCooked($meal);

    $dialog = Livewire::test(MealStockDialog::class)->call('onMealCooked', $meal->id, true)->assertSet('show', true);

    expect($meal->fresh()->stock_state)->toBe('pending');

    $dialog->call('close');
    Livewire::test(Bell::class)->call('show')->assertSee('Stock à régulariser');

    Livewire::test(MealStockDialog::class)->call('settle', $meal->id)->assertSet('show', true)->call('confirm');

    expect($meal->fresh()->stock_state)->toBe('done');
});

test('« Ne rien retirer » est un choix : plus de signalement', function () {
    Settings::set('stock.deduction_mode', 'ask');
    eggs($this->stock);
    $meal = $this->planner->addRecipe('2026-10-14', $this->dinner, $this->omelette, 2);
    $this->planner->toggleCooked($meal);

    Livewire::test(MealStockDialog::class)->call('onMealCooked', $meal->id, true)->call('ignore');

    expect($meal->fresh()->stock_state)->toBe('ignored');
});

test('en mode automatique, le retrait est annoncé avec « Annuler »', function () {
    Settings::set('stock.deduction_mode', 'auto');
    $item = eggs($this->stock);
    $meal = $this->planner->addRecipe('2026-10-14', $this->dinner, $this->omelette, 2);
    $this->planner->toggleCooked($meal);

    Livewire::test(MealStockDialog::class)
        ->call('onMealCooked', $meal->id, true)
        ->assertSet('show', false)
        ->assertDispatched('notify', fn ($name, $params) => ($params['action']['event'] ?? null) === 'undo-meal-stock');

    expect((float) $item->fresh()->quantity)->toBe(2.0)
        ->and($meal->fresh()->stock_state)->toBe('done');

    Livewire::test(MealStockDialog::class)->call('undo', $meal->id);

    expect((float) $item->fresh()->quantity)->toBe(6.0)
        ->and($meal->fresh()->stock_state)->toBe('pending');
});

/* ================================================================ Stock réservé (R24) */

test('les repas prévus réservent le stock, dans l\'ordre où ils seront mangés', function () {
    $item = eggs($this->stock);
    $jeudi = $this->planner->addRecipe('2026-10-15', $this->dinner, $this->omelette, 2);   // 4 œufs
    $samedi = $this->planner->addRecipe('2026-10-17', $this->dinner, $this->omelette, 2);  // 4 œufs

    $result = app(StockReservations::class)->compute();

    expect($result['items'][$item->id]['reserved'])->toBe(6.0)
        ->and($result['meals'])->toHaveKey($samedi->id)
        ->and($result['meals'])->not->toHaveKey($jeudi->id)
        ->and($result['meals'][$samedi->id][0]['missing'])->toBe('2 œufs')
        ->and($result['meals'][$samedi->id][0]['taken_by'][0])->toStartWith('Omelette (jeu.');
});

test('un manque qui n\'est pas dû à un autre repas n\'est pas un conflit', function () {
    eggs($this->stock, 2);
    $meal = $this->planner->addRecipe('2026-10-15', $this->dinner, $this->omelette, 2);

    expect(app(StockReservations::class)->compute()['meals'])->toBe([]);
});

test('un repas mangé, non fait ou préparé ne réserve rien', function () {
    $item = eggs($this->stock);
    $meal = $this->planner->addRecipe('2026-10-15', $this->dinner, $this->omelette, 2);
    $this->closing->skip($meal);

    expect(app(StockReservations::class)->compute()['items'])->toBe([]);
});

test('la liste de courses ne compte pas le stock promis aux repas d\'avant', function () {
    eggs($this->stock);                                                                    // 6 œufs
    $this->planner->addRecipe('2026-10-15', $this->dinner, $this->omelette, 2);            // jeudi : 4 œufs, hors liste
    $this->planner->addRecipe('2026-10-19', $this->dinner, $this->omelette, 2);            // lundi : 4 œufs

    $manager = app(ShoppingListManager::class);
    $list = $manager->create(Carbon::parse('2026-10-19'), Carbon::parse('2026-10-25'));
    $item = $list->items()->where('ingredient_id', $this->oeuf->id)->first();

    // Il reste 2 œufs après jeudi : il faut en acheter 2, pas 0.
    expect($item->stock_status)->toBe('partial')
        ->and((float) $item->quantity)->toBe(2.0);
});

test('le stock affiche ce qui est réservé, et le planning signale le conflit', function () {
    eggs($this->stock);
    $this->planner->addRecipe('2026-10-15', $this->dinner, $this->omelette, 2);
    $samedi = $this->planner->addRecipe('2026-10-17', $this->dinner, $this->omelette, 2);

    Livewire::test(StockIndex::class)->assertSee('6 œufs réservés');

    Livewire::test(Week::class)
        ->call('selectMeal', $samedi->id)
        ->assertSee('il en manquera 2 œufs');
});

/* ================================================================ Hors repas (22.4) */

test('« J\'ai utilisé 2 œufs et 20 cl de lait » retire du stock', function () {
    $item = eggs($this->stock);
    $lait = $this->stock->add(['ingredient_id' => Ingredient::firstWhere('name', 'Lait demi-écrémé')->id, 'quantity' => 1, 'unit_id' => \App\Models\Unit::firstWhere('code', 'l')->id]);

    $results = app(StockUsage::class)->useText('2 œufs et 20 cl de lait, 3 licornes');

    expect($results[0]['status'])->toBe('ok')
        ->and((float) $item->fresh()->quantity)->toBe(4.0)
        ->and($results[1]['status'])->toBe('ok')
        ->and((float) $lait->fresh()->quantity)->toBe(0.8)
        ->and($results[2]['status'])->toBe('notfound');

    Livewire::test(QuickAdd::class)
        ->call('open', 'use')
        ->set('text', '5 œufs')
        ->call('useFromStock')
        ->assertSee('il en manquait 1');

    expect($item->fresh()->finished_at)->not->toBeNull();
});

test('une consommation régulière est retirée chaque jour concerné', function () {
    $lait = $this->stock->add(['ingredient_id' => Ingredient::firstWhere('name', 'Lait demi-écrémé')->id, 'quantity' => 2, 'unit_id' => \App\Models\Unit::firstWhere('code', 'l')->id]);

    Livewire::test(StockSettings::class)
        ->set('ruleText', '25 cl de lait')
        ->set('ruleEvery', 1)
        ->call('addRule')
        ->assertHasNoErrors();

    $usage = app(StockUsage::class);
    expect($usage->applyRules(Carbon::parse('2026-10-14')))->toBe(0);          // le jour même : rien

    expect($usage->applyRules(Carbon::parse('2026-10-17')))->toBe(1)           // 3 jours plus tard
        ->and((float) $lait->fresh()->quantity)->toBe(1.25)
        ->and($usage->applyRules(Carbon::parse('2026-10-17')))->toBe(0)        // une seule fois par jour
        ->and(StockUsageRule::first()->last_applied_on->toDateString())->toBe('2026-10-17');
});

/* ================================================================ Écarts (22.5) */

test('les articles qui ont probablement dérivé sont proposés à la vérification', function () {
    $perime = $this->stock->add(['ingredient_id' => Ingredient::firstWhere('name', 'Beurre')->id, 'quantity' => 250, 'expires_on' => '2026-10-01', 'expiry_type' => 'dlc']);
    $fond = eggs($this->stock);
    $this->stock->setQuantity($fond, 0.5);
    $ok = eggs($this->stock);

    $service = app(StockDiscrepancies::class);
    $ids = $service->toCheck()->pluck('item.id')->all();

    expect($ids)->toBe([$perime->id, $fond->id]);

    Livewire::test(StockIndex::class)
        ->assertSee('2 articles à vérifier')
        ->set('showCheck', true)
        ->assertSee('Date dépassée depuis 13 jours')
        ->call('markChecked', $perime->id);

    expect($service->toCheck()->pluck('item.id')->all())->toBe([$fond->id]);
});

/* ================================================================ Mode cuisine (22.6) */

test('au fil du mode cuisine, cocher un ingrédient le retire, et « mangé » ne le retire pas deux fois', function () {
    Settings::set('stock.cook_mode_live', true);
    $item = eggs($this->stock);
    $meal = $this->planner->addRecipe('2026-10-14', $this->dinner, $this->omelette, 2);

    $page = Livewire::withQueryParams(['repas' => $meal->id])->test(Cook::class, ['recipe' => $this->omelette]);
    $lineId = $page->instance()->lines->first()['id'];
    $page->call('toggleLine', $lineId);

    expect((float) $item->fresh()->quantity)->toBe(2.0)
        ->and(app(MealStockService::class)->plan($meal->fresh()))->toBe([]);
});

test('rattrapage : tout marquer mangé sans toucher au stock', function () {
    $item = eggs($this->stock);
    foreach (['2026-10-11', '2026-10-12', '2026-10-13'] as $date) {
        $this->planner->addRecipe($date, $this->dinner, $this->omelette, 2);
    }

    Livewire::test(\App\Livewire\Dashboard::class)
        ->assertSee('Tout marquer mangé, sans toucher au stock')
        ->call('closeAllWithoutStock');

    expect($this->closing->pending())->toBeEmpty()
        ->and((float) $item->fresh()->quantity)->toBe(6.0)
        ->and(PlannedMeal::where('stock_state', 'ignored')->count())->toBe(3);
});

test('un article périmé avant le repas n\'est pas réservé', function () {
    $vieux = $this->stock->add(['ingredient_id' => $this->oeuf->id, 'quantity' => 6, 'expires_on' => '2026-10-14', 'expiry_type' => 'dlc']);
    $this->planner->addRecipe('2026-10-16', $this->dinner, $this->omelette, 2);

    expect(app(StockReservations::class)->compute()['items'])->not->toHaveKey($vieux->id);
});
