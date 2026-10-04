<?php

use App\Enums\UserRole;
use App\Livewire\Dashboard;
use App\Livewire\Evening;
use App\Livewire\Layout\Undo;
use App\Livewire\Planner\MealPicker;
use App\Livewire\Planner\Week;
use App\Livewire\Settings\Notifications as NotificationsPage;
use App\Models\Ingredient;
use App\Models\MealOccasion;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\PushSubscription;
use App\Models\Reminder;
use App\Models\Stay;
use App\Models\StockMovement;
use App\Models\Tag;
use App\Models\UndoToken;
use App\Models\User;
use App\Models\Wish;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\WebPush;
use App\Services\Planning\EveningRendezvous;
use App\Services\Planning\PrepReminderPlanner;
use App\Services\Planning\WeekFiller;
use App\Services\Planning\WeekPlanner;
use App\Services\Stock\StockManager;
use App\Support\Settings;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

require_once __DIR__.'/../Shopping/helpers.php';

/*
 * Lot 37 — Le bon écran au bon moment : rendez-vous du soir (37.1, R38), « Hier comme prévu » (37.2),
 * infos de la semaine repliées (37.3), « Autre idée » (37.4), fiche recette sur téléphone (37.5).
 */

/** Un appareil abonné aux notifications (clé P-256 valable : le message est réellement chiffré). */
function eveningDevice(User $user, string $endpoint = 'https://push.example.test/soir'): PushSubscription
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'config' => resource_path('openssl/openssl.cnf')]);
    $details = openssl_pkey_get_details($key);
    $public = "\x04".str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

    return PushSubscription::create([
        'user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashOf($endpoint),
        'public_key' => WebPush::b64($public), 'auth_token' => WebPush::b64(random_bytes(16)), 'device' => 'Test',
    ]);
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 20:45'));   // jeudi soir, après le rendez-vous de 20 h 30
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);
    $this->actingAs($this->pierre = User::factory()->create(['name' => 'Pierre']));
    $this->monique = User::factory()->create(['name' => 'Monique']);
    $this->lunch = MealSlot::factory()->create(['name' => 'Déjeuner', 'sort_order' => 1]);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 2]);

    $this->planner = app(WeekPlanner::class);
    $this->evening = app(EveningRendezvous::class);
    $this->stock = app(StockManager::class);

    $this->chili = recipeWith('Chili con carne', 4, [[500, 'g', 'Bœuf haché', false]]);
    $this->omelette = recipeWith('Omelette', 2, [[4, 'piece', 'Œuf', false]]);
    $this->houmous = recipeWith('Houmous', 4, [[250, 'g', 'Pois chiches secs', false]]);
    $this->houmous->steps()->create(['position' => 1, 'instruction' => 'La veille, mettre les pois chiches à tremper.']);
});

/* ================================================================ 37.1 Rendez-vous du soir */

test('le message du soir regroupe le repas à clôturer, la préparation de demain et la gamelle', function () {
    $diner = $this->planner->addRecipe('2026-10-15', $this->dinner, $this->chili, 4);
    $this->planner->addRecipe('2026-10-15', $this->lunch, $this->omelette, 2);
    $this->planner->addRecipe('2026-10-16', $this->dinner, $this->houmous, 2);             // à tremper ce soir
    $this->planner->addLeftover('2026-10-16', $this->lunch, $diner, 1, app(\App\Services\People\HouseholdPeople::class)->forUser($this->pierre)->id);   // gamelle de Pierre
    app(PrepReminderPlanner::class)->sync(Carbon::today(), Carbon::today()->addDays(3));

    $message = collect(app(NotificationDispatcher::class)->messagesFor($this->pierre, now()))->firstWhere('type', 'evening');

    expect($message['title'])->toBe('Chili con carne : c\'était mangé ?')               // le dernier repas du jour d'abord
        ->and($message['body'])->toContain('+ 1 autre repas à clôturer')
        ->and($message['body'])->toContain('Pour demain : faire tremper')
        ->and($message['body'])->toContain('1 gamelle à préparer')
        ->and($message['url'])->toBe(route('evening'))
        ->and($message['badge'])->toBe(2);
});

test('une seule notification par soir, à l\'heure choisie ; le rappel de la veille ne part pas seul', function () {
    Http::fake(['push.example.test/*' => Http::response('', 201)]);
    eveningDevice($this->pierre);
    $this->planner->addRecipe('2026-10-16', $this->dinner, $this->houmous, 2);
    $dispatcher = app(NotificationDispatcher::class);

    // 18 h 30 : le rappel « tremper » (dû à 18 h) attend le rendez-vous.
    $this->travelTo(Carbon::parse('2026-10-15 18:30'));
    expect($dispatcher->run()['sent'])->toBe(0);

    $this->travelTo(Carbon::parse('2026-10-15 20:35'));
    expect($dispatcher->run()['sent'])->toBe(1)
        ->and($dispatcher->run()['sent'])->toBe(0);

    Http::assertSentCount(1);
});

test('sans le rendez-vous (ou s\'il n\'a pas pu partir), les rappels arrivent un par un', function () {
    $this->planner->addRecipe('2026-10-16', $this->dinner, $this->houmous, 2);
    app(PrepReminderPlanner::class)->sync(Carbon::today(), Carbon::today()->addDays(3));
    $dispatcher = app(NotificationDispatcher::class);

    // Fenêtre passée (ordinateur éteint de 20 h 30 à 23 h 30) : le rappel repart seul.
    $late = $dispatcher->messagesFor($this->pierre, Carbon::parse('2026-10-15 23:40'));
    expect(collect($late)->pluck('type')->all())->toBe(['prep']);

    $this->pierre->setPreference('notify.evening', false);
    $early = $dispatcher->messagesFor($this->pierre, Carbon::parse('2026-10-15 18:30'));
    expect(collect($early)->pluck('type')->all())->toBe(['prep']);
});

test('rien à faire, absent du repas ou en séjour : pas de notification', function () {
    expect($this->evening->message($this->pierre, now(), 'k'))->toBeNull();

    $this->planner->addRecipe('2026-10-15', $this->dinner, $this->chili, 4);
    MealOccasion::create(['date' => '2026-10-15', 'meal_slot_id' => $this->dinner->id, 'absent_user_ids' => [$this->pierre->id]]);

    expect($this->evening->message($this->pierre, now(), 'k'))->toBeNull()
        ->and($this->evening->message($this->monique, now(), 'k')['title'])->toBe('Chili con carne : c\'était mangé ?');

    $stay = Stay::create(['name' => 'Ardennes', 'starts_on' => '2026-10-14', 'ends_on' => '2026-10-18', 'split_mode' => 'appetite']);
    $stay->participants()->create(['name' => 'Monique', 'appetite' => 'normal', 'group_label' => 'Nous', 'user_id' => $this->monique->id]);

    expect($this->evening->message($this->monique, now(), 'k'))->toBeNull();
});

test('réglage du rendez-vous : activé pour les comptes complets, heure au choix, désactivable', function () {
    $viewer = User::factory()->create(['role' => UserRole::Viewer]);

    expect(EveningRendezvous::settings($this->pierre))->toBe(['on' => true, 'time' => '20:30'])
        ->and(EveningRendezvous::settings($viewer)['on'])->toBeFalse();

    Livewire::test(NotificationsPage::class)
        ->assertSee('Le rendez-vous du soir')
        ->set('eveningAt', '21:00')->call('saveEvening')->assertHasNoErrors()
        ->set('eveningAt', '25:00')->call('saveEvening')->assertHasErrors('eveningAt');

    expect(EveningRendezvous::settings($this->pierre->fresh())['time'])->toBe('21:00')
        ->and($this->evening->isDue($this->pierre->fresh(), Carbon::parse('2026-10-15 20:45')))->toBeFalse()
        ->and($this->evening->isDue($this->pierre->fresh(), Carbon::parse('2026-10-15 21:05')))->toBeTrue();

    Livewire::test(NotificationsPage::class)->set('evening', false)->call('saveEvening');
    expect($this->evening->isDue($this->pierre->fresh(), Carbon::parse('2026-10-15 21:05')))->toBeFalse();
});

test('la page « Ce soir » : clôturer, préparer demain, rattrapage replié', function () {
    $diner = $this->planner->addRecipe('2026-10-15', $this->dinner, $this->chili, 4);
    $old = $this->planner->addRecipe('2026-10-10', $this->dinner, $this->omelette, 2);
    $this->planner->addRecipe('2026-10-16', $this->dinner, $this->houmous, 2);

    $page = Livewire::test(Evening::class)
        ->assertSee('C\'était mangé ?', false)
        ->assertSee('Chili con carne')
        ->assertSee('1 repas des jours précédents')
        ->assertSee('Faire tremper — houmous')
        ->assertSee('Demain, vendredi');

    $reminder = Reminder::query()->firstOrFail();
    $page->call('reminderDone', $reminder->id)->call('closeSkipped', $old->id);

    expect($reminder->fresh()->status)->toBe(Reminder::DONE)
        ->and($old->fresh()->isSkipped())->toBeTrue();

    $page->call('closeEaten', $diner->id);
    expect($diner->fresh()->cooked_at)->not->toBeNull();

    Livewire::test(Evening::class)->assertSee('Restes à placer')->assertSee('2 portions en trop');
});

/* ================================================================ 37.2 Hier comme prévu */

test('« Hier comme prévu » marque mangés les repas d\'hier avec le stock, et « Annuler » remet tout', function () {
    $oeufs = $this->stock->add(['ingredient_id' => Ingredient::firstWhere('name', 'Œuf')->id, 'quantity' => 6]);
    $boeuf = $this->stock->add(['ingredient_id' => Ingredient::firstWhere('name', 'Bœuf haché')->id, 'quantity' => 500]);
    $this->omelette->forceFill(['is_to_test' => true])->save();
    $midi = $this->planner->addRecipe('2026-10-14', $this->lunch, $this->omelette, 2);
    $soir = $this->planner->addRecipe('2026-10-14', $this->dinner, $this->chili, 4);
    $avant = $this->planner->addRecipe('2026-10-12', $this->dinner, $this->chili, 4);      // pas « hier »
    $movements = StockMovement::count();

    Livewire::test(Dashboard::class)->assertSee('Hier comme prévu')->call('closeYesterday')
        ->assertDispatched('notify', fn ($event, $params) => str_contains($params['message'], '2 repas marqués mangés, stock mis à jour')
            && ($params['action']['label'] ?? null) === 'Annuler');

    expect($midi->fresh()->cooked_at)->not->toBeNull()
        ->and($soir->fresh()->stock_state)->toBe('done')
        ->and((float) $oeufs->fresh()->quantity)->toBe(2.0)
        ->and($boeuf->fresh()->finished_at)->not->toBeNull()
        ->and($this->omelette->fresh()->is_to_test)->toBeFalse()
        ->and($avant->fresh()->cooked_at)->toBeNull();

    Livewire::test(Undo::class)->call('undo', UndoToken::query()->latest('id')->value('token'))->assertDispatched('bouffe-undone');

    expect($midi->fresh()->cooked_at)->toBeNull()
        ->and($soir->fresh()->stock_state)->toBeNull()
        ->and((float) $oeufs->fresh()->quantity)->toBe(6.0)
        ->and($boeuf->fresh()->finished_at)->toBeNull()
        ->and(StockMovement::count())->toBe($movements)
        ->and($this->omelette->fresh()->is_to_test)->toBeTrue();
});

test('« Tout comme prévu » sur la page du soir : aujourd\'hui et hier seulement ; jamais pour un compte en consultation', function () {
    $today = $this->planner->addRecipe('2026-10-15', $this->dinner, $this->omelette, 2);
    $yesterday = $this->planner->addRecipe('2026-10-14', $this->dinner, $this->omelette, 2);
    $older = $this->planner->addRecipe('2026-10-11', $this->dinner, $this->omelette, 2);
    Settings::set('stock.deduction_mode', 'never');

    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer]));
    Livewire::test(Evening::class)->call('closeAllAsPlanned');
    expect($today->fresh()->cooked_at)->toBeNull();

    $this->actingAs($this->pierre);
    Livewire::test(Evening::class)->call('closeAllAsPlanned');

    expect($today->fresh()->cooked_at)->not->toBeNull()
        ->and($yesterday->fresh()->cooked_at)->not->toBeNull()
        ->and($older->fresh()->cooked_at)->toBeNull()
        ->and($today->fresh()->stock_state)->toBeNull();           // « jamais » : le stock n'est pas touché
});

/* ================================================================ 37.3 Planning */

test('planning : les infos de la semaine tiennent sur une ligne', function () {
    $this->stock->add(['ingredient_id' => Ingredient::firstWhere('name', 'Œuf')->id, 'quantity' => 6, 'expires_on' => '2026-10-16']);
    $this->planner->addRecipe('2026-10-15', $this->dinner, $this->chili, 4);

    Livewire::test(Week::class)
        ->assertSee('infos sur la semaine')
        ->assertSee('astuce, équilibre, 1 produit à consommer', false);
});

/* ================================================================ 37.4 Autre idée */

test('idées pour une case : trois plats, ni dessert ni sous-recette, ni ce qui est déjà dans la semaine', function () {
    $dessert = Tag::factory()->create(['name' => 'Dessert']);
    $tarte = recipeWith('Tarte tatin', 6, [[1, 'kg', 'Pomme', false]]);
    $tarte->tags()->attach($dessert);
    $pate = recipeWith('Pâte brisée', 4, [[250, 'g', 'Farine', false]]);
    $quiche = recipeWith('Quiche lorraine', 4, [[200, 'g', 'Lardons', false]]);
    $quiche->components()->create(['component_recipe_id' => $pate->id, 'quantity' => 1]);
    $gratin = recipeWith('Gratin dauphinois', 4, [[1, 'kg', 'Pomme de terre', false]]);
    $this->planner->addRecipe('2026-10-14', $this->dinner, $this->omelette, 2);           // déjà dans la semaine

    $filler = app(WeekFiller::class);
    $titles = collect($filler->ideasFor('2026-10-16', $this->dinner))->pluck('title');

    expect($titles)->toHaveCount(3)
        ->and($titles->diff(['Chili con carne', 'Houmous', 'Quiche lorraine', 'Gratin dauphinois'])->all())->toBe([])
        ->and(collect($filler->ideasFor('2026-10-16', $this->dinner, 3, [$this->chili->id, $quiche->id]))->mapWithKeys(fn ($i) => [$i['title'] => $i['warnings']])->sortKeys()->all())
        ->toBe(['Gratin dauphinois' => [], 'Houmous' => [], 'Omelette' => ['déjà au menu cette semaine']])   // petit carnet : en dernier recours, en le disant
        ->and(collect($filler->ideasFor('2026-10-16', $this->dinner, 3, [], null, \App\Enums\Course::Dessert))->pluck('title')->all())->toBe(['Tarte tatin']);
});

test('le sélecteur d\'une case propose trois idées, « Autre idée » en tire d\'autres', function () {
    foreach (['Gratin dauphinois', 'Tian', 'Lasagnes', 'Risotto'] as $title) {
        recipeWith($title, 4, [[100, 'g', 'Riz basmati', false]]);
    }

    $picker = Livewire::test(MealPicker::class)->call('open', '2026-10-16', $this->dinner->id)->assertSee('Idées pour cette case');
    $first = collect($picker->get('ideas'))->pluck('recipe_id')->all();

    $picker->call('otherIdeas');
    $second = collect($picker->get('ideas'))->pluck('recipe_id')->all();

    expect($first)->toHaveCount(3)
        ->and(array_intersect($first, $second))->toBe([]);                  // 7 recettes : trois autres

    $picker->call('pickRecipe', $second[0]);
    expect(PlannedMeal::query()->whereDate('date', '2026-10-16')->value('recipe_id'))->toBe($second[0]);
});

test('« Autre idée » dans le détail d\'un plat : il est remplacé, l\'envie redevient à planifier, « Annuler » le remet', function () {
    $meal = $this->planner->addRecipe('2026-10-16', $this->dinner, $this->chili, 3);
    $this->planner->update($meal, ['comment' => 'avec du riz']);
    $wish = Wish::create(['user_id' => $this->monique->id, 'recipe_id' => $this->chili->id, 'planned_meal_id' => $meal->id, 'planned_at' => now()]);

    $week = Livewire::test(Week::class)->call('selectMeal', $meal->id)->call('otherIdea');
    $ideas = $week->get('mealIdeas');

    expect(collect($ideas)->pluck('recipe_id'))->not->toContain($this->chili->id)->not->toBeEmpty();

    $week->call('replaceWithIdea', $ideas[0]['recipe_id'])->assertDispatched('notify', fn ($e, $p) => ($p['action']['label'] ?? null) === 'Annuler');

    $fresh = $meal->fresh();
    expect($fresh->recipe_id)->toBe($ideas[0]['recipe_id'])
        ->and((float) $fresh->servings)->toBe(3.0)
        ->and($fresh->comment)->toBe('avec du riz')
        ->and($wish->fresh()->planned_at)->toBeNull();

    Livewire::test(Undo::class)->call('undo', UndoToken::query()->latest('id')->value('token'));

    expect($meal->fresh()->recipe_id)->toBe($this->chili->id)
        ->and($wish->fresh()->planned_meal_id)->toBe($meal->id);

    // Un plat déjà mangé ne se remplace plus.
    $this->planner->toggleCooked($meal->fresh());
    expect(fn () => $this->planner->replaceRecipe($meal->fresh(), $this->omelette))->toThrow(InvalidArgumentException::class);
});

/* ================================================================ 37.5 Fiche recette */

test('fiche recette sur téléphone : les temps en une ligne, « Cuisiner », « Planifier » et « Plus »', function () {
    $this->chili->forceFill(['prep_minutes' => 20, 'cook_minutes' => 90])->save();

    $this->get(route('recipes.show', $this->chili))
        ->assertOk()
        ->assertSee('data-recipe-facts', false)
        ->assertSee('data-recipe-phone-actions', false)
        ->assertSeeInOrder(['data-recipe-phone-actions', 'Cuisiner', 'Planifier', 'Plus', 'Ajouter aux envies', 'Supprimer'], false);
});
