<?php

use App\Enums\MovementType;
use App\Enums\UserRole;
use App\Livewire\Budget\Analyses;
use App\Livewire\Budget\ExpenseForm;
use App\Livewire\Budget\Index as BudgetIndex;
use App\Livewire\Layout\QuickAdd;
use App\Livewire\Planner\Week;
use App\Livewire\Settings\BudgetSettings;
use App\Models\BudgetAmount;
use App\Models\BudgetCategory;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\RecurringExpense;
use App\Models\ShoppingList;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\Budget\BudgetAnalysis;
use App\Services\Budget\BudgetTracker;
use App\Services\Budget\RecurringExpenses;
use App\Services\Planning\WeekPlanner;
use App\Services\Pricing\Budget;
use App\Services\Shopping\ShoppingListManager;
use App\Support\Settings;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Dépenses et budget réel (lot 22 — module 23 du document 07, règles R25 et R26).
 */

require_once __DIR__.'/../Shopping/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00'));      // mercredi, 14e jour d'un mois de 31
    $this->actingAs($this->user = User::factory()->create(['name' => 'Pierre']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, IngredientSeeder::class]);

    $this->tracker = app(BudgetTracker::class);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner']);

    $this->cat = fn (string $kind) => BudgetCategory::firstWhere('kind', $kind);
    $this->spend = fn (float $amount, string $kind = 'groceries', string $date = '2026-10-10', array $extra = []) => $this->tracker->record(
        ['amount' => $amount, 'spent_on' => $date, 'budget_category_id' => ($this->cat)($kind)->id] + $extra,
    );
});

/* ================================================================ Postes et périodes (23.2, 23.7) */

test('six postes sont proposés au départ, sans montant', function () {
    expect(BudgetCategory::query()->ordered()->pluck('name')->all())->toBe([
        'Courses alimentaires', 'Droguerie et maison', 'Restaurant', 'À emporter / livraison', 'Midi au travail', 'Boissons',
    ])->and(BudgetAmount::count())->toBe(0)
        ->and(BudgetCategory::groceries()->kind)->toBe('groceries');
});

test('la période peut commencer le 25 du mois', function () {
    [$start, $end] = $this->tracker->period();
    expect($start->toDateString())->toBe('2026-10-01')->and($end->toDateString())->toBe('2026-10-31');

    Settings::set('budget.period_start_day', 25);

    [$start, $end] = $this->tracker->period();
    expect($start->toDateString())->toBe('2026-09-25')
        ->and($end->toDateString())->toBe('2026-10-24')
        ->and($this->tracker->periodLabel($start, $end))->toBe('Du 25 sept. au 24 oct. 2026');

    [$next] = $this->tracker->shift($start, 1);
    expect($next->toDateString())->toBe('2026-10-25');
});

test('changer un budget ne réécrit pas les périodes passées', function () {
    $groceries = ($this->cat)('groceries');

    $this->tracker->setBudget($groceries, 400, Carbon::parse('2026-08-12'));
    $this->tracker->setBudget($groceries, 500);

    expect($this->tracker->budgetFor($groceries, Carbon::parse('2026-07-01')))->toBeNull()
        ->and($this->tracker->budgetFor($groceries, Carbon::parse('2026-08-01')))->toBe(400.0)
        ->and($this->tracker->budgetFor($groceries, Carbon::parse('2026-09-01')))->toBe(400.0)
        ->and($this->tracker->budgetFor($groceries, Carbon::parse('2026-10-01')))->toBe(500.0);

    $this->tracker->setBudget($groceries, 0);
    expect($this->tracker->budgetFor($groceries, Carbon::parse('2026-10-01')))->toBeNull();
});

/* ================================================================ Saisie (23.1, 23.3) */

test('une dépense se répartit entre postes, et la répartition doit tomber juste', function () {
    $expense = $this->tracker->record(
        ['amount' => '87,40', 'spent_on' => '2026-10-12', 'budget_category_id' => ($this->cat)('groceries')->id],
        [($this->cat)('groceries')->id => 72.10, ($this->cat)('household')->id => 15.30],
    );

    expect($expense->splits)->toHaveCount(2)
        ->and($expense->isSplit())->toBeTrue();

    $spent = $this->tracker->spentByCategory(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
    expect($spent[($this->cat)('groceries')->id])->toBe(72.1)
        ->and($spent[($this->cat)('household')->id])->toBe(15.3);

    expect(fn () => $this->tracker->record(
        ['amount' => 50, 'spent_on' => '2026-10-12', 'budget_category_id' => ($this->cat)('groceries')->id],
        [($this->cat)('groceries')->id => 30, ($this->cat)('household')->id => 10],
    ))->toThrow(InvalidArgumentException::class, 'ne correspond pas');
});

test('une dépense ne peut être ni négative ni datée dans le futur', function () {
    expect(fn () => ($this->spend)(-5))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ($this->spend)(20, 'groceries', '2026-10-15'))->toThrow(InvalidArgumentException::class, 'futur');

    expect(Expense::count())->toBe(0);
});

test('un ticket relié à une liste remplace ses prix cochés (R26)', function () {
    $list = ShoppingList::create(['name' => 'Semaine', 'period_start' => '2026-10-12', 'period_end' => '2026-10-18']);
    $manager = app(ShoppingListManager::class);
    $manager->addManual($list, 'Beurre')->update(['is_checked' => true, 'checked_at' => now(), 'paid_price' => 3.20]);
    $manager->addManual($list, 'Lait demi-écrémé')->update(['is_checked' => true, 'checked_at' => now(), 'paid_price' => 26.80]);

    $groceries = ($this->cat)('groceries')->id;
    $month = [Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')];

    expect($this->tracker->spentByCategory(...$month)[$groceries])->toBe(30.0)
        ->and($this->tracker->suggestList(null, '2026-10-14')?->id)->toBe($list->id);

    ($this->spend)(42.15, 'groceries', '2026-10-14', ['shopping_list_id' => $list->id]);

    expect($this->tracker->spentByCategory(...$month)[$groceries])->toBe(42.15)
        ->and($this->tracker->listPrices(...$month)['entries'])->toBe(0)
        ->and($this->tracker->suggestList(null, '2026-10-14'))->toBeNull();
});

test('une dépense identique est repérée', function () {
    ($this->spend)(18.50, 'restaurant', '2026-10-12', ['place' => 'Chez Mario']);

    expect($this->tracker->duplicateOf(['amount' => '18,50', 'spent_on' => '2026-10-12', 'place' => 'Chez Mario']))->not->toBeNull()
        ->and($this->tracker->duplicateOf(['amount' => '18,60', 'spent_on' => '2026-10-12', 'place' => 'Chez Mario']))->toBeNull()
        ->and($this->tracker->duplicateOf(['amount' => '18,50', 'spent_on' => '2026-10-13', 'place' => 'Chez Mario']))->toBeNull();
});

/* ================================================================ Tableau de bord (23.5, R25) */

test('chaque poste dit où il en est : rythme, projection et alertes', function () {
    $this->tracker->setBudget(($this->cat)('groceries'), 310);
    $this->tracker->setBudget(($this->cat)('restaurant'), 100);
    $this->tracker->setBudget(($this->cat)('drinks'), 10);

    ($this->spend)(250);                 // 81 %
    ($this->spend)(60, 'restaurant');    // 60 %, mais 60 € en 14 jours → 133 € en fin de mois
    ($this->spend)(12, 'drinks');        // dépassé

    $rows = $this->tracker->dashboard()->keyBy(fn ($r) => $r['category']->kind);

    expect($rows['groceries']['pace'])->toBe(140.0)
        ->and($rows['groceries']['share'])->toBe(81)
        ->and($rows['groceries']['alert'])->toBe('soon')
        ->and($rows['groceries']['fragile'])->toBeTrue()
        ->and($rows['groceries']['projection'])->toBe(553.57)
        ->and($rows['groceries']['per_week'])->toBe(24.71)
        ->and($rows['restaurant']['alert'])->toBe('projected')
        ->and($rows['drinks']['alert'])->toBe('over')
        ->and($rows['household']['budget'])->toBeNull()
        ->and($rows['household']['alert'])->toBeNull();
});

test('avec trois périodes d\'historique, la projection s\'appuie sur leur moyenne (R25)', function () {
    foreach (['2026-07-10', '2026-08-10', '2026-09-10'] as $date) {
        ($this->spend)(310, 'groceries', $date);
    }
    ($this->spend)(100);

    $row = $this->tracker->dashboard()->firstWhere('category.kind', 'groceries');

    // 100 € dépensés + 310 € × 17/31 jours restants
    expect($row['fragile'])->toBeFalse()
        ->and($row['projection'])->toBe(270.0);
});

/* ================================================================ Récurrentes (C7) et qui a payé (23.8) */

test('une dépense récurrente est comptée à chaque échéance passée, jamais d\'avance ni deux fois', function () {
    $service = app(RecurringExpenses::class);
    $rule = $service->create('Cantine', 45, ($this->cat)('work')->id, 'monthly', 5, Carbon::parse('2026-08-01'));

    expect($rule->next_on->toDateString())->toBe('2026-08-05')
        ->and($service->apply())->toBe(3)
        ->and($service->apply())->toBe(0)
        ->and($rule->fresh()->next_on->toDateString())->toBe('2026-11-05')
        ->and(Expense::where('source', 'recurring')->orderBy('spent_on')->pluck('spent_on')->map->toDateString()->all())
        ->toBe(['2026-08-05', '2026-09-05', '2026-10-05']);

    expect(RecurringExpense::nextOccurrence('weekly', 1, Carbon::today())->toDateString())->toBe('2026-10-19');
});

test('le tableau de bord rattrape les échéances même sans tâche planifiée', function () {
    app(RecurringExpenses::class)->create('Panier bio', 22, ($this->cat)('groceries')->id, 'weekly', 1, Carbon::parse('2026-10-01'));

    Livewire::test(BudgetIndex::class)->assertSee('Panier bio');

    expect(Expense::count())->toBe(2);    // lundis 5 et 12 octobre
});

test('« qui a payé » fait le compte par personne', function () {
    expect($this->tracker->tracksPayer())->toBeFalse();
    Settings::set('budget.track_payer', true);

    $julie = User::factory()->create(['name' => 'Julie']);
    ($this->spend)(80, 'groceries', '2026-10-10', ['paid_by' => $this->user->id]);
    ($this->spend)(30, 'restaurant', '2026-10-11', ['paid_by' => $julie->id]);

    $payers = $this->tracker->payers(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))->mapWithKeys(fn ($r) => [$r['user']->name => $r['total']]);

    expect($payers->all())->toBe(['Julie' => 30.0, 'Pierre' => 80.0]);

    Livewire::test(BudgetIndex::class)->assertSee('Qui a payé')->assertSee('Pierre a avancé');
});

/* ================================================================ Analyses (23.6, C5) */

test('un repas coûte tant par personne, à la maison et dehors', function () {
    config(['bouffe.household_size' => 2]);
    $recipe = recipeWith('Hachis', 2, [[400, 'g', 'Bœuf haché', false]]);
    $planner = app(WeekPlanner::class);

    foreach (['2026-10-05', '2026-10-06', '2026-10-07'] as $date) {
        $planner->addRecipe($date, $this->dinner, $recipe)->update(['cooked_at' => now()]);
    }
    $planner->addRecipe('2026-10-08', $this->dinner, $recipe);   // pas mangé : ne compte pas

    ($this->spend)(60, 'groceries', '2026-10-05');
    ($this->spend)(80, 'restaurant', '2026-10-06', ['persons' => 4]);

    $analysis = app(BudgetAnalysis::class);

    expect($analysis->mealCosts(Carbon::parse('2026-10-05'), Carbon::parse('2026-10-08')))
        ->toBe(['home' => 10.0, 'home_covers' => 6, 'home_reliable' => true, 'out' => 20.0, 'out_covers' => 4]);

    // Sur tout le mois, 6 couverts ne suffisent pas : pas de coût à la maison plutôt qu'un chiffre faux.
    expect($analysis->mealCosts(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')))
        ->toMatchArray(['home' => null, 'home_covers' => 6, 'home_reliable' => false, 'out' => 20.0]);
});

test('le gaspillage est chiffré avec les prix de référence, sans inventer', function () {
    $g = Unit::firstWhere('code', 'g');
    $beurre = Ingredient::firstWhere('name', 'Beurre');
    $beurre->forceFill(['reference_price' => 0.01, 'reference_price_unit_id' => $g->id])->save();

    StockMovement::create(['ingredient_id' => $beurre->id, 'label' => 'Beurre', 'type' => MovementType::Waste->value, 'quantity' => 200, 'unit_id' => $g->id, 'reason' => 'périmé']);
    StockMovement::create(['ingredient_id' => Ingredient::firstWhere('name', 'Potiron')->id, 'label' => 'Potiron', 'type' => MovementType::Waste->value, 'quantity' => 1, 'unit_id' => Unit::firstWhere('code', 'piece')?->id, 'reason' => 'périmé']);

    expect($this->tracker->wasteValue(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')))
        ->toBe(['cost' => 2.0, 'count' => 2, 'priced' => 1]);
});

test('les analyses comparent les périodes, les lieux et le planning', function () {
    ($this->spend)(120, 'groceries', '2026-09-10', ['place' => 'Cactus']);
    ($this->spend)(90, 'groceries', '2026-10-10', ['place' => 'Cactus']);
    ($this->spend)(40, 'restaurant', '2026-10-11', ['place' => 'Chez Mario']);

    $analysis = app(BudgetAnalysis::class);
    $periods = $analysis->periods(3);

    expect(array_column($periods, 'total'))->toBe([0.0, 120.0, 130.0])
        ->and($analysis->places(Carbon::parse('2026-09-01'), Carbon::parse('2026-10-31'))->first())
        ->toBe(['label' => 'Cactus', 'visits' => 2, 'total' => 210.0, 'average' => 105.0])
        ->and($analysis->plannedVsActual(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))['actual'])->toBe(90.0);

    Livewire::test(Analyses::class)
        ->assertSee('Dépenses par période')
        ->assertSee('Chez Mario')
        ->set('range', 12)
        ->assertSee('Nov.');
});

/* ================================================================ Fenêtre de saisie */

test('la saisie rapide enregistre une dépense de courses par défaut', function () {
    Livewire::test(ExpenseForm::class)
        ->call('open')
        ->assertSet('categoryId', ($this->cat)('groceries')->id)
        ->assertSet('spentOn', '2026-10-14')
        ->set('amount', '42,50')
        ->set('place', 'Cactus Bereldange')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('show', false)
        ->assertDispatched('expenses-changed');

    $expense = Expense::sole();
    expect((float) $expense->amount)->toBe(42.5)
        ->and($expense->source)->toBe('manual')
        ->and($expense->created_by)->toBe($this->user->id)
        ->and($expense->placeLabel())->toBe('Cactus Bereldange');
});

test('le poste est proposé d\'après la dernière dépense au même endroit', function () {
    ($this->spend)(48, 'restaurant', '2026-10-02', ['place' => 'Chez Mario']);

    Livewire::test(ExpenseForm::class)
        ->call('open')
        ->assertSet('categoryId', ($this->cat)('groceries')->id)
        ->set('place', 'Chez Mario')
        ->assertSet('categoryId', ($this->cat)('restaurant')->id)
        ->set('place', 'Inconnu')
        ->assertSet('categoryId', ($this->cat)('restaurant')->id);   // rien de connu : on ne change pas
});

test('un ticket mixte se répartit dans la fenêtre de saisie', function () {
    $groceries = ($this->cat)('groceries')->id;
    $household = ($this->cat)('household')->id;

    $form = Livewire::test(ExpenseForm::class)
        ->call('open')
        ->set('amount', '87,40')
        ->call('toggleSplit')
        ->set('splits', [['category_id' => $groceries, 'amount' => '70'], ['category_id' => $household, 'amount' => '15']])
        ->call('save')
        ->assertHasErrors('splits');

    expect(Expense::count())->toBe(0);

    $form->set('splits', [['category_id' => $groceries, 'amount' => '72,10'], ['category_id' => $household, 'amount' => '15,30']])
        ->call('save')
        ->assertHasNoErrors();

    expect(Expense::sole()->splits()->pluck('amount', 'budget_category_id')->map(fn ($v) => (float) $v)->all())
        ->toBe([$groceries => 72.1, $household => 15.3]);
});

test('un repas « restaurant » du planning pré-remplit sa dépense (23.4)', function () {
    config(['bouffe.household_size' => 2]);
    $meal = app(WeekPlanner::class)->addFree('2026-10-13', $this->dinner, 'Chez Mario');

    Livewire::test(Week::class)
        ->call('mealExpense', $meal->id)
        ->assertDispatched('open-expense', mealId: $meal->id);

    Livewire::test(ExpenseForm::class)
        ->call('open', null, $meal->id)
        ->assertSet('categoryId', ($this->cat)('restaurant')->id)
        ->assertSet('place', 'Chez Mario')
        ->assertSet('persons', 2)
        ->assertSet('spentOn', '2026-10-13')
        ->set('amount', '64')
        ->call('save');

    expect(Expense::sole()->planned_meal_id)->toBe($meal->id);

    // Une seconde ouverture depuis le repas modifie la même dépense.
    Livewire::test(Week::class)
        ->call('mealExpense', $meal->id)
        ->assertDispatched('open-expense', expenseId: Expense::sole()->id);
});

test('un repas pris dehors peut être ajouté au planning depuis sa dépense', function () {
    Livewire::test(ExpenseForm::class)
        ->call('open', null, null, 'takeaway')
        ->set('amount', '31')
        ->set('place', 'Pizzeria Roma')
        ->set('addToPlanning', true)
        ->set('slotId', (string) $this->dinner->id)
        ->call('save');

    $meal = PlannedMeal::sole();
    expect($meal->free_text)->toBe('Pizzeria Roma')
        ->and($meal->cooked_at)->not->toBeNull()
        ->and(Expense::sole()->planned_meal_id)->toBe($meal->id);
});

test('une dépense en double demande confirmation', function () {
    ($this->spend)(18.5, 'groceries', '2026-10-14', ['place' => 'Boulangerie']);

    $form = Livewire::test(ExpenseForm::class)
        ->call('open')
        ->set('amount', '18,50')
        ->set('place', 'Boulangerie')
        ->call('save')
        ->assertSee('Une dépense identique existe déjà');

    expect(Expense::count())->toBe(1);

    $form->call('save');
    expect(Expense::count())->toBe(2);
});

test('en consultation seule, on voit le budget sans pouvoir saisir', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer]));

    $this->get(route('budget.index'))->assertOk()->assertDontSee('wire:click="add"', false);

    Livewire::test(ExpenseForm::class)->call('open')->assertSet('show', false);
    Livewire::test(ExpenseForm::class)->set('amount', '10')->call('save')->assertForbidden();
});

test('le bouton + propose « Une dépense »', function () {
    Livewire::test(QuickAdd::class)
        ->call('open')
        ->assertSee('Une dépense')
        ->call('expense')
        ->assertSet('show', false)
        ->assertDispatched('open-expense');
});

/* ================================================================ Pages */

test('le tableau de bord change de période et filtre par poste', function () {
    ($this->spend)(25, 'groceries', '2026-10-03', ['place' => 'Marché']);
    ($this->spend)(33, 'drinks', '2026-09-20', ['place' => 'Cave']);

    $this->get(route('budget.index'))->assertOk()->assertSee('Octobre 2026')->assertSee('Budget');

    Livewire::test(BudgetIndex::class)
        ->assertSee('Marché')->assertDontSee('Cave')
        ->call('previous')
        ->assertSet('at', '2026-09-01')
        ->assertSee('Septembre 2026')->assertSee('Cave')
        ->set('categoryFilter', (string) ($this->cat)('groceries')->id)
        ->assertDontSee('Cave')
        ->call('next')
        ->assertSet('at', '');
});

test('l\'export CSV donne une ligne par poste', function () {
    $this->tracker->record(
        ['amount' => 87.4, 'spent_on' => '2026-10-12', 'budget_category_id' => ($this->cat)('groceries')->id, 'place' => 'Cactus'],
        [($this->cat)('groceries')->id => 72.1, ($this->cat)('household')->id => 15.3],
    );

    $response = $this->get(route('budget.export', ['du' => '2026-10-01', 'au' => '2026-10-31']))->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBFDate;Lieu;Poste;Montant")
        ->toContain('12/10/2026;Cactus;"Courses alimentaires";72,10')
        ->toContain('12/10/2026;Cactus;"Droguerie et maison";15,30');

    $this->get(route('budget.export', ['du' => 'nimporte']))->assertRedirect();
});

/* ================================================================ Paramètres */

test('les paramètres fixent le budget de chaque poste', function () {
    $groceries = ($this->cat)('groceries');
    $restaurant = ($this->cat)('restaurant');

    Livewire::test(BudgetSettings::class)
        ->assertSee('Postes et budget mensuel')
        ->set("rows.{$groceries->id}.budget", '450')
        ->set("rows.{$restaurant->id}.budget", '120,50')
        ->set("rows.{$restaurant->id}.name", 'Resto')
        ->call('saveCategories')
        ->assertHasNoErrors();

    expect(app(Budget::class)->monthly())->toBe(450.0)      // interface du lot 17 toujours juste
        ->and($this->tracker->budgetFor($restaurant->fresh(), Carbon::parse('2026-10-01')))->toBe(120.5)
        ->and($restaurant->fresh()->name)->toBe('Resto')
        ->and(BudgetAmount::count())->toBe(2);

    // Enregistrer sans rien changer ne crée pas de nouvelle ligne datée.
    Livewire::test(BudgetSettings::class)->call('saveCategories');
    expect(BudgetAmount::count())->toBe(2);

    Livewire::test(BudgetSettings::class)
        ->set("rows.{$groceries->id}.budget", 'beaucoup')
        ->call('saveCategories')
        ->assertHasErrors("rows.{$groceries->id}.budget");
});

test('les postes s\'ajoutent, se classent et s\'archivent', function () {
    $drinks = ($this->cat)('drinks');

    Livewire::test(BudgetSettings::class)
        ->set('newName', 'Animaux')
        ->set('newColor', 'teal')
        ->call('addCategory')
        ->call('move', $drinks->id, -1)
        ->call('archive', $drinks->id)
        ->call('archive', ($this->cat)('groceries')->id)
        ->assertSee('Postes archivés');

    expect(BudgetCategory::firstWhere('name', 'Animaux')->kind)->toBe('other')
        ->and($drinks->fresh()->archived_at)->not->toBeNull()
        ->and(($this->cat)('groceries')->archived_at)->toBeNull()
        ->and(BudgetCategory::query()->active()->ordered()->pluck('name')->last())->toBe('Animaux');

    Livewire::test(BudgetSettings::class)->call('restore', $drinks->id);
    expect($drinks->fresh()->archived_at)->toBeNull();
});

test('les paramètres règlent la période, « qui a payé » et les dépenses récurrentes', function () {
    Livewire::test(BudgetSettings::class)
        ->set('startDay', 25)
        ->set('trackPayer', true)
        ->call('saveOptions')
        ->set('recLabel', 'Cantine de Léa')
        ->set('recAmount', '45,60')
        ->set('recCategory', ($this->cat)('work')->id)
        ->set('recFrequency', 'weekly')
        ->set('recDay', 2)
        ->call('addRecurring')
        ->assertHasNoErrors()
        ->assertSee('Cantine de Léa')
        ->assertSee('chaque mardi');

    expect($this->tracker->startDay())->toBe(25)
        ->and($this->tracker->tracksPayer())->toBeTrue();

    $rule = RecurringExpense::sole();
    expect((float) $rule->amount)->toBe(45.6)
        ->and($rule->next_on->toDateString())->toBe('2026-10-20');

    Livewire::test(BudgetSettings::class)->call('toggleRecurring', $rule->id);
    expect($rule->fresh()->is_active)->toBeFalse();

    Livewire::test(BudgetSettings::class)->call('deleteRecurring', $rule->id);
    expect(RecurringExpense::count())->toBe(0);
});
