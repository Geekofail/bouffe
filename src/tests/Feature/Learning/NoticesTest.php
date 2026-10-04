<?php

use App\Enums\UserRole;
use App\Livewire\Journal;
use App\Livewire\Prices\Index as PricesIndex;
use App\Livewire\Settings\Display;
use App\Livewire\Settings\Notifications as NotificationSettings;
use App\Models\ActivityEvent;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\IngredientPrice;
use App\Models\MealOccasion;
use App\Models\MealSlot;
use App\Models\Recipe;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Models\Wish;
use App\Services\Activity\ActivityLog;
use App\Services\Households\HouseholdManager;
use App\Services\Linked\HouseholdLinks;
use App\Services\Linked\SharedMeals;
use App\Services\Linked\Surplus;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Planning\WeekPlanner;
use App\Services\Pricing\PersonalInflation;
use App\Services\Pricing\PriceBook;
use App\Services\Pricing\PriceComparison;
use App\Services\Shopping\ShoppingListManager;
use App\Support\CurrentHousehold;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\MealSlotSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Bouffe prévient (lot 30) : journal du foyer (30.2), aide contextuelle (30.3),
 * notifications étendues et « ne pas déranger » (30.6), promotions (30.7, R35).
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00'));   // mercredi
    $this->seed([UnitSeeder::class, AisleSeeder::class, MealSlotSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);

    $this->home = Household::query()->orderBy('id')->first();
    $this->pierre = User::factory()->create(['name' => 'Pierre']);
    $this->monique = User::factory()->create(['name' => 'Monique']);
    $this->actingAs($this->pierre);

    $this->prices = app(PriceBook::class);
    $this->lidl = Store::create(['name' => 'Lidl']);
    $this->cactus = Store::create(['name' => 'Cactus']);
    $this->butter = Ingredient::firstWhere('name', 'Beurre');
    $this->gram = Unit::firstWhere('code', 'g');
});

/* ================================================================ Journal du foyer (30.2) */

test('le journal note les gestes de chacun et regroupe ceux qui se suivent', function () {
    $slot = MealSlot::query()->active()->ordered()->first();
    $list = app(ShoppingListManager::class)->currentOrNew();
    $items = collect(['Pain', 'Lait', 'Pommes'])->map(fn ($label) => app(ShoppingListManager::class)->addManual($list, $label));

    $this->actingAs($this->monique);
    foreach ($items as $item) {
        app(ShoppingListManager::class)->toggleCheck($item);
    }

    $this->actingAs($this->pierre);
    app(WeekPlanner::class)->addFree('2026-10-15', $slot, 'Soupe');
    app(WeekPlanner::class)->addFree('2026-10-16', $slot, 'Pizza');
    Wish::create(['user_id' => $this->pierre->id, 'text' => 'Lasagnes']);

    $events = ActivityEvent::query()->with('user')->get()->map(fn ($e) => $e->user->name.' '.$e->summary)->all();

    expect($events)->toContain('Monique a coché 3 articles dans « '.$list->name.' »')
        ->toContain('Pierre a planifié 2 repas')
        ->toContain('Pierre a ajouté une envie : Lasagnes');
});

test('les gestes espacés de plus de 30 minutes font des lignes séparées', function () {
    $slot = MealSlot::query()->active()->ordered()->first();
    app(WeekPlanner::class)->addFree('2026-10-15', $slot, 'Soupe');
    $this->travel(31)->minutes();
    app(WeekPlanner::class)->addFree('2026-10-16', $slot, 'Pizza');

    expect(ActivityEvent::where('type', 'planning.planned')->pluck('summary')->all())->toBe(['a planifié « Soupe »', 'a planifié « Pizza »']);
});

test('la page du journal : par jour, filtrable par personne et par type', function () {
    $slot = MealSlot::query()->active()->ordered()->first();
    app(WeekPlanner::class)->addFree('2026-10-15', $slot, 'Soupe');
    $this->actingAs($this->monique);
    Wish::create(['user_id' => $this->monique->id, 'text' => 'Crêpes']);

    Livewire::test(Journal::class)
        ->assertSee('Aujourd\'hui')->assertSee('a planifié « Soupe »')->assertSee('a ajouté une envie : Crêpes')
        ->set('person', $this->pierre->id)
        ->assertSee('a planifié « Soupe »')->assertDontSee('Crêpes')
        ->set('person', 0)->set('family', 'wish')
        ->assertSee('Crêpes')->assertDontSee('Soupe');

    $this->get(route('journal'))->assertOk()->assertSee('Journal du foyer');
});

test('le journal est propre au foyer et oublie ce qui a plus de 30 jours', function () {
    Wish::create(['user_id' => $this->pierre->id, 'text' => 'Lasagnes']);

    $other = app(HouseholdManager::class)->create('Léo et Clara', $this->pierre);
    $leo = User::factory()->create(['name' => 'Léo']);
    app(HouseholdManager::class)->attach($other, $leo, UserRole::Owner);
    app(HouseholdManager::class)->detach($this->home, $leo);
    $this->actingAs($leo);
    CurrentHousehold::forget();

    expect(ActivityEvent::count())->toBe(0);
    Livewire::test(Journal::class)->assertDontSee('Lasagnes');

    $this->actingAs($this->pierre);
    CurrentHousehold::forget();
    $this->travel(31)->days();
    expect(app(ActivityLog::class)->purge())->toBe(1)->and(ActivityEvent::count())->toBe(0);
});

test('rien n\'est noté sans personne connectée (tâches planifiées)', function () {
    auth()->logout();
    CurrentHousehold::run($this->home, fn () => Wish::create(['user_id' => $this->pierre->id, 'text' => 'Lasagnes']));

    expect(ActivityEvent::query()->withoutGlobalScopes()->count())->toBe(0);
});

/* ================================================================ Aide contextuelle (30.3) */

test('une aide s\'affiche une fois ; « Compris » la retire ; Paramètres les réaffiche', function () {
    $this->get(route('stock.index'))->assertSee('data-hint="stock"', false);

    $this->post(route('hints.seen', 'stock'))->assertNoContent();
    expect($this->pierre->fresh()->preference('hints_seen'))->toBe(['stock']);
    $this->get(route('stock.index'))->assertDontSee('data-hint="stock"', false);

    $this->post(route('hints.seen', 'inconnue'))->assertNotFound();

    Livewire::test(Display::class)->assertSee('Vous en avez fermé 1 sur 5.')->call('resetHints');
    expect($this->pierre->fresh()->preference('hints_seen'))->toBe([]);

    Livewire::test(Display::class)->call('toggleHints');
    $this->get(route('stock.index'))->assertDontSee('data-hint="stock"', false);
});

/* ================================================================ Promotions (30.7, R35) */

test('un prix en promotion ne devient pas le prix de référence, ni le prix courant du magasin', function () {
    $this->prices->record($this->butter, 2.00, 250, $this->gram, $this->lidl, Carbon::parse('2026-10-01'));
    $this->prices->record($this->butter, 2.40, 250, $this->gram, $this->cactus, Carbon::parse('2026-10-02'));
    $promo = $this->prices->record($this->butter, 1.50, 250, $this->gram, $this->lidl, Carbon::parse('2026-10-10'), IngredientPrice::MANUAL, true);

    expect($promo->is_promo)->toBeTrue()
        ->and((float) Ingredient::find($this->butter->id)->reference_price)->toBe(0.0096);   // 2,40 € / 250 g, le dernier prix hors promo

    $product = app(PriceComparison::class)->products()->sole();
    $plain = fn (string $text) => str_replace(["\u{00A0}", "\u{202F}"], ' ', $text);
    expect($product['prices']->map(fn ($r) => $r['store']->name.' '.$plain($r['label']))->all())->toBe(['Lidl 8,00 € / kg', 'Cactus 9,60 € / kg'])
        ->and($plain($product['best']['label']))->toBe('6,00 € / kg')
        ->and($product['best']['store']->name)->toBe('Lidl');

    Livewire::test(PricesIndex::class)->assertSee('Meilleur prix vu')->assertSee('promo');
});

test('l\'indice du panier et les hausses marquées ignorent les promotions', function () {
    $this->prices->record($this->butter, 1.50, 250, $this->gram, $this->lidl, Carbon::parse('2026-09-01'), IngredientPrice::MANUAL, true);
    $this->prices->record($this->butter, 2.00, 250, $this->gram, $this->lidl, Carbon::parse('2026-10-10'));

    // Sans la promotion, pas de « hausse » de 33 % : un seul prix courant.
    expect(app(PersonalInflation::class)->rises())->toBeEmpty();
});

test('noter un prix « en promotion » depuis la page des prix', function () {
    Livewire::test(PricesIndex::class)
        ->call('openForm', $this->butter->id, $this->lidl->id)
        ->set('notePrice', '1,49')->set('noteQuantity', '250')->set('noteUnitId', $this->gram->id)->set('notePromo', true)
        ->call('savePrice');

    expect(IngredientPrice::sole()->is_promo)->toBeTrue()
        ->and(Ingredient::find($this->butter->id)->reference_price)->toBeNull();
});

test('supprimer un relevé : la référence reprend le dernier prix hors promotion', function () {
    $this->prices->record($this->butter, 2.00, 250, $this->gram, $this->lidl, Carbon::parse('2026-10-01'));
    $this->prices->record($this->butter, 1.00, 250, $this->gram, $this->lidl, Carbon::parse('2026-10-05'), IngredientPrice::MANUAL, true);
    $last = $this->prices->record($this->butter, 2.50, 250, $this->gram, $this->lidl, Carbon::parse('2026-10-10'));

    $this->prices->forget($last);
    expect((float) Ingredient::find($this->butter->id)->reference_price)->toBe(0.008);
});

/* ================================================================ Notifications (30.6) */

test('nouveaux types : invitation d\'un foyer relié et réponse activées par défaut, les autres non', function () {
    expect(NotificationDispatcher::wants($this->pierre, 'linked_invite'))->toBeTrue()
        ->and(NotificationDispatcher::wants($this->pierre, 'linked_reply'))->toBeTrue()
        ->and(NotificationDispatcher::wants($this->pierre, 'surplus'))->toBeFalse()
        ->and(NotificationDispatcher::wants($this->pierre, 'rating'))->toBeFalse()
        ->and(NotificationDispatcher::wants($this->pierre, 'price_rise'))->toBeFalse();
});

test('invitation, réponse, surplus, avis et hausse de prix deviennent des messages', function () {
    $manager = app(HouseholdManager::class);
    $other = $manager->create('Léo et Clara', $this->pierre);
    $leo = User::factory()->create(['name' => 'Léo']);
    $manager->attach($other, $leo, UserRole::Owner);
    $manager->detach($this->home, $leo);
    $links = app(HouseholdLinks::class);
    $invite = $links->invite($this->home, $this->pierre);
    $links->accept($invite['link'], $other, $leo);

    foreach (['surplus', 'rating', 'price_rise'] as $type) {
        $this->pierre->setPreference('notify.'.$type, true);
    }

    // Pierre invite Léo et Clara ; ils répondent ; ils donnent des courgettes et notent sa quiche.
    $slot = MealSlot::query()->active()->ordered()->first();
    $occasion = MealOccasion::create(['date' => '2026-10-24', 'meal_slot_id' => $slot->id, 'title' => 'Brunch']);
    $row = app(SharedMeals::class)->invite($occasion, $other->id, $this->pierre);
    $quiche = Recipe::factory()->create(['title' => 'Quiche', 'visibility' => 'linked']);

    $this->actingAs($leo);
    CurrentHousehold::forget();

    // Côté Léo : l'invitation.
    $leoMessages = collect(app(NotificationDispatcher::class)->messagesFor($leo, now()));
    expect($leoMessages->firstWhere('type', 'linked_invite')['title'] ?? null)->toBe($this->home->name.' vous invite')
        ->and($leoMessages->firstWhere('type', 'linked_invite')['body'])->toBe('Brunch du samedi 24 octobre');

    app(SharedMeals::class)->respond($row->fresh(), true, 4, 'Avec plaisir');
    app(Surplus::class)->offer(['label' => 'Courgettes', 'quantity' => '1 kg', 'available_until' => '2026-10-16'], $leo);
    $quiche->ratings()->create(['user_id' => $leo->id, 'household_id' => $other->id, 'rating' => 5]);

    // Côté Pierre : réponse, surplus, avis, hausse.
    $this->actingAs($this->pierre);
    CurrentHousehold::forget();
    $this->prices->record($this->butter, 2.00, 250, $this->gram, $this->lidl, Carbon::parse('2026-09-01'));
    $this->prices->record($this->butter, 2.50, 250, $this->gram, $this->lidl, Carbon::parse('2026-10-14'));

    $messages = collect(app(NotificationDispatcher::class)->messagesFor($this->pierre, now()))->keyBy('type');
    expect($messages['linked_reply']['title'])->toBe('Léo et Clara viendra à 4')
        ->and($messages['linked_reply']['body'])->toBe('Brunch du samedi 24 octobre · « Avec plaisir »')
        ->and($messages['surplus']['title'])->toBe('Léo et Clara donne : Courgettes')
        ->and($messages['rating']['title'])->toBe('Léo a noté « Quiche »')
        ->and($messages['price_rise']['title'])->toBe('Hausse de prix : Beurre +25 %');

    // Plus de 24 heures après : plus rien de neuf.
    $this->travel(2)->days();
    expect(collect(app(NotificationDispatcher::class)->messagesFor($this->pierre, now()))->whereIn('type', ['linked_reply', 'surplus', 'rating'])->count())->toBe(0);
});

test('« ne pas déranger » : propre à chacun, réglable, désactivable', function () {
    $dispatcher = app(NotificationDispatcher::class);

    expect($dispatcher->isQuietFor($this->pierre, Carbon::parse('2026-10-14 23:00')))->toBeTrue()
        ->and($dispatcher->isQuietFor($this->pierre, Carbon::parse('2026-10-14 08:00')))->toBeFalse();

    Livewire::test(NotificationSettings::class)
        ->assertSet('quiet', true)->assertSet('quietFrom', 22)->assertSet('quietUntil', 7)
        ->set('quietFrom', 21)->set('quietUntil', 9)->call('saveQuiet')
        ->assertDispatched('notify', message: 'Rien sur votre téléphone entre 21 h et 9 h : ce qui tombe pendant attend le matin.');

    $pierre = $this->pierre->fresh();
    expect($dispatcher->isQuietFor($pierre, Carbon::parse('2026-10-14 08:30')))->toBeTrue()
        ->and($dispatcher->isQuietFor($pierre, Carbon::parse('2026-10-14 21:10')))->toBeTrue();

    Livewire::test(NotificationSettings::class)->set('quiet', false)->call('saveQuiet');
    expect($dispatcher->isQuietFor($this->pierre->fresh(), Carbon::parse('2026-10-14 23:00')))->toBeFalse()
        ->and($dispatcher->isQuietFor($this->monique, Carbon::parse('2026-10-14 23:00')))->toBeTrue();   // Monique garde les siennes
});

test('les réglages de notifications montrent les nouveaux types', function () {
    Livewire::test(NotificationSettings::class)
        ->assertSee('Invitation d\'un foyer relié')->assertSee('Réponse à mon invitation')
        ->assertSee('Surplus proposé')->assertSee('Avis reçu')->assertSee('Hausse de prix marquée')
        ->assertSee('Ne pas déranger la nuit')
        ->assertSet('types.linked_invite', true)->assertSet('types.price_rise', false);
});
