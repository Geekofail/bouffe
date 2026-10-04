<?php

use App\Enums\Course;
use App\Enums\MealType;
use App\Enums\MovementType;
use App\Enums\UserRole;
use App\Livewire\Dashboard;
use App\Livewire\Kitchen\Screen as KitchenScreen;
use App\Livewire\Planner\Statistics;
use App\Livewire\Planner\YearInReview;
use App\Models\Ingredient;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Reminder;
use App\Models\ShoppingList;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Planning\PlanningStats;
use App\Services\Pricing\PriceBook;
use App\Services\Shopping\ShoppingListManager;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

require_once __DIR__.'/../Shopping/helpers.php';

/*
 * Lot 35 — Écran de cuisine (35.1), statistiques de planning (35.2), l'année en cuisine (35.3).
 */

/** Un plat au planning ; $state : mangé, pas fait, ou rien (à clôturer). */
function statMeal(string $date, MealSlot $slot, $recipe = null, ?string $state = 'eaten', array $extra = []): PlannedMeal
{
    return PlannedMeal::factory()->create([
        'date' => $date,
        'meal_slot_id' => $slot->id,
        'type' => MealType::Recipe,
        'recipe_id' => $recipe?->id,
        'cooked_at' => $state === 'eaten' ? Carbon::parse($date)->setTime(21, 0) : null,
        'skipped_at' => $state === 'skipped' ? Carbon::parse($date)->setTime(21, 0) : null,
        ...$extra,
    ]);
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 16:00'));   // jeudi
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);
    $this->actingAs($this->pierre = User::factory()->create(['name' => 'Pierre']));
    $this->lunch = MealSlot::factory()->create(['name' => 'Déjeuner', 'sort_order' => 1]);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 2]);

    $this->quiche = recipeWith('Quiche lorraine', 4, [[200, 'g', 'Lardons', false], [20, 'cl', 'Crème liquide', false]]);
    $this->tian = recipeWith('Tian de courgettes', 4, [[800, 'g', 'Courgette', false], [150, 'g', 'Feta', false]]);
    $this->chili = recipeWith('Chili con carne', 4, [[500, 'g', 'Bœuf haché', false], [400, 'g', 'Tomate', false]]);
    Ingredient::firstWhere('name', 'Courgette')->forceFill(['season_months' => [6, 7, 8, 9]])->save();
    Ingredient::firstWhere('name', 'Tomate')->forceFill(['season_months' => [7, 8, 9]])->save();
});

/* ================================================================ 35.2 Statistiques */

test('statistiques d\'une période : planning suivi, classement, nouveautés, végétarien, saison', function () {
    statMeal('2026-06-01', $this->dinner, $this->quiche);                    // avant la période : la quiche n'est pas nouvelle
    statMeal('2026-08-03', $this->dinner, $this->quiche);
    statMeal('2026-09-07', $this->dinner, $this->quiche);
    statMeal('2026-10-05', $this->dinner, $this->quiche);
    statMeal('2026-09-08', $this->dinner, $this->tian);                      // courgette de saison en septembre
    statMeal('2026-10-06', $this->dinner, $this->tian);                      // … plus en octobre
    statMeal('2026-10-06', $this->dinner, $this->chili, 'eaten', ['course' => Course::Starter]);   // même repas, entrée
    statMeal('2026-10-07', $this->lunch, $this->chili, 'skipped');
    statMeal('2026-10-08', $this->lunch, $this->chili, null);               // pas clôturé
    statMeal('2026-10-20', $this->dinner, $this->chili, null);              // à venir : ne compte pas
    PlannedMeal::factory()->free('Restaurant')->create(['date' => '2026-10-09', 'meal_slot_id' => $this->dinner->id, 'cooked_at' => now()]);

    $stats = app(PlanningStats::class)->period(Carbon::parse('2026-07-16'), Carbon::parse('2026-10-15'));

    expect($stats['dishes'])->toBe(7)                          // 6 recettes + 1 repas libre
        ->and($stats['occasions'])->toBe(6)                    // le 6 octobre : entrée + plat = un repas
        ->and($stats['followed'])->toBe(['planned' => 8, 'eaten' => 6, 'skipped' => 1, 'pending' => 1, 'share' => 75])
        ->and($stats['distinct'])->toBe(3)
        ->and($stats['new'])->toBe(['count' => 2, 'titles' => ['Tian de courgettes', 'Chili con carne']])
        ->and($stats['top']->map(fn ($row) => [$row['recipe']->title, $row['count']])->all())
        ->toBe([['Quiche lorraine', 3], ['Tian de courgettes', 2], ['Chili con carne', 1]]);

    // Plats principaux : 3 quiches + 2 tians (le chili en entrée ne compte pas). Seuls les tians sont sans viande.
    expect($stats['vegetarian'])->toMatchArray(['mains' => 5, 'count' => 2, 'share' => 40])
        ->and($stats['weeks'])->toBe(14)
        ->and(collect($stats['vegetarian']['weeks'])->sum('count'))->toBe(2)
        ->and(count($stats['vegetarian']['weeks']))->toBe(12);

    // Saison : tian (sept. oui, oct. non), chili (tomate en oct. : non) ; la quiche n'a ni fruit ni légume.
    expect($stats['season'])->toBe(['base' => 3, 'count' => 1, 'share' => 33]);
});

test('restes : repas de restes clôturés et plats préparés du stock, gestes annulés exclus', function () {
    $source = statMeal('2026-10-01', $this->dinner, $this->chili);
    statMeal('2026-10-02', $this->lunch, null, 'eaten', ['type' => MealType::Leftover, 'leftover_of_id' => $source->id]);
    statMeal('2026-10-03', $this->lunch, null, 'skipped', ['type' => MealType::Leftover, 'leftover_of_id' => $source->id]);
    statMeal('2026-10-04', $this->lunch, null, null, ['type' => MealType::Leftover, 'leftover_of_id' => $source->id]);   // pas clôturé

    $dish = fn (MovementType $type) => StockMovement::create(['label' => 'Lasagnes', 'type' => $type, 'created_at' => now()->subDays(3)]);
    $dish(MovementType::Consume);
    $dish(MovementType::Consume);
    $dish(MovementType::Waste);
    $undone = $dish(MovementType::Waste);
    StockMovement::create(['label' => 'Lasagnes', 'type' => MovementType::Undo, 'reverts_movement_id' => $undone->id, 'created_at' => now()->subDays(3)]);
    StockMovement::create(['ingredient_id' => Ingredient::firstWhere('name', 'Feta')->id, 'label' => 'Feta', 'type' => MovementType::Waste, 'created_at' => now()->subDays(2)]);

    $stats = app(PlanningStats::class)->period(Carbon::parse('2026-09-16'), Carbon::parse('2026-10-15'));

    expect($stats['leftovers'])->toBe(['meals' => 2, 'eaten' => 1, 'dishes' => 3, 'finished' => 2, 'wasted' => 1, 'share' => 60]);
    expect(app(PlanningStats::class)->wasted(Carbon::parse('2026-09-16'), Carbon::parse('2026-10-15')))
        ->toMatchArray(['count' => 2]);
});

test('page Statistiques : période, chiffres avec leur base, état vide', function () {
    $this->get(route('planner.stats'))->assertOk()->assertSee('Rien à compter sur cette période');

    statMeal('2026-10-05', $this->dinner, $this->quiche);
    statMeal('2026-10-06', $this->dinner, $this->tian);
    statMeal('2026-06-10', $this->dinner, $this->chili);

    Livewire::test(Statistics::class)
        ->assertSee('Plats mangés')
        ->assertSee('2 mangés sur 2 plats planifiés')
        ->assertSee('Quiche lorraine')
        ->assertDontSee('Chili con carne')
        ->call('setPeriod', '6m')
        ->assertSet('period', '6m')
        ->assertSee('Chili con carne')
        ->call('setPeriod', 'n-importe-quoi')
        ->assertSet('period', '3m');

    $this->get(route('planner.week'))->assertSee(route('planner.stats'), false);
});

/* ================================================================ 35.3 L'année en cuisine */

test('l\'année en cuisine : chiffres réels, comparaison avec la même période, texte à partager', function () {
    foreach (['2026-02-10', '2026-05-12', '2026-09-08'] as $date) {
        statMeal($date, $this->dinner, $this->quiche);
    }
    statMeal('2026-03-03', $this->dinner, $this->tian);
    statMeal('2025-04-01', $this->dinner, $this->chili);   // l'an dernier, avant le 15 octobre
    statMeal('2025-11-20', $this->dinner, $this->chili);   // l'an dernier, après : hors comparaison

    $stats = app(PlanningStats::class)->year(2026);

    expect($stats)->toMatchArray(['year' => 2026, 'complete' => false, 'dishes' => 4, 'distinct' => 2])
        ->and($stats['new']['count'])->toBe(2)
        ->and($stats['previous']['dishes'])->toBe(1)
        ->and($stats['previous']['to']->toDateString())->toBe('2025-10-15')
        ->and(collect($stats['months'])->pluck('dishes')->all())->toBe([0, 1, 1, 0, 1, 0, 0, 0, 1, 0]);

    $text = YearInReview::shareText($stats, app(PriceBook::class));
    expect($text)->toContain('Notre année 2026 en cuisine (au 15 octobre) : 4 plats mangés, 2 recettes différentes, 2 découvertes.')
        ->toContain('La plus cuisinée : Quiche lorraine, 3 fois.')
        ->not->toContain('Pierre');

    Livewire::test(YearInReview::class)
        ->assertSee('L\'année 2026 en cuisine', false)
        ->assertSee('Du 1er janvier au 15 octobre')
        ->assertSee('c\'est la recette de l\'année', false)
        ->assertSee('Rien n\'a été marqué « jeté »', false);

    $this->get(route('planner.year', 2025))->assertOk()->assertSee('Chili con carne');
    $this->get('/planning/annee/2031')->assertNotFound();
});

test('l\'année en cuisine : euros jetés en moins, seulement si les deux années ont des prix', function () {
    statMeal('2025-03-01', $this->dinner, $this->quiche);
    statMeal('2026-03-01', $this->dinner, $this->quiche);

    $stats = app(PlanningStats::class)->year(2026);
    expect(YearInReview::wasteDifference($stats))->toBeNull();   // rien de jeté, rien de chiffré

    $stats['waste'] = ['count' => 3, 'priced' => 2, 'cost' => 4.5];
    $stats['previous']['waste'] = ['count' => 9, 'priced' => 7, 'cost' => 27.8];
    expect(YearInReview::wasteDifference($stats))->toBe(23.3)
        ->and(YearInReview::shareText($stats, app(PriceBook::class)))->toContain("23,30\u{00A0}€ jetés de moins que l'an dernier");

    $stats['previous']['waste']['priced'] = 0;
    expect(YearInReview::wasteDifference($stats))->toBeNull();
});

test('l\'accueil propose l\'année en cuisine en décembre et jusqu\'au 15 janvier', function () {
    statMeal('2026-03-01', $this->dinner, $this->quiche);

    Livewire::test(Dashboard::class)->assertViewHas('yearReview', null);

    $this->travelTo(Carbon::parse('2026-12-02 10:00'));
    Livewire::test(Dashboard::class)->assertViewHas('yearReview', 2026)->assertSee('L\'année 2026 en cuisine', false);

    $this->travelTo(Carbon::parse('2027-01-10 10:00'));
    Livewire::test(Dashboard::class)->assertViewHas('yearReview', 2026);
    $this->get(route('planner.year'))->assertSee('L\'année 2026 en cuisine', false);

    $this->travelTo(Carbon::parse('2027-01-16 10:00'));
    Livewire::test(Dashboard::class)->assertViewHas('yearReview', null);
});

/* ================================================================ 35.1 Écran de cuisine */

test('écran de cuisine : menus du jour et du lendemain, préparations, courses, rafraîchi seul', function () {
    statMeal('2026-10-15', $this->dinner, $this->quiche, null);
    statMeal('2026-10-16', $this->lunch, $this->tian, null);
    $list = app(ShoppingListManager::class)->create(Carbon::today(), Carbon::today()->addDays(6));
    app(ShoppingListManager::class)->addManual($list, 'Lessive');
    $checked = app(ShoppingListManager::class)->addManual($list, 'Beurre');
    app(ShoppingListManager::class)->toggleCheck($checked);

    $meal = PlannedMeal::where('recipe_id', $this->quiche->id)->sole();
    $this->tian->update(['rest_minutes' => 720]);   // repos d'une nuit : à préparer aujourd'hui

    $this->get(route('kitchen'))->assertOk()
        ->assertSee('wire:poll.60s', false)
        ->assertSee('data-cook-mode', false)
        ->assertDontSee('Paramètres')                       // pas de navigation
        ->assertSeeInOrder(['Aujourd\'hui', 'Quiche lorraine', 'Demain', 'Tian de courgettes'], false)
        ->assertSee('Préparer tian de courgettes')
        ->assertSee('Lessive')
        ->assertDontSee('>Beurre<', false)
        ->assertSee(e(route('recipes.cook', ['recipe' => $this->quiche, 'portions' => 2, 'repas' => $meal->id])), false);

    $reminder = Reminder::sole();

    Livewire::test(KitchenScreen::class)
        ->set('newItem', 'Lait')
        ->call('addItem')
        ->assertHasNoErrors()
        ->assertSee('Lait')
        ->call('reminderDone', $reminder->id);

    expect($list->items()->where('label', 'Lait')->exists())->toBeTrue()
        ->and($reminder->fresh()->status)->toBe(Reminder::DONE);
});

test('écran de cuisine : sans liste, l\'ajout en crée une ; un compte en consultation ne modifie rien', function () {
    Livewire::test(KitchenScreen::class)->assertSee('Pas de liste en cours')->set('newItem', 'Café')->call('addItem');
    expect(ShoppingList::sole()->items()->pluck('label')->all())->toBe(['Café']);

    $viewer = User::factory()->create(['role' => UserRole::Viewer]);
    $this->actingAs($viewer);
    $this->get(route('kitchen'))->assertOk()->assertDontSee('Il manque…');
    Livewire::test(KitchenScreen::class)->set('newItem', 'Thé')->call('addItem');
    expect(ShoppingList::sole()->items()->count())->toBe(1);
});
