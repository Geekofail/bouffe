<?php

use App\Livewire\Layout\Notifications as Bell;
use App\Livewire\Settings\Notifications as NotificationsPage;
use App\Mail\WeeklyDigest;
use App\Models\MealSlot;
use App\Models\PushSubscription;
use App\Models\ShoppingList;
use App\Models\User;
use App\Models\Wish;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\WebPush;
use App\Services\Planning\WeekPlanner;
use App\Services\Stock\StockManager;
use App\Support\Settings;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
 * Rappels et notifications (lot 20 — 19.2, 19.3, 19.4).
 *
 * Le chiffrement Web Push est vérifié ici par un aller-retour complet (le test joue le rôle du
 * navigateur). Il a aussi été vérifié avec les bibliothèques de référence (http_ece, web-push).
 */

require_once __DIR__.'/../Shopping/helpers.php';

/** Le « navigateur » : une paire de clés P-256 et un secret d'authentification. */
function browserKeys(): array
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'config' => resource_path('openssl/openssl.cnf')]);
    $details = openssl_pkey_get_details($key);
    openssl_pkey_export($key, $pem, null, ['config' => resource_path('openssl/openssl.cnf')]);

    return [
        'private' => $pem,
        'public' => "\x04".str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT),
        'auth' => random_bytes(16),
    ];
}

/** Déchiffrement côté navigateur (RFC 8291), pour vérifier le message. */
function browserDecrypt(string $body, array $browser): string
{
    $salt = substr($body, 0, 16);
    $keyLength = ord($body[20]);
    $serverPublic = substr($body, 21, $keyLength);
    $cipher = substr($body, 21 + $keyLength, -16);
    $tag = substr($body, -16);

    $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$serverPublic), 64, "\n")."-----END PUBLIC KEY-----\n";
    $shared = openssl_pkey_derive(openssl_pkey_get_public($pem), openssl_pkey_get_private($browser['private']), 32);

    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$browser['public'].$serverPublic, $browser['auth']);
    $key = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

    $plain = openssl_decrypt($cipher, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);

    return rtrim((string) $plain, "\x02");
}

function subscribe(User $user, string $endpoint = 'https://push.example.test/abc'): PushSubscription
{
    $browser = browserKeys();

    return PushSubscription::create([
        'user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashOf($endpoint),
        'public_key' => WebPush::b64($browser['public']), 'auth_token' => WebPush::b64($browser['auth']), 'device' => 'Test',
    ]);
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 18:30'));      // jeudi soir
    $this->pierre = User::factory()->create(['name' => 'Pierre', 'email' => 'pierre@example.test']);
    $this->monique = User::factory()->create(['name' => 'Monique', 'email' => 'monique@example.test']);
    $this->actingAs($this->pierre);
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);

    $this->push = app(WebPush::class);
    $this->dispatcher = app(NotificationDispatcher::class);
    $this->slot = MealSlot::factory()->create(['name' => 'Dîner']);
});

/* ================================================================ Web Push (19.2) */

test('le message est chiffré pour le seul navigateur abonné', function () {
    $browser = browserKeys();

    $body = $this->push->encrypt('Sortir le poulet du congélateur', $browser['public'], $browser['auth']);

    expect(browserDecrypt($body, $browser))->toBe('Sortir le poulet du congélateur')
        // Un autre navigateur ne peut pas le lire.
        ->and(browserDecrypt($body, [...browserKeys(), 'auth' => $browser['auth']]))->toBe('');
});

test('l\'en-tête VAPID est signé avec la clé de Bouffe', function () {
    $header = $this->push->authorization('https://fcm.googleapis.com/fcm/send/xyz');

    expect($header)->toMatch('/^vapid t=[\w-]+\.[\w-]+\.[\w-]+, k=[\w-]+$/');

    preg_match('/t=([^,]+), k=(.+)$/', $header, $m);
    [$h, $c, $s] = explode('.', $m[1]);
    $claims = json_decode(WebPush::unb64($c), true);

    // Signature brute r||s → DER, puis vérification avec la clé publique annoncée.
    $raw = WebPush::unb64($s);
    $int = fn (string $v) => "\x02".chr(strlen($v = ltrim($v, "\0")) + (ord($v[0]) > 0x7F ? 1 : 0)).(ord($v[0]) > 0x7F ? "\0" : '').$v;
    $seq = $int(substr($raw, 0, 32)).$int(substr($raw, 32));
    $der = "\x30".chr(strlen($seq)).$seq;
    $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').WebPush::unb64($m[2])), 64, "\n")."-----END PUBLIC KEY-----\n";

    expect($claims['aud'])->toBe('https://fcm.googleapis.com')
        ->and($claims['sub'])->toStartWith('mailto:')
        ->and(openssl_verify($h.'.'.$c, $der, $pem, OPENSSL_ALGO_SHA256))->toBe(1)
        // Les clés sont générées une fois et conservées.
        ->and($m[2])->toBe($this->push->publicKey());
});

test('un navigateur s\'abonne et se désabonne', function () {
    $browser = browserKeys();
    $payload = ['endpoint' => 'https://push.example.test/sub-1', 'keys' => ['p256dh' => WebPush::b64($browser['public']), 'auth' => WebPush::b64($browser['auth'])], 'device' => 'Chrome sur Android'];

    $this->getJson(route('push.key'))->assertOk()->assertJsonPath('supported', true);
    $this->postJson(route('push.subscribe'), $payload)->assertOk();
    $this->postJson(route('push.subscribe'), $payload)->assertOk();          // idempotent

    expect(PushSubscription::count())->toBe(1)
        ->and(PushSubscription::first()->device)->toBe('Chrome sur Android');

    $this->postJson(route('push.subscribe'), [...$payload, 'keys' => ['p256dh' => 'abc', 'auth' => 'def']])->assertStatus(422);

    $this->postJson(route('push.unsubscribe'), ['endpoint' => 'https://push.example.test/sub-1'])->assertOk();
    expect(PushSubscription::count())->toBe(0);
});

/* ================================================================ Tâche planifiée (19.4) */

test('un rappel dû part une seule fois, sur tous les appareils de la personne', function () {
    Http::fake(['push.example.test/*' => Http::response('', 201)]);
    subscribe($this->pierre, 'https://push.example.test/pierre-phone');
    subscribe($this->pierre, 'https://push.example.test/pierre-pc');
    // Sans le rendez-vous du soir (lot 37), le rappel du soir part seul, à son heure.
    $this->pierre->setPreference('notify.evening', false);

    $recipe = recipeWith('Pois chiches rôtis', 2, [[200, 'g', 'Pois chiches secs', false]]);
    $recipe->steps()->create(['position' => 1, 'instruction' => 'La veille, mettre les pois chiches à tremper.']);
    app(WeekPlanner::class)->addRecipe('2026-10-16', $this->slot, $recipe, 2);    // demain → rappel ce soir 18 h

    $first = $this->dispatcher->run();
    $second = $this->dispatcher->run();

    expect($first['sent'])->toBe(1)
        ->and($second['sent'])->toBe(0);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->hasHeader('Content-Encoding', 'aes128gcm')
        && str_starts_with($request->header('Authorization')[0], 'vapid t=')
        && $request->header('TTL')[0] === (string) WebPush::TTL);
});

test('chacun choisit ce qu\'il reçoit', function () {
    Http::fake(['*' => Http::response('', 201)]);
    subscribe($this->pierre);
    $this->pierre->setPreference('notify.prep', false);

    $recipe = recipeWith('Pois chiches rôtis', 2, [[200, 'g', 'Pois chiches secs', false]]);
    $recipe->steps()->create(['position' => 1, 'instruction' => 'La veille, mettre à tremper.']);
    app(WeekPlanner::class)->addRecipe('2026-10-16', $this->slot, $recipe, 2);

    expect($this->dispatcher->run()['sent'])->toBe(0);
    Http::assertNothingSent();
});

test('rien ne part la nuit', function () {
    Http::fake();
    subscribe($this->pierre);
    $this->travelTo(Carbon::parse('2026-10-15 23:10'));

    $recipe = recipeWith('Pois chiches rôtis', 2, [[200, 'g', 'Pois chiches secs', false]]);
    $recipe->steps()->create(['position' => 1, 'instruction' => 'La veille, mettre à tremper.']);
    app(WeekPlanner::class)->addRecipe('2026-10-16', $this->slot, $recipe, 2);

    $result = $this->dispatcher->run();

    expect($result['quiet'])->toBeTrue()->and($result['sent'])->toBe(0);
    Http::assertNothingSent();
});

test('un abonnement expiré (410) est oublié', function () {
    Http::fake(['*' => Http::response('', 410)]);
    subscribe($this->pierre);

    expect($this->dispatcher->test($this->pierre))->toBe(['sent' => 0, 'failed' => 1])
        ->and(PushSubscription::count())->toBe(0);
});

test('les produits à consommer : un message par jour, après l\'heure réglée', function () {
    Http::fake(['*' => Http::response('', 201)]);
    subscribe($this->pierre);
    app(StockManager::class)->add(['ingredient_id' => \App\Models\Ingredient::firstWhere('name', 'Crème fraîche épaisse')->id, 'quantity' => 20, 'expires_on' => '2026-10-16']);

    $messages = $this->dispatcher->messagesFor($this->pierre, now());
    expect(collect($messages)->firstWhere('type', 'expiry')['title'])->toBe('1 produit à consommer d\'ici demain');

    $this->travelTo(Carbon::parse('2026-10-15 09:00'));
    expect(collect($this->dispatcher->messagesFor($this->pierre, now()))->firstWhere('type', 'expiry'))->toBeNull();
});

test('les envies et la liste prête sont proposées seulement si on les a demandées', function () {
    Wish::create(['user_id' => $this->monique->id, 'text' => 'Raclette']);
    $list = ShoppingList::create(['name' => 'Semaine 42', 'period_start' => '2026-10-12', 'period_end' => '2026-10-18', 'status' => 'active', 'created_by' => $this->monique->id]);
    $list->items()->create(['label' => 'Pain', 'origin' => 'manual']);

    expect(collect($this->dispatcher->messagesFor($this->pierre, now()))->pluck('type')->all())->not->toContain('wishes');

    $this->pierre->setPreference('notify.wishes', true);
    $this->pierre->setPreference('notify.list', true);
    $messages = collect($this->dispatcher->messagesFor($this->pierre->fresh(), now()));

    expect($messages->firstWhere('type', 'wishes')['title'])->toBe('Monique a une envie')
        ->and($messages->firstWhere('type', 'list')['title'])->toBe('Monique a préparé la liste de courses');
});

test('la commande note son passage et sait créer la tâche Windows', function () {
    $this->artisan('bouffe:reminders')->assertSuccessful();

    expect(Settings::get('notifications.last_run')['at'])->toStartWith('2026-10-15T18:30');

    $this->artisan('bouffe:reminders', ['--tache-windows' => true])
        ->expectsOutputToContain('schtasks /Create /TN "Bouffe - rappels"')
        ->assertSuccessful();
});

/* ================================================================ Récapitulatif (19.3) */

test('le récapitulatif part le jour réglé, une fois, à ceux qui l\'ont demandé', function () {
    Mail::fake();
    Settings::set('notifications.recap_enabled', true);
    Settings::set('notifications.recap_day', 0);
    Settings::set('notifications.recap_hour', 18);
    $this->monique->setPreference('notify.recap', true);

    $this->travelTo(Carbon::parse('2026-10-18 18:05'));     // dimanche
    $this->dispatcher->run();
    $this->dispatcher->run();

    Mail::assertSent(WeeklyDigest::class, 1);
    Mail::assertSent(WeeklyDigest::class, fn ($mail) => $mail->hasTo('monique@example.test')
        && $mail->weekStart->toDateString() === '2026-10-19');
});

test('le récapitulatif contient le menu et la liste de la semaine', function () {
    $recipe = recipeWith('Gratin dauphinois', 4, [[1, 'kg', 'Pomme de terre', false]]);
    app(WeekPlanner::class)->addRecipe('2026-10-20', $this->slot, $recipe, 4);
    $list = ShoppingList::create(['name' => 'Semaine 43', 'period_start' => '2026-10-19', 'period_end' => '2026-10-25', 'status' => 'active']);
    $list->items()->create(['label' => 'Pain de campagne', 'origin' => 'manual']);

    $html = (new WeeklyDigest($this->pierre, Carbon::parse('2026-10-19')))->render();

    expect($html)->toContain('La semaine du 19 octobre')
        ->toContain('Gratin dauphinois')
        ->toContain('Pain de campagne');
});

/* ================================================================ Écrans */

test('l\'écran Notifications enregistre les choix de chacun', function () {
    Livewire::test(NotificationsPage::class)
        ->assertSet('types.prep', true)
        ->assertSet('types.wishes', false)
        ->set('types.wishes', true)
        ->set('recap', true);

    expect(NotificationDispatcher::wants($this->pierre->fresh(), 'wishes'))->toBeTrue()
        ->and(NotificationDispatcher::wantsRecap($this->pierre->fresh()))->toBeTrue();

    Livewire::test(NotificationsPage::class)
        ->set('recapEnabled', true)
        ->set('recapDay', 5)
        ->call('saveShared');

    expect(Settings::int('notifications.recap_day'))->toBe(5);
});

test('la cloche signale la liste préparée par l\'autre et les restes sans repas prévu', function () {
    $list = ShoppingList::create(['name' => 'Semaine 42', 'period_start' => '2026-10-12', 'period_end' => '2026-10-18', 'status' => 'active', 'created_by' => $this->monique->id]);
    $list->items()->create(['label' => 'Pain', 'origin' => 'manual']);

    $recipe = recipeWith('Chili', 4, [[500, 'g', 'Bœuf haché', false]]);
    $meal = app(WeekPlanner::class)->addRecipe('2026-10-14', $this->slot, $recipe, 4);
    app(WeekPlanner::class)->toggleCooked($meal);
    app(\App\Services\Stock\MealStockService::class)->storeLeftovers($meal->fresh(), 2);

    Livewire::test(Bell::class)
        ->call('show')
        ->assertSee('a préparé la liste')
        ->assertSee('Restes à planifier')
        ->assertSee('Restes de chili');
});
