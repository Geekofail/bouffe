<?php

use App\Enums\ReminderType;
use App\Livewire\Layout\Notifications;
use App\Models\Ingredient;
use App\Models\MealSlot;
use App\Models\Recipe;
use App\Models\Reminder;
use App\Models\StorageLocation;
use App\Models\User;
use App\Services\Planning\PrepReminderPlanner;
use App\Services\Planning\WeekPlanner;
use App\Support\Settings;
use Database\Seeders\AisleSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 09:00'));       // mercredi
    $this->actingAs(User::factory()->create(['name' => 'Pierre']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class]);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 2]);
    $this->planner = app(WeekPlanner::class);
    $this->reminders = app(PrepReminderPlanner::class);
    $this->freezer = StorageLocation::query()->where('type', \App\Enums\LocationType::Freezer->value)->firstOrFail();
    $this->fridge = StorageLocation::query()->where('type', \App\Enums\LocationType::Fresh->value)->firstOrFail();
    $this->stock = app(\App\Services\Stock\StockManager::class);
    Settings::flush();
});

/** Recette utilisant un ingrédient donné. */
function reminderRecipeWith(Ingredient $ingredient, array $attributes = []): Recipe
{
    $recipe = Recipe::factory()->create($attributes);
    $recipe->ingredients()->create(['ingredient_id' => $ingredient->id, 'quantity' => 2, 'sort_order' => 1]);

    return $recipe;
}

test('un ingrédient qui n\'est qu\'au congélateur donne un rappel la veille', function () {
    $poulet = Ingredient::factory()->create(['name' => 'Blanc de poulet']);
    $this->stock->add(['ingredient_id' => $poulet->id, 'storage_location_id' => $this->freezer->id, 'quantity' => 4]);

    $meal = $this->planner->addRecipe('2026-09-18', $this->dinner, reminderRecipeWith($poulet));
    $this->reminders->syncMeal($meal);

    $reminder = Reminder::query()->where('planned_meal_id', $meal->id)->first();

    expect($reminder)->not->toBeNull()
        ->and($reminder->type)->toBe(ReminderType::Thaw)
        ->and($reminder->title)->toBe('Sortir blanc de poulet du congélateur')
        ->and($reminder->due_at->toDateTimeString())->toBe('2026-09-17 18:00:00');
});

test('le même ingrédient disponible aussi au frais ne donne aucun rappel', function () {
    $poulet = Ingredient::factory()->create(['name' => 'Blanc de poulet']);
    $this->stock->add(['ingredient_id' => $poulet->id, 'storage_location_id' => $this->freezer->id, 'quantity' => 4]);
    $this->stock->add(['ingredient_id' => $poulet->id, 'storage_location_id' => $this->fridge->id, 'quantity' => 2]);

    $meal = $this->planner->addRecipe('2026-09-18', $this->dinner, reminderRecipeWith($poulet));
    $this->reminders->syncMeal($meal);

    expect(Reminder::count())->toBe(0);
});

test('un repos long remonte le temps depuis l\'heure du repas', function () {
    $recipe = Recipe::factory()->create(['title' => 'Pâte à pizza', 'prep_minutes' => 20, 'rest_minutes' => 720]);
    $meal = $this->planner->addRecipe('2026-09-18', $this->dinner, $recipe);

    $this->reminders->syncMeal($meal);
    $reminder = Reminder::query()->where('key', 'rest')->first();

    // Repas à 19 h, 12 h de repos + 20 min de préparation → le matin même à 6 h 40.
    expect($reminder->type)->toBe(ReminderType::Rest)
        ->and($reminder->due_at->toDateTimeString())->toBe('2026-09-18 06:40:00')
        ->and($reminder->detail)->toContain('Repos de');
});

test('une étape qui parle de tremper ou de la veille donne un rappel', function () {
    $recipe = Recipe::factory()->create(['title' => 'Houmous maison']);
    $recipe->steps()->create(['position' => 1, 'instruction' => 'La veille, mettre les pois chiches à tremper dans un grand volume d\'eau.']);
    $recipe->steps()->create(['position' => 2, 'instruction' => 'Mixer avec le tahini.']);

    $meal = $this->planner->addRecipe('2026-09-18', $this->dinner, $recipe);
    $this->reminders->syncMeal($meal);

    $reminders = Reminder::query()->where('planned_meal_id', $meal->id)->get();

    expect($reminders)->toHaveCount(1)
        ->and($reminders->first()->due_at->toDateTimeString())->toBe('2026-09-17 18:00:00')
        ->and($reminders->first()->detail)->toContain('pois chiches');
});

test('déplacer le repas décale le rappel, le supprimer l\'efface', function () {
    $recipe = Recipe::factory()->create(['rest_minutes' => 720, 'prep_minutes' => 0]);
    $meal = $this->planner->addRecipe('2026-09-18', $this->dinner, $recipe);
    $this->reminders->syncMeal($meal);

    $this->planner->move($meal->fresh(), '2026-09-19', $this->dinner, 0);
    $this->reminders->syncMeal($meal->fresh());

    expect(Reminder::query()->first()->due_at->toDateTimeString())->toBe('2026-09-19 07:00:00');

    $this->planner->delete($meal->fresh());

    expect(Reminder::count())->toBe(0);
});

test('« fait » est conservé quand les rappels sont recalculés', function () {
    $poulet = Ingredient::factory()->create(['name' => 'Blanc de poulet']);
    $this->stock->add(['ingredient_id' => $poulet->id, 'storage_location_id' => $this->freezer->id, 'quantity' => 4]);

    $meal = $this->planner->addRecipe('2026-09-18', $this->dinner, reminderRecipeWith($poulet));
    $this->reminders->syncMeal($meal);

    $reminder = Reminder::query()->first();
    $this->reminders->markDone($reminder);

    $this->reminders->syncMeal($meal->fresh());

    expect(Reminder::query()->first()->status)->toBe(Reminder::DONE)
        ->and(Reminder::count())->toBe(1);
});

test('un repas déjà mangé n\'a plus de rappel', function () {
    $recipe = Recipe::factory()->create(['rest_minutes' => 720]);
    $meal = $this->planner->addRecipe('2026-09-18', $this->dinner, $recipe);
    $this->reminders->syncMeal($meal);

    expect(Reminder::count())->toBe(1);

    $this->planner->toggleCooked($meal);
    $this->reminders->syncMeal($meal->fresh());

    expect(Reminder::count())->toBe(0);
});

test('l\'heure des rappels est réglable', function () {
    Settings::set('planning.reminder_hour', 9);

    $recipe = Recipe::factory()->create();
    $recipe->steps()->create(['position' => 1, 'instruction' => 'Faire mariner la viande la veille.']);

    $meal = $this->planner->addRecipe('2026-09-18', $this->dinner, $recipe);
    $this->reminders->syncMeal($meal);

    expect(Reminder::query()->first()->due_at->toDateTimeString())->toBe('2026-09-17 09:00:00');
});

/* ================================================================ 19.1 — centre de notifications */

test('la cloche rassemble les rappels et permet de les traiter', function () {
    $recipe = Recipe::factory()->create(['title' => 'Chili']);
    $recipe->steps()->create(['position' => 1, 'instruction' => 'La veille, faire tremper les haricots.']);

    $meal = $this->planner->addRecipe('2026-09-17', $this->dinner, $recipe);
    $this->reminders->syncMeal($meal);

    $component = Livewire::test(Notifications::class)
        ->call('show')
        ->assertSee('Faire tremper')
        ->assertSet('count', 1);

    $component->call('markDone', Reminder::query()->first()->id)
        ->assertSet('count', 0);

    expect(Reminder::query()->first()->status)->toBe(Reminder::DONE);
});

test('un rappel ignoré ne revient pas', function () {
    $recipe = Recipe::factory()->create();
    $recipe->steps()->create(['position' => 1, 'instruction' => 'Mettre à mariner la veille.']);

    $meal = $this->planner->addRecipe('2026-09-17', $this->dinner, $recipe);
    $this->reminders->syncMeal($meal);

    Livewire::test(Notifications::class)->call('show')->call('ignore', Reminder::query()->first()->id);

    $this->reminders->syncMeal($meal->fresh());

    expect(Reminder::query()->first()->status)->toBe(Reminder::IGNORED)
        ->and(app(PrepReminderPlanner::class)->due())->toHaveCount(0);
});

test('le planning signale les cases qui demandent une préparation', function () {
    $recipe = Recipe::factory()->create();
    $recipe->steps()->create(['position' => 1, 'instruction' => 'La veille, préparer la marinade.']);

    $meal = $this->planner->addRecipe('2026-09-18', $this->dinner, $recipe);
    $this->reminders->syncMeal($meal);

    $byCell = $this->reminders->forWeek(Carbon::parse('2026-09-14'));

    expect($byCell)->toHaveKey('2026-09-18|'.$this->dinner->id)
        ->and($byCell->get('2026-09-18|'.$this->dinner->id))->toHaveCount(1);
});
