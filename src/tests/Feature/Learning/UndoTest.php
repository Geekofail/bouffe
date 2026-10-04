<?php

use App\Livewire\Layout\Undo;
use App\Livewire\Planner\Week;
use App\Livewire\Prices\Index as PricesIndex;
use App\Livewire\Shopping\Index as ShoppingIndex;
use App\Livewire\Shopping\Show as ShoppingShow;
use App\Models\ActivityEvent;
use App\Models\Ingredient;
use App\Models\MealReaction;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\ShoppingList;
use App\Models\UndoToken;
use App\Models\User;
use App\Services\Planning\WeekPlanner;
use App\Services\Pricing\PriceBook;
use App\Services\Shopping\ShoppingListManager;
use App\Services\Undo\UndoManager;
use App\Services\Undo\UndoRecorder;
use App\Services\Undo\UndoRefused;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Annuler partout (lot 30 — 30.1, règle R32).
 */

require_once __DIR__.'/../Shopping/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00'));     // mercredi
    $this->user = User::factory()->create(['name' => 'Pierre']);
    $this->actingAs($this->user);
    $this->seed([UnitSeeder::class, AisleSeeder::class, IngredientSeeder::class]);

    $this->slot = MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 1]);
    $this->planner = app(WeekPlanner::class);
    $this->chili = recipeWith('Chili', 4, [[500, 'g', 'Bœuf haché', false], [2, 'piece', 'Oignon', false]]);
    $this->soupe = recipeWith('Soupe de potiron', 4, [[800, 'g', 'Potiron', false]]);
});

/** Jeton d'annulation proposé par le dernier message. */
function undoTokenFrom($component): string
{
    $token = null;
    $component->assertDispatched('notify', function (string $name, array $params) use (&$token) {
        $token = $params['action']['params']['token'] ?? $token;

        return isset($params['action']);
    });

    return $token;
}

/** L'état de la table, pour comparer avant / après. */
function tableState(string $table): array
{
    return DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
}

test('vider la semaine, puis annuler : tout revient, restes et réactions compris', function () {
    $chili = $this->planner->addRecipe('2026-10-13', $this->slot, $this->chili, 4);
    $leftover = $this->planner->addLeftover('2026-10-19', $this->slot, $chili, 2);   // semaine suivante
    $this->planner->addRecipe('2026-10-15', $this->slot, $this->soupe, 2);
    MealReaction::create(['planned_meal_id' => $chili->id, 'user_id' => $this->user->id, 'value' => 1]);

    $before = tableState('planned_meals');
    $reactions = tableState('meal_reactions');

    $week = Livewire::test(Week::class)->call('clearWeek');
    expect(PlannedMeal::count())->toBe(0)
        ->and(MealReaction::count())->toBe(0);

    Livewire::test(Undo::class)->call('undo', undoTokenFrom($week))
        ->assertDispatched('bouffe-undone')
        ->assertDispatched('notify', message: 'Annulé : semaine du 12 octobre vidée.');

    expect(tableState('planned_meals'))->toBe($before)
        ->and(tableState('meal_reactions'))->toBe($reactions)
        ->and(PlannedMeal::find($leftover->id)->leftover_of_id)->toBe($chili->id);
});

test('une annulation ne sert qu\'une fois, et pas après le délai', function () {
    $this->planner->addRecipe('2026-10-13', $this->slot, $this->chili, 4);

    $token = undoTokenFrom(Livewire::test(Week::class)->call('clearWeek'));
    app(UndoManager::class)->undo($token);

    expect(fn () => app(UndoManager::class)->undo($token))->toThrow(UndoRefused::class, 'déjà été annulée');

    $token = undoTokenFrom(Livewire::test(Week::class)->call('clearWeek'));
    $this->travel(UndoManager::GRACE_SECONDS + 5)->seconds();

    expect(fn () => app(UndoManager::class)->undo($token))->toThrow(UndoRefused::class, 'Trop tard');
});

test('si quelqu\'un a changé ces éléments entre-temps, l\'annulation est refusée et le dit', function () {
    $meal = $this->planner->addRecipe('2026-10-13', $this->slot, $this->chili, 4);
    $other = $this->planner->addRecipe('2026-10-14', $this->slot, $this->soupe, 2);

    $week = Livewire::test(Week::class)->call('moveMeal', $meal->id, 0, '2026-10-14|'.$this->slot->id);
    expect($meal->fresh()->date->toDateString())->toBe('2026-10-14');

    // Monique modifie les portions de la soupe, dans la case d'arrivée.
    $this->travel(2)->seconds();
    $other->fresh()->update(['servings' => 6]);

    Livewire::test(Undo::class)->call('undo', undoTokenFrom($week))
        ->assertDispatched('notify', type: 'warning', message: 'Impossible d\'annuler : ces éléments ont été modifiés entre-temps.')
        ->assertNotDispatched('bouffe-undone');

    expect($meal->fresh()->date->toDateString())->toBe('2026-10-14');
});

test('un repas déplacé revient à sa place et à son rang', function () {
    $a = $this->planner->addRecipe('2026-10-13', $this->slot, $this->chili, 4);
    $b = $this->planner->addFree('2026-10-13', $this->slot, 'Salade');
    $c = $this->planner->addFree('2026-10-16', $this->slot, 'Pizza');
    $before = tableState('planned_meals');

    $week = Livewire::test(Week::class)->call('moveMeal', $a->id, 0, '2026-10-16|'.$this->slot->id);
    expect($a->fresh()->date->toDateString())->toBe('2026-10-16')
        ->and($b->fresh()->position)->toBe(1);

    app(UndoManager::class)->undo(undoTokenFrom($week));

    expect(tableState('planned_meals'))->toBe($before);
});

test('un repas retiré revient avec ses restes', function () {
    $chili = $this->planner->addRecipe('2026-10-13', $this->slot, $this->chili, 4);
    $this->planner->addLeftover('2026-10-15', $this->slot, $chili, 2);
    $before = tableState('planned_meals');

    $week = Livewire::test(Week::class)->call('deleteMeal', $chili->id);
    expect(PlannedMeal::count())->toBe(0);

    app(UndoManager::class)->undo(undoTokenFrom($week));
    expect(tableState('planned_meals'))->toBe($before);
});

test('copier une semaine en remplaçant, puis annuler : les copies partent, la semaine d\'avant revient', function () {
    $this->planner->addRecipe('2026-10-13', $this->slot, $this->chili, 4);
    $this->planner->addFree('2026-10-20', $this->slot, 'Crêpes');     // semaine cible
    $before = tableState('planned_meals');

    $week = Livewire::test(Week::class)
        ->set('copyTarget', '2026-10-19')->set('copyMode', 'replace')->call('copyWeek');

    expect(PlannedMeal::whereDate('date', '2026-10-20')->pluck('free_text')->filter()->all())->toBe([]);

    app(UndoManager::class)->undo(undoTokenFrom($week));
    expect(tableState('planned_meals'))->toBe($before);
});

test('liste de courses : article retiré, liste mise à jour, liste supprimée — tout s\'annule', function () {
    $this->planner->addRecipe('2026-10-15', $this->slot, $this->chili, 4);
    $manager = app(ShoppingListManager::class);
    $list = $manager->create(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-18'));
    $item = $list->items()->where('label', 'like', 'Oignon%')->first();
    $manager->toggleCheck($item);

    // Retirer un article (la coche revient aussi).
    $before = tableState('shopping_list_items');
    $page = Livewire::test(ShoppingShow::class, ['shoppingList' => $list])->call('remove', $item->id);
    expect($item->fresh()->is_removed)->toBeTrue();
    app(UndoManager::class)->undo(undoTokenFrom($page));
    expect(tableState('shopping_list_items'))->toBe($before);

    // Mise à jour depuis le planning après un changement de repas.
    $this->planner->addRecipe('2026-10-16', $this->slot, $this->soupe, 4);
    $page = Livewire::test(ShoppingShow::class, ['shoppingList' => $list])->call('regenerate');
    expect($list->items()->where('label', 'like', 'Potiron%')->exists())->toBeTrue();
    app(UndoManager::class)->undo(undoTokenFrom($page));
    expect(tableState('shopping_list_items'))->toBe($before);

    // Supprimer la liste entière.
    $lists = tableState('shopping_lists');
    $page = Livewire::test(ShoppingIndex::class)->call('delete', $list->id);
    expect(ShoppingList::count())->toBe(0);
    app(UndoManager::class)->undo(undoTokenFrom($page));
    expect(tableState('shopping_lists'))->toBe($lists)
        ->and(tableState('shopping_list_items'))->toBe($before);
});

test('une liste déjà à jour ne propose pas d\'annuler', function () {
    $this->planner->addRecipe('2026-10-15', $this->slot, $this->chili, 4);
    $list = app(ShoppingListManager::class)->create(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-18'));

    Livewire::test(ShoppingShow::class, ['shoppingList' => $list])->call('regenerate')
        ->assertDispatched('notify', message: 'La liste était déjà à jour.')
        ->assertNotDispatched('notify', fn ($name, $params) => isset($params['action']));
});

test('un relevé de prix supprimé revient, avec le prix de référence qu\'il donnait', function () {
    $butter = Ingredient::firstWhere('name', 'Beurre');
    $prices = app(PriceBook::class);
    $prices->record($butter, 2.00, 250, \App\Models\Unit::firstWhere('code', 'g'), null, Carbon::parse('2026-10-01'));
    $latest = $prices->record($butter, 2.50, 250, \App\Models\Unit::firstWhere('code', 'g'), null, Carbon::parse('2026-10-10'));
    $reference = (float) Ingredient::find($butter->id)->reference_price;

    $page = Livewire::test(PricesIndex::class)->call('deletePrice', $latest->id);
    expect((float) Ingredient::find($butter->id)->reference_price)->toBe(0.008);

    app(UndoManager::class)->undo(undoTokenFrom($page));
    \App\Models\HouseholdIngredientSetting::flush();

    expect(\App\Models\IngredientPrice::find($latest->id))->not->toBeNull()
        ->and((float) Ingredient::find($butter->id)->reference_price)->toBe($reference);
});

test('seule la personne qui a fait le geste peut l\'annuler', function () {
    $this->planner->addRecipe('2026-10-13', $this->slot, $this->chili, 4);
    $token = undoTokenFrom(Livewire::test(Week::class)->call('clearWeek'));

    $monique = User::factory()->create(['name' => 'Monique']);
    $this->actingAs($monique);

    expect(fn () => app(UndoManager::class)->undo($token))->toThrow(UndoRefused::class);
});

test('le message d\'annulation reste 10 secondes et l\'annulation est notée au journal', function () {
    $this->planner->addRecipe('2026-10-13', $this->slot, $this->chili, 4);

    $week = Livewire::test(Week::class)->call('clearWeek')
        ->assertDispatched('notify', fn ($name, $params) => ($params['duration'] ?? null) === 10000
            && $params['action']['label'] === 'Annuler' && $params['message'] === 'Semaine vidée (1 repas retiré).');

    app(UndoManager::class)->undo(undoTokenFrom($week));

    expect(ActivityEvent::pluck('summary')->all())->toContain('a vidé la semaine du 12 octobre (1 repas)', 'a annulé : semaine du 12 octobre vidée');
});

test('les jetons d\'annulation périmés sont oubliés', function () {
    app(UndoManager::class)->run('test', 'Essai', fn (UndoRecorder $r) => null);
    $this->travel(2)->days();
    app(UndoManager::class)->run('test', 'Essai', fn (UndoRecorder $r) => null);

    expect(UndoToken::count())->toBe(1);
});
