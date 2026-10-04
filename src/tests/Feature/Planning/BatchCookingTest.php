<?php

use App\Enums\LocationType;
use App\Livewire\Planner\BatchCook;
use App\Models\Ingredient;
use App\Models\MealSlot;
use App\Models\Reminder;
use App\Models\StockItem;
use App\Models\StorageLocation;
use App\Models\User;
use App\Services\Planning\BatchCooking;
use App\Services\Planning\PrepReminderPlanner;
use App\Services\Planning\WeekPlanner;
use App\Services\Shopping\ShoppingListGenerator;
use App\Services\Stock\MealStockService;
use App\Services\Stock\StockManager;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Batch cooking (lot 20 — 14.8) : cuisiner à l'avance, ranger les plats liés à leurs repas.
 */

require_once __DIR__.'/../Shopping/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-11 10:00'));      // dimanche
    $this->actingAs(User::factory()->create(['name' => 'Pierre']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);

    $this->slot = MealSlot::factory()->create(['name' => 'Dîner']);
    $this->planner = app(WeekPlanner::class);
    $this->batch = app(BatchCooking::class);

    $this->chili = recipeWith('Chili', 4, [[500, 'g', 'Bœuf haché', false], [2, 'piece', 'Oignon', false]]);
    $this->chili->update(['prep_minutes' => 20, 'cook_minutes' => 90]);
    $this->soupe = recipeWith('Soupe de potiron', 4, [[800, 'g', 'Potiron', false], [1, 'piece', 'Oignon', false]]);
    $this->soupe->update(['prep_minutes' => 15, 'cook_minutes' => 30]);

    $this->mardi = $this->planner->addRecipe('2026-10-13', $this->slot, $this->chili, 2);
    $this->samedi = $this->planner->addRecipe('2026-10-17', $this->slot, $this->soupe, 2);
});

test('la session regroupe les ingrédients et lance d\'abord le plus long', function () {
    $session = $this->batch->session(collect([$this->samedi, $this->mardi]));

    expect($session['recipes']->first()['meal']->id)->toBe($this->mardi->id)       // chili : 1 h 50
        ->and($session['minutes'])->toBe(20 + 15 + 90)
        ->and(linesText($session['ingredients'])['Oignon'])->toBe('2 oignons');     // 1 + 0,5, arrondi comme aux courses
});

test('le rangement proposé dépend du jour du repas', function () {
    expect($this->batch->storageFor($this->mardi)['storage'])->toBe('fridge')      // dans 2 jours
        ->and($this->batch->storageFor($this->samedi)['storage'])->toBe('freezer'); // dans 6 jours
});

test('un plat prêt est rangé, lié à son repas, et ses ingrédients sortent du stock', function () {
    $stock = app(StockManager::class);
    $boeuf = $stock->add(['ingredient_id' => Ingredient::firstWhere('name', 'Bœuf haché')->id, 'quantity' => 500, 'unit_id' => \App\Models\Unit::firstWhere('code', 'g')->id]);

    $dish = $this->batch->prepare($this->samedi->fresh(), 'freezer');
    $this->batch->prepare($this->mardi->fresh(), 'fridge');

    expect($dish->planned_meal_id)->toBe($this->samedi->id)
        ->and($dish->location->type)->toBe(LocationType::Freezer)
        ->and($dish->isFrozen())->toBeTrue()
        ->and((float) $dish->quantity)->toBe(2.0)
        ->and($this->samedi->fresh()->isPrepared())->toBeTrue()
        // 250 g de bœuf ont servi au chili (2 portions sur 4).
        ->and((float) $boeuf->fresh()->quantity)->toBe(250.0);
});

test('un plat préparé ne revient pas dans la liste de courses', function () {
    $this->batch->prepare($this->mardi->fresh(), 'fridge', deduct: false);

    $generator = app(ShoppingListGenerator::class);
    $meals = $generator->meals(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-18'));

    expect($meals->pluck('id')->all())->toBe([$this->samedi->id]);
});

test('« mangé » retire le plat préparé et plus ses ingrédients ; le décocher le remet', function () {
    $this->batch->prepare($this->mardi->fresh(), 'fridge', deduct: false);
    $service = app(MealStockService::class);
    $meal = $this->mardi->fresh();

    expect($service->plan($meal))->toBe([]);

    $consumption = $service->leftoverConsumption($meal);
    expect($consumption['take'])->toBe(2.0);

    $this->planner->toggleCooked($meal);
    $service->consumeLeftovers($meal);

    expect(StockItem::active()->where('planned_meal_id', $meal->id)->count())->toBe(0);

    // Décoché : seul le plat revient, le « plat rangé » n'est pas considéré comme une déduction.
    $service->revert($meal);
    expect(StockItem::active()->where('planned_meal_id', $meal->id)->count())->toBe(1);
});

test('un plat préparé au congélateur déclenche « Décongeler » la veille', function () {
    $this->batch->prepare($this->samedi->fresh(), 'freezer', deduct: false);

    app(PrepReminderPlanner::class)->sync(Carbon::parse('2026-10-11'), Carbon::parse('2026-10-18'));

    $reminder = Reminder::where('planned_meal_id', $this->samedi->id)->first();

    expect($reminder->title)->toStartWith('Décongeler soupe de potiron')
        ->and($reminder->due_at->format('Y-m-d H:i'))->toBe('2026-10-16 18:00');
});

test('les restes d\'un plat congelé déclenchent aussi « Décongeler »', function () {
    $mardi = $this->planner->update($this->mardi->fresh(), ['servings' => 4]);
    $this->planner->toggleCooked($mardi);
    $leftover = app(MealStockService::class)->storeLeftovers($mardi->fresh(), 2);
    app(StockManager::class)->freeze($leftover);

    $restes = $this->planner->addLeftover('2026-10-16', $this->slot, $mardi, 2);
    app(PrepReminderPlanner::class)->sync(Carbon::parse('2026-10-11'), Carbon::parse('2026-10-18'));

    expect(Reminder::where('planned_meal_id', $restes->id)->first()?->title)->toContain('Décongeler');
})->skip(fn () => ! StorageLocation::firstOfType(LocationType::Freezer), 'Pas de congélateur');

test('l\'écran de la session : choisir, cuisiner, annuler', function () {
    $page = Livewire::test(BatchCook::class)
        ->assertSee('Chili')
        ->set('selected', [$this->mardi->id, $this->samedi->id])
        ->call('start')
        ->assertSet('mealIds', $this->mardi->id.','.$this->samedi->id)
        ->assertSee('Tout sortir');

    $page->set('deduct', false)->call('prepare', $this->mardi->id)->assertDispatched('notify');

    expect($this->mardi->fresh()->isPrepared())->toBeTrue();

    $page->call('unprepare', $this->mardi->id);

    expect($this->mardi->fresh()->isPrepared())->toBeFalse()
        ->and(StockItem::active()->where('planned_meal_id', $this->mardi->id)->count())->toBe(0);
});
