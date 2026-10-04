<?php

use App\Enums\ExpiryLevel;
use App\Enums\MovementType;
use App\Livewire\Planner\Week;
use App\Livewire\Settings\StockSettings;
use App\Livewire\Stock\ExpiryAlertsCard;
use App\Livewire\Stock\Index;
use App\Models\Ingredient;
use App\Models\MealSlot;
use App\Models\StockItem;
use App\Models\User;
use App\Services\Stock\ExpiryAlerts;
use App\Services\Stock\ExpiryCalculator;
use App\Services\Stock\StockManager;
use App\Support\Settings;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00'));   // mercredi
    $this->actingAs(User::factory()->create(['name' => 'Pierre']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);
    MealSlot::factory()->create(['name' => 'Dîner']);
    $this->stock = app(StockManager::class);

    $add = fn (string $name, ?string $date, string $type = 'dlc', array $extra = []) => $this->stock->add(array_merge([
        'ingredient_id' => Ingredient::firstWhere('name', $name)->id, 'quantity' => 1, 'expires_on' => $date, 'expiry_type' => $type,
    ], $extra));

    $this->expired = $add('Crème liquide', '2026-09-15');
    $this->tomorrow = $add('Blanc de poulet', '2026-09-17');
    $this->soon = $add('Yaourt nature', '2026-09-19');
    $this->ddmPassed = $add('Chocolat noir', '2026-09-01', 'ddm', ['quantity' => 200]);
    $this->ok = $add('Beurre', '2026-11-01');
    $this->nextWeek = $add('Feta', '2026-09-22');
});

test('les produits à surveiller sont triés du plus urgent au moins urgent', function () {
    $alerts = app(ExpiryAlerts::class);

    expect($alerts->items()->map(fn ($r) => $r['item']->name())->all())
        ->toBe(['Crème liquide', 'Blanc de poulet', 'Chocolat noir', 'Yaourt nature'])
        ->and($alerts->counts())->toBe(['total' => 4, 'urgent' => 2])
        ->and($alerts->expiringBy(Carbon::parse('2026-09-20'))->map(fn ($r) => $r['item']->name())->all())
        ->toBe(['Chocolat noir', 'Crème liquide', 'Blanc de poulet', 'Yaourt nature']);
});

test('les délais réglables changent les niveaux', function () {
    $calculator = app(ExpiryCalculator::class);
    expect($calculator->level($this->nextWeek))->toBe(ExpiryLevel::Ok);

    Settings::set('stock.dlc_soon_days', 7);
    expect($calculator->level($this->nextWeek->fresh()))->toBe(ExpiryLevel::Soon)
        ->and(Settings::int('stock.dlc_soon_days'))->toBe(7);

    Settings::set('stock.dlc_soon_days', 99);   // borné
    expect(Settings::int('stock.dlc_soon_days'))->toBe(14);
});

test('l\'écran de réglages enregistre les délais et les options', function () {
    Livewire::test(StockSettings::class)
        ->assertSet('dlcSoonDays', 3)
        ->set('dlcSoonDays', 0)->call('save')->assertHasErrors('dlcSoonDays')
        ->set('dlcSoonDays', 5)->set('ddmSoonDays', 14)->set('navBadge', false)->call('save')
        ->assertHasNoErrors()->assertDispatched('notify');

    Settings::flush();
    expect(Settings::int('stock.dlc_soon_days'))->toBe(5)
        ->and(Settings::int('stock.ddm_soon_days'))->toBe(14)
        ->and(Settings::bool('stock.nav_badge'))->toBeFalse();
});

test('le menu affiche le nombre de produits à surveiller, sauf si désactivé', function () {
    $this->get(route('dashboard'))->assertSee('4 produit(s) à surveiller', false)->assertSee('bg-red-600', false);

    Settings::set('stock.nav_badge', false);
    $this->get(route('recipes.index'))->assertDontSee('produit(s) à surveiller', false);
});

test('la carte de l\'accueil liste les produits et permet d\'agir directement', function () {
    Livewire::test(ExpiryAlertsCard::class)
        ->assertSeeInOrder(['Crème liquide', 'Dépassée', 'Blanc de poulet', 'Demain'])
        ->assertSee('à jeter')
        ->assertDontSee('Beurre')
        ->call('consume', $this->soon->id)
        ->assertSee('« Yaourt nature » consommé.')
        ->call('undo')
        ->call('askWaste', $this->expired->id)
        ->call('waste', $this->expired->id, 'périmé')
        ->call('freeze', $this->tomorrow->id)
        ->call('askDate', $this->ddmPassed->id)
        ->assertSet('newDate', '2026-09-01')
        ->set('newDate', '2026-12-31')
        ->call('saveDate')
        ->assertSee('« Chocolat noir » : nouvelle date le 31 décembre.')
        ->tap(fn ($component) => expect($component->instance()->alerts->map(fn ($r) => $r['item']->name())->all())->toBe(['Yaourt nature']));

    expect($this->soon->fresh()->finished_at)->toBeNull()
        ->and($this->expired->fresh()->movements()->latest('id')->first()->type)->toBe(MovementType::Waste)
        ->and($this->tomorrow->fresh()->isFrozen())->toBeTrue()
        ->and($this->ddmPassed->fresh()->expires_on->toDateString())->toBe('2026-12-31');

    $this->get(route('dashboard'))->assertSee('À consommer rapidement');
});

test('sans produit à surveiller, la carte le dit ; sans stock, elle disparaît', function () {
    StockItem::query()->update(['finished_at' => now()]);
    Livewire::test(ExpiryAlertsCard::class)->assertDontSee('À consommer rapidement');

    $this->stock->add(['ingredient_id' => Ingredient::firstWhere('name', 'Beurre')->id, 'quantity' => 250]);
    Livewire::test(ExpiryAlertsCard::class)->assertSee('Rien ne presse');
});

test('le planning de la semaine en cours signale ce qui périme d\'ici dimanche', function () {
    Livewire::test(Week::class)
        ->assertSee('4 produits')
        ->assertSee('Crème liquide (dépassée)')
        ->assertDontSee('Feta (')
        ->call('nextWeek')
        ->assertDontSee('à consommer d\'ici dimanche', false);

    Settings::set('stock.planning_banner', false);
    Livewire::test(Week::class)->assertDontSee('à consommer d\'ici dimanche', false);
});

test('le filtre « À surveiller » de la page Stock', function () {
    Livewire::withQueryParams(['filtre' => 'alertes'])->test(Index::class)
        ->assertSee('Crème liquide')->assertSee('Chocolat noir')->assertDontSee('Beurre')->assertDontSee('Feta');
});
