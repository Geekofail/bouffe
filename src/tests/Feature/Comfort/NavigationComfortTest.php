<?php

use App\Livewire\Dashboard;
use App\Livewire\Layout\GlobalSearch;
use App\Livewire\Layout\QuickAdd;
use App\Livewire\Planner\Week;
use App\Livewire\Settings\Display;
use App\Models\Ingredient;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\ShoppingList;
use App\Models\StockItem;
use App\Models\User;
use App\Services\Planning\GuestManager;
use App\Services\Planning\WeekPlanner;
use App\Services\Search\GlobalSearch as SearchService;
use App\Services\Stock\StockManager;
use App\Support\Navigation;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

require_once __DIR__.'/../Shopping/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00'));
    $this->actingAs($this->user = User::factory()->create(['name' => 'Pierre']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);
    $this->planner = app(WeekPlanner::class);
    $this->lunch = MealSlot::factory()->create(['name' => 'Déjeuner', 'sort_order' => 1]);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 2]);
    $this->recipe = recipeWith('Poulet à la crème', 4, [[2, 'piece', 'Blanc de poulet', false], [20, 'cl', 'Crème liquide', false]]);
});

/* ================================================================ 12.1 Recherche globale */

test('recherche globale : recettes, stock, courses, ingrédients (avec alias), invités et pages', function () {
    app(StockManager::class)->add(['ingredient_id' => Ingredient::firstWhere('name', 'Crème liquide')->id, 'quantity' => 20, 'expires_on' => '2026-09-17', 'expiry_type' => 'dlc']);
    Ingredient::firstWhere('name', 'Crème liquide')->aliases()->create(['name' => 'Crème fleurette']);
    $list = ShoppingList::create(['name' => 'Courses de la semaine', 'period_start' => '2026-09-14', 'period_end' => '2026-09-20']);
    $list->items()->create(['label' => 'Crème liquide', 'origin' => 'manual']);
    app(GuestManager::class)->save(null, ['name' => 'Julie Crèmerie']);

    $results = app(SearchService::class)->search('crème');

    expect(array_keys($results))->toBe(['Recettes', 'Stock', 'Liste de courses', 'Ingrédients', 'Invités'])
        ->and($results['Recettes'][0]['title'])->toBe('Poulet à la crème')
        ->and($results['Stock'][0]['badge']['text'])->toBe('Demain')
        ->and($results['Stock'][0]['actions'][0]['label'])->toBe('Que cuisiner ?')
        ->and($results['Liste de courses'][0]['subtitle'])->toBe('Courses de la semaine')
        ->and(collect($results['Ingrédients'])->pluck('title'))->toContain('Crème liquide')
        ->and($results['Invités'][0]['title'])->toBe('Julie Crèmerie');

    $alias = app(SearchService::class)->search('fleurette');
    expect($alias['Ingrédients'][0]['subtitle'])->toContain('autre nom : Crème fleurette')
        ->and($alias['Stock'][0]['title'])->toBe('Crème liquide');

    expect(collect(app(SearchService::class)->search('sauvegarde')['Aller à'])->pluck('title')->all())->toBe(['Sauvegardes'])
        ->and(app(SearchService::class)->search(''))->toHaveKey('Aller à');
});

test('fenêtre de recherche : ouverture, résultats, fermeture', function () {
    Livewire::test(GlobalSearch::class)
        ->assertDontSee('Rechercher')
        ->dispatch('open-search')
        ->assertSet('show', true)
        ->assertSee('Aller à')
        ->set('query', 'poulet')
        ->assertSee('Poulet à la crème')
        ->set('query', 'zzzz')
        ->assertSee('Rien trouvé pour « zzzz »', false)
        ->call('close')
        ->assertSet('show', false);

    $this->get(route('dashboard'))->assertOk()->assertSee('open-search', false)->assertSee('bouffe-theme', false);
});

/* ================================================================ 12.2 Bouton + */

test('ajout rapide : au stock (ingrédient connu, inconnu, plat préparé) et aux courses', function () {
    Livewire::test(QuickAdd::class)
        ->dispatch('open-quick-add')
        ->assertSee('Au stock')
        ->call('choose', 'stock')
        ->set('text', '6 œufs')->call('addToStock')
        ->assertDispatched('notify', message: '« Œuf » ajouté au stock (6 pièces · Réfrigérateur).')
        ->set('text', 'Kombucha maison')->call('addToStock')
        ->assertSet('unknownName', 'Kombucha maison')
        ->call('addToStock', 'prepared')
        ->assertDispatched('stock-changed')
        ->set('text', '2 bocaux de kimchi')->call('addToStock')
        ->call('addToStock', 'ingredient');

    expect(StockItem::active()->count())->toBe(3)
        ->and(StockItem::whereNull('ingredient_id')->value('label'))->toBe('Kombucha maison')
        ->and(Ingredient::where('name', 'like', '%imchi%')->exists())->toBeTrue();

    Livewire::test(QuickAdd::class)
        ->call('open', 'shopping')
        ->set('text', 'Lessive')->call('addToShopping')
        ->assertDispatched('notify');

    expect(ShoppingList::sole()->items()->pluck('label')->all())->toBe(['Lessive']);

    Livewire::test(QuickAdd::class)->call('open', 'meal')
        ->assertSee(e(route('planner.week', ['ajouter' => '2026-09-16', 'creneau' => $this->dinner->id])), false)
        ->assertSee('Demain');
});

test('le planning ouvre directement la case demandée par l\'ajout rapide', function () {
    Livewire::withQueryParams(['ajouter' => '2026-09-22', 'creneau' => $this->dinner->id])
        ->test(Week::class)
        ->assertSet('week', '2026-09-21')
        ->assertDispatched('open-meal-picker', date: '2026-09-22', slotId: $this->dinner->id);
});

/* ================================================================ 12.7 Barre du bas, 12.3 accueil */

test('barre du bas personnalisée et menu « Plus »', function () {
    $this->get(route('dashboard'))->assertOk()->assertSee('Plus');

    Livewire::test(Display::class)
        ->assertSet('bottom', Navigation::DEFAULT_BOTTOM)
        ->set('bottom', ['recipes', 'planner', 'planner', 'stock'])
        ->call('saveBottom')->assertHasErrors('bottom.2')
        ->set('bottom', ['recipes', 'planner', 'suggestions', 'stock'])
        ->call('saveBottom')->assertHasNoErrors();

    expect(Navigation::bottom($this->user->fresh()))->toBe(['recipes', 'planner', 'suggestions', 'stock']);

    // Préférence invalide enregistrée à la main : valeurs par défaut
    $this->user->setPreference('bottom_nav', ['recipes', 'nimporte']);
    expect(Navigation::bottom($this->user->fresh()))->toBe(Navigation::DEFAULT_BOTTOM);
});

test('accueil : ordre et blocs masqués selon les préférences', function () {
    $this->get(route('dashboard'))->assertSeeInOrder(['Au menu aujourd\'hui', 'Courses', 'Cette semaine']);

    Livewire::test(Display::class)
        ->call('moveHome', 5, -1)      // « Cette semaine » avant « Courses »
        ->call('toggleHome', 0);       // « Au menu » masqué

    $home = Navigation::home($this->user->fresh());
    expect(array_column($home, 'key'))->toBe(['menu', 'leftovers', 'expiring', 'reminders', 'week', 'shopping', 'wishes', 'ideas', 'linked', 'prices'])
        ->and($home[0]['visible'])->toBeFalse();

    $this->get(route('dashboard'))
        ->assertDontSee('Au menu aujourd\'hui')
        ->assertSeeInOrder(['Cette semaine', 'Courses']);

    Livewire::test(Display::class)->call('resetHome');
    $this->get(route('dashboard'))->assertSee('Au menu aujourd\'hui');
});

test('accueil : restes à finir et « Placer demain »', function () {
    $meal = $this->planner->addRecipe('2026-09-16', $this->dinner, $this->recipe, 4);   // 4 portions, 2 convives → 2 restes
    $this->planner->addRecipe('2026-09-17', $this->dinner, $this->recipe, 2);           // demain soir déjà pris

    Livewire::test(Dashboard::class)
        ->assertSee('Restes à finir')
        ->assertSee('2 portions à placer')
        ->call('placeLeftovers', $meal->id)
        ->assertDispatched('notify', message: 'Restes de « Poulet à la crème » placés jeudi · déjeuner.');

    $leftover = PlannedMeal::where('leftover_of_id', $meal->id)->sole();
    expect($leftover->date->toDateString())->toBe('2026-09-17')
        ->and($leftover->meal_slot_id)->toBe($this->lunch->id);

    Livewire::test(Dashboard::class)->assertDontSee('portions à placer');
});
