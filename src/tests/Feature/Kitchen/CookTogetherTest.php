<?php

use App\Enums\Course;
use App\Enums\UserRole;
use App\Livewire\Kitchen\Screen as KitchenScreen;
use App\Livewire\Planner\CookMeal;
use App\Models\Household;
use App\Models\KitchenTimer;
use App\Models\MealSlot;
use App\Models\MealTask;
use App\Models\PushSubscription;
use App\Models\Stay;
use App\Models\User;
use App\Services\Households\HouseholdManager;
use App\Services\Kitchen\KitchenTimers;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\WebPush;
use App\Services\Planning\WeekPlanner;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

require_once __DIR__.'/../Shopping/helpers.php';

/*
 * Lot 41 — Cuisiner à plusieurs, même sans réseau : minuteurs partagés (41.1), recettes gardées
 * dans le téléphone (41.2), qui fait quoi ce soir (41.3).
 */

/** Un appareil abonné aux notifications (clé P-256 valable : le message est réellement chiffré). */
function timerDevice(User $user, string $endpoint): PushSubscription
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
    $this->travelTo(Carbon::parse('2026-10-15 18:00'));   // jeudi
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);
    $this->home = Household::query()->orderBy('id')->first();
    $this->actingAs($this->pierre = User::factory()->create(['name' => 'Pierre']));
    $this->monique = User::factory()->create(['name' => 'Monique']);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 2]);
    $this->timers = app(KitchenTimers::class);

    $this->gratin = recipeWith('Gratin dauphinois', 4, [[1, 'kg', 'Pomme de terre', false]]);
    $this->gratin->update(['prep_minutes' => 20, 'cook_minutes' => 60]);
    $this->gratin->steps()->create(['position' => 1, 'instruction' => 'Éplucher et trancher les pommes de terre.']);
    $this->gratin->steps()->create(['position' => 2, 'instruction' => 'Enfourner 60 minutes à 160 °C.']);
    $this->tarte = recipeWith('Tarte aux pommes', 6, [[4, 'piece', 'Pomme', false]]);
    $this->tarte->steps()->create(['position' => 1, 'instruction' => 'Étaler la pâte et disposer les pommes.']);
    $this->tarte->steps()->create(['position' => 2, 'instruction' => 'Cuire 35 minutes.']);
});

/* ================================================================ 41.1 Minuteurs partagés */

test('un minuteur lancé sur un appareil se lit et s\'arrête de n\'importe quel autre', function () {
    $this->postJson(route('timers.store'), ['label' => 'Gratin · 60 min', 'seconds' => 3600, 'source' => 'recette:'.$this->gratin->id])
        ->assertCreated()
        ->assertJsonPath('timers.0.label', 'Gratin · 60 min')
        ->assertJsonPath('timers.0.mine', true);

    $timer = KitchenTimer::query()->sole();
    expect($timer->ends_at->toDateTimeString())->toBe('2026-10-15 19:00:00');

    // Monique, sur la tablette : le même minuteur, « lancé par Pierre ».
    $this->actingAs($this->monique)->getJson(route('timers.index'))
        ->assertOk()
        ->assertJsonPath('timers.0.by', 'Pierre')
        ->assertJsonPath('timers.0.mine', false)
        ->assertJsonPath('timers.0.endsAt', Carbon::parse('2026-10-15 19:00:00')->getTimestamp() * 1000);

    $this->postJson(route('timers.stop', $timer))->assertOk()->assertJsonCount(0, 'timers');
    expect($timer->fresh()->stopped_at)->not->toBeNull();

    $this->postJson(route('timers.store'), ['seconds' => 0])->assertUnprocessable();
});

test('les minuteurs restent dans leur foyer', function () {
    $timer = $this->timers->start('Pâtes', 600);

    $other = app(HouseholdManager::class)->create('Les voisins');
    $voisin = User::factory()->create();
    app(HouseholdManager::class)->attach($other, $voisin, UserRole::Owner);
    app(HouseholdManager::class)->detach($this->home, $voisin);

    $this->actingAs($voisin)->getJson(route('timers.index'))->assertOk()->assertJsonCount(0, 'timers');
    $this->postJson(route('timers.stop', $timer))->assertNotFound();
    expect($timer->fresh()->stopped_at)->toBeNull();
});

test('fini sans page ouverte pour le voir : une notification, une seule fois, même la nuit', function () {
    Http::fake(['push.example.test/*' => Http::response('', 201)]);
    timerDevice($this->pierre, 'https://push.example.test/pierre');
    timerDevice($this->monique, 'https://push.example.test/monique');

    $vu = $this->timers->start('Œufs mollets', 360);           // une page l'a vu sonner
    $oublie = $this->timers->start('Gratin · 60 min', 3600, 'recette:'.$this->gratin->id);
    $arrete = $this->timers->start('Thé', 180);
    $this->timers->stop($arrete);

    $this->travelTo(Carbon::parse('2026-10-15 18:30'));
    $this->timers->rang($oublie->fresh());                       // pas encore fini : rien à noter
    $this->timers->rang($vu->fresh());
    expect($oublie->fresh()->rang_at)->toBeNull()->and($vu->fresh()->rang_at)->not->toBeNull();

    // 19 h 10 : le gratin a fini à 19 h et aucune page ne l'a vu sonner → pour Pierre, qui l'a lancé.
    $this->travelTo(Carbon::parse('2026-10-15 19:10'));
    $messages = app(NotificationDispatcher::class)->messagesFor($this->pierre, now());
    $timerMessage = collect($messages)->firstWhere('type', 'timer');

    expect($timerMessage['title'])->toBe('⏰ Gratin · 60 min : c\'est l\'heure')
        ->and($timerMessage['url'])->toBe(route('recipes.cook', $this->gratin))
        ->and(collect(app(NotificationDispatcher::class)->messagesFor($this->monique, now()))->where('type', 'timer'))->toBeEmpty();

    // Heures calmes à partir de 19 h : un minuteur se signale quand même ; une seule fois.
    $this->pierre->setPreference('notify.quiet_from', 19);
    app(NotificationDispatcher::class)->run();
    expect($oublie->fresh()->notified_at)->not->toBeNull()
        ->and(collect(app(NotificationDispatcher::class)->messagesFor($this->pierre, now()))->where('type', 'timer'))->toBeEmpty();

    // Plus de 30 minutes après la fin : trop tard pour servir, plus rien.
    $tard = $this->timers->start('Riz', 60);
    $this->travelTo(Carbon::parse('2026-10-15 19:45'));
    expect($this->timers->toNotify(now())->pluck('id'))->not->toContain($tard->id);

    Http::assertSent(fn ($request) => $request->url() === 'https://push.example.test/pierre');
    Http::assertNotSent(fn ($request) => $request->url() === 'https://push.example.test/monique');
});

test('lancé sur la tablette d\'un compte sans téléphone abonné : tout le foyer est prévenu', function () {
    timerDevice($this->monique, 'https://push.example.test/monique');
    $this->timers->start('Pain', 120);
    $this->travelTo(Carbon::parse('2026-10-15 18:03'));

    expect(collect(app(NotificationDispatcher::class)->messagesFor($this->monique, now()))->firstWhere('type', 'timer')['body'])
        ->toEndWith('lancé par Pierre.');

    $this->monique->setPreference('notify.timer', false);
    expect(collect(app(NotificationDispatcher::class)->messagesFor($this->monique, now()))->where('type', 'timer'))->toBeEmpty();
});

test('les pages de cuisine montrent les minuteurs de la maison', function () {
    $this->get(route('recipes.cook', $this->gratin))->assertOk()
        ->assertSee('data-timers-panel', false)
        ->assertSee('recette:'.$this->gratin->id, false);
    $this->get(route('kitchen'))->assertOk()->assertSee('Les minuteurs lancés sur un téléphone apparaissent ici');
    // La pastille du menu, sur toutes les pages.
    $this->get(route('dashboard'))->assertOk()->assertSee('data-timer-pill', false);
});

/* ================================================================ 41.2 Sans réseau */

test('recettes sans réseau : la semaine, chaque recette aux portions prévues, et un séjour', function () {
    $planner = app(WeekPlanner::class);
    $planner->addRecipe('2026-10-15', $this->dinner, $this->gratin, 3);
    $planner->addFree('2026-10-16', $this->dinner, 'Restaurant');

    $url = route('offline.recipe', ['recipe' => $this->gratin, 'portions' => '3']);

    $this->get(route('offline.week', ['semaine' => '2026-10-12']))
        ->assertOk()
        ->assertSee('Garder sur cet appareil')
        ->assertSee('Gratin dauphinois')
        ->assertSee('Restaurant')
        ->assertSee(str_replace('/', '\/', $url), false);                     // dans la liste des pages à garder

    $this->get($url)->assertOk()
        ->assertSee('3 portions')
        ->assertSee('750')                                                     // 1 kg pour 4 → 750 g pour 3
        ->assertSee('data-offline-timer', false)
        ->assertSee('data-minutes="60"', false)
        ->assertDontSee('wire:snapshot', false);                               // page autonome, sans Livewire

    $stay = Stay::create(['name' => 'Chalet', 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-22', 'split_mode' => 'appetite']);
    $stay->meals()->create(['date' => '2026-10-21', 'meal_slot_id' => $this->dinner->id, 'recipe_id' => $this->tarte->id, 'servings' => 8]);

    $this->get(route('offline.stay', $stay))->assertOk()->assertSee('Séjour « Chalet »')->assertSee('Tarte aux pommes')->assertSee('8 portions');
    $this->get(route('offline.week'))->assertSee('Chalet');
});

test('sans connexion, pas de recette ; celle d\'un autre foyer n\'existe pas', function () {
    $other = app(HouseholdManager::class)->create('Les voisins');
    $voisin = User::factory()->create();
    app(HouseholdManager::class)->attach($other, $voisin, UserRole::Owner);
    app(HouseholdManager::class)->detach($this->home, $voisin);

    $this->actingAs($voisin)->get(route('offline.recipe', $this->gratin))->assertNotFound();

    auth()->logout();
    $this->get(route('offline.week'))->assertRedirect(route('login'));
});

/* ================================================================ 41.3 Qui fait quoi */

test('qui fait quoi : un plat par personne, une étape confiée, les étapes cochées partagées', function () {
    $planner = app(WeekPlanner::class);
    $gratin = $planner->addRecipe('2026-10-15', $this->dinner, $this->gratin, 4);
    $tarte = $planner->addRecipe('2026-10-15', $this->dinner, $this->tarte, 4);
    $tarte->update(['course' => Course::Dessert]);
    $params = ['date' => '2026-10-15', 'slot' => $this->dinner->id];

    Livewire::test(CookMeal::class, $params)
        ->assertSee('Qui ?')
        ->call('assignDish', $gratin->id, (string) $this->pierre->id)
        ->call('assignDish', $tarte->id, (string) $this->monique->id)
        ->call('assignStep', $tarte->id.'-1', (string) $this->pierre->id)   // Pierre étale la pâte
        ->call('toggle', $gratin->id.'-1');

    expect($gratin->fresh()->cook_user_id)->toBe($this->pierre->id)
        ->and($tarte->fresh()->cook_user_id)->toBe($this->monique->id)
        ->and(MealTask::query()->where('planned_meal_id', $tarte->id)->value('user_id'))->toBe($this->pierre->id);

    // Sur le téléphone de Monique : ses étapes seulement (la cuisson de la tarte), et l'étape faite par Pierre.
    $this->actingAs($this->monique);
    $page = Livewire::test(CookMeal::class, $params)->assertSee('faite par Pierre')->assertSee('Étapes faites : 1 / 4');
    $page->call('toggleMine')
        ->assertSet('who', 'moi')
        ->assertSee('Cuire 35 minutes.')
        ->assertDontSee('Étaler la pâte')
        ->assertDontSee('Éplucher et trancher')
        ->assertSee('3 étapes confiées à quelqu\'un d\'autre, masquées.', false);

    // L'écran de cuisine montre les deux.
    Livewire::test(KitchenScreen::class)->assertSee('Pierre')->assertSee('Monique')->assertSee('Cuisiner tout le repas · qui fait quoi');

    // Décocher, et rendre l'étape au plat : la ligne disparaît.
    Livewire::test(CookMeal::class, $params)->call('toggle', $gratin->id.'-1')->call('assignStep', $tarte->id.'-1', '');
    expect(MealTask::count())->toBe(0);
});

test('un compte en consultation coche les étapes mais ne répartit pas', function () {
    $gratin = app(WeekPlanner::class)->addRecipe('2026-10-15', $this->dinner, $this->gratin, 4);
    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer, 'name' => 'Léo']));

    Livewire::test(CookMeal::class, ['date' => '2026-10-15', 'slot' => $this->dinner->id])
        ->call('assignDish', $gratin->id, (string) $this->pierre->id)
        ->call('toggle', $gratin->id.'-2');

    expect($gratin->fresh()->cook_user_id)->toBeNull()
        ->and(MealTask::query()->sole()->done_at)->not->toBeNull();

    expect(fn () => app(\App\Services\Kitchen\MealTasks::class)->assignDish($gratin, '999999'))->toThrow(InvalidArgumentException::class);
});
