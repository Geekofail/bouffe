<?php

use App\Enums\UserRole;
use App\Livewire\Account\Privacy;
use App\Livewire\Account\Show as Account;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Auth\TwoFactorChallenge;
use App\Livewire\Settings\Online;
use App\Mail\Notice;
use App\Models\Household;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Backup\BackupManager;
use App\Services\Backup\SqlDialect;
use App\Services\Households\HouseholdManager;
use App\Services\Security\AccountDeletion;
use App\Services\Security\LoginJournal;
use App\Services\Security\TwoFactor;
use App\Services\System\ErrorAlert;
use App\Services\System\TaskRunner;
use App\Services\System\TaskToken;
use App\Support\Settings;
use App\Support\Totp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * En ligne chez OVH (lot 25 — module 27 du document 07) : double authentification, sessions et
 * journal, mot de passe oublié, en-têtes, tâches planifiées, surveillance, sauvegardes, vos données.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-19 10:00:00'));   // un lundi
    $this->pierre = User::factory()->create(['name' => 'Pierre', 'email' => 'pierre@example.lu', 'password' => 'motdepasse']);   // administrateur, responsable
    $this->twoFactor = app(TwoFactor::class);
});

/** Active la double authentification et rend les codes de secours. */
function enableTwoFactor(User $user): array
{
    $secret = app(TwoFactor::class)->begin($user);
    Cache::forget("bouffe.2fa.step.{$user->id}");   // code de la même période qu'une activation précédente

    return app(TwoFactor::class)->confirm($user, Totp::code($secret, Totp::step()));
}

/* ================================================================ 27.4 — double authentification */

test('TOTP : les valeurs de la RFC 6238 et le secret en base 32', function () {
    $secret = Totp::base32Encode('12345678901234567890');

    expect($secret)->toBe('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ')
        ->and(Totp::base32Decode($secret))->toBe('12345678901234567890')
        ->and(Totp::code($secret, Totp::step(59)))->toBe('287082')
        ->and(Totp::code($secret, Totp::step(1111111109)))->toBe('081804')
        ->and(Totp::code($secret, Totp::step(2000000000)))->toBe('279037')
        ->and(Totp::verify($secret, '081 804', 1111111109 + 30))->toBe(Totp::step(1111111109))   // horloge décalée de 30 s
        ->and(Totp::verify($secret, '081804', 1111111109 + 90))->toBeNull()
        ->and(Totp::uri($secret, 'pierre@example.lu'))->toStartWith('otpauth://totp/Bouffe:pierre%40example.lu?secret=GEZD');
});

test('activation : le secret est chiffré, confirmé par un code, et donne 8 codes de secours', function () {
    Livewire::actingAs($this->pierre)->test(Account::class)
        ->call('startTwoFactor')
        ->assertSee('Recopiez ci-dessous le code')
        ->set('setupCode', '000000')
        ->call('confirmTwoFactor')
        ->assertHasErrors('setupCode');

    $user = $this->pierre->fresh();
    expect($user->hasTwoFactor())->toBeFalse()
        ->and(DB::table('users')->where('id', $user->id)->value('two_factor_secret'))->not->toBe($user->two_factor_secret);   // chiffré en base

    $component = Livewire::actingAs($user)->test(Account::class)
        ->set('setupCode', Totp::code($user->two_factor_secret, Totp::step()))
        ->call('confirmTwoFactor')
        ->assertHasNoErrors();

    expect($component->get('recoveryCodes'))->toHaveCount(8)
        ->and($user->fresh()->hasTwoFactor())->toBeTrue()
        ->and($user->fresh()->two_factor_recovery_codes)->not->toContain($component->get('recoveryCodes')[0])   // seulement les condensés
        ->and(LoginEvent::where('type', 'two_factor_enabled')->count())->toBe(1);
});

test('connexion : mot de passe puis code ; un code faux est noté, un code ne sert pas deux fois', function () {
    enableTwoFactor($this->pierre);
    $this->travel(2)->minutes();

    Livewire::test(Login::class)
        ->set('email', 'pierre@example.lu')->set('password', 'motdepasse')
        ->call('login')
        ->assertRedirect(route('two-factor.challenge'));

    $this->assertGuest();

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', '123456')->call('verify')
        ->assertHasErrors('code');

    expect(LoginEvent::where('type', 'two_factor_failed')->count())->toBe(1);

    $code = Totp::code($this->pierre->fresh()->two_factor_secret, Totp::step());

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', $code)->call('verify')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($this->pierre);
    expect(LoginEvent::where('type', 'login')->count())->toBe(1);

    // Même code, nouvelle connexion : refusé (rejeu).
    auth()->logout();
    session()->put('login.two_factor', ['id' => $this->pierre->id, 'remember' => false, 'at' => now()->getTimestamp()]);

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', $code)->call('verify')
        ->assertHasErrors('code');
});

test('sans étape en attente, la page du code renvoie à la connexion', function () {
    Livewire::test(TwoFactorChallenge::class)->assertRedirect(route('login'));
});

test('code de secours : accepté une fois, puis rayé', function () {
    $codes = enableTwoFactor($this->pierre);

    session()->put('login.two_factor', ['id' => $this->pierre->id, 'remember' => false, 'at' => now()->getTimestamp()]);

    Livewire::test(TwoFactorChallenge::class)
        ->call('toggleRecovery')
        ->set('code', strtoupper($codes[0]))->call('verify')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($this->pierre);
    expect($this->twoFactor->remainingRecoveryCodes($this->pierre->fresh()))->toBe(7)
        ->and(LoginEvent::where('type', 'recovery_code')->count())->toBe(1)
        ->and($this->twoFactor->useRecoveryCode($this->pierre->fresh(), $codes[0]))->toBeFalse();
});

test('appareil de confiance : le cookie signé évite le code, et ne vaut plus après réactivation', function () {
    enableTwoFactor($this->pierre);
    $user = $this->pierre->fresh();

    $this->twoFactor->trustDevice($user);
    $cookie = collect(\Illuminate\Support\Facades\Cookie::getQueuedCookies())->firstWhere(fn ($c) => $c->getName() === TwoFactor::TRUST_COOKIE);
    $request = \Illuminate\Http\Request::create('/', cookies: [TwoFactor::TRUST_COOKIE => $cookie->getValue()]);

    expect($this->twoFactor->isTrusted($user, $request))->toBeTrue();

    enableTwoFactor($user);   // nouveau secret
    expect($this->twoFactor->isTrusted($user->fresh(), $request))->toBeFalse()
        ->and($this->twoFactor->isTrusted($user, \Illuminate\Http\Request::create('/', cookies: [TwoFactor::TRUST_COOKIE => $user->id.'|'.(time() + 999).'|faux'])))->toBeFalse();
});

test('obligatoire (Q41) : l\'administrateur et les responsables ne voient que « Mon compte » tant qu\'elle n\'est pas activée', function () {
    config(['bouffe.security.two_factor_required' => true]);
    $viewer = User::factory()->create(['role' => 'viewer']);

    $this->actingAs($this->pierre)->get(route('recipes.index'))->assertRedirect(route('account.show'));
    $this->actingAs($this->pierre)->get(route('account.show'))->assertOk()->assertSee('Double authentification à activer');
    $this->actingAs($viewer)->get(route('recipes.index'))->assertOk();   // facultative pour les autres

    expect($this->twoFactor->required($this->pierre))->toBeTrue()
        ->and($this->twoFactor->required($viewer))->toBeFalse();

    enableTwoFactor($this->pierre);
    $this->actingAs($this->pierre->fresh())->get(route('recipes.index'))->assertOk();

    // Obligatoire : pas de bouton « Désactiver ».
    Livewire::actingAs($this->pierre->fresh())->test(Account::class)
        ->assertDontSee('wire:click="disableTwoFactor"', false)
        ->set('confirmPassword', 'motdepasse')->call('disableTwoFactor')
        ->assertForbidden();
});

test('facultative : désactivation avec le mot de passe ; bouffe:user --sans-2fa en dernier recours', function () {
    config(['bouffe.security.two_factor_required' => false]);
    enableTwoFactor($this->pierre);

    Livewire::actingAs($this->pierre->fresh())->test(Account::class)
        ->set('confirmPassword', 'mauvais')->call('disableTwoFactor')->assertHasErrors('confirmPassword')
        ->set('confirmPassword', 'motdepasse')->call('disableTwoFactor')->assertHasNoErrors();

    expect($this->pierre->fresh()->hasTwoFactor())->toBeFalse();

    enableTwoFactor($this->pierre->fresh());
    $this->artisan('bouffe:user', ['email' => 'pierre@example.lu', '--sans-2fa' => true])->assertSuccessful();
    expect($this->pierre->fresh()->hasTwoFactor())->toBeFalse();
});

/* ================================================================ 27.5 — journal, alertes, appareils */

test('journal : échecs notés, blocage noté une fois', function () {
    foreach (range(1, Login::MAX_ATTEMPTS + 2) as $i) {
        Livewire::test(Login::class)->set('email', 'pierre@example.lu')->set('password', 'mauvais')->call('login');
    }

    expect(LoginEvent::where('type', 'failed')->count())->toBe(Login::MAX_ATTEMPTS)
        ->and(LoginEvent::where('type', 'locked')->count())->toBe(1);

    Livewire::actingAs($this->pierre)->test(Account::class)
        ->assertSee('Mot de passe refusé')
        ->assertSee('Trop d');
});

test('alerte « nouvelle connexion » : seulement pour un navigateur jamais vu sur le compte', function () {
    Mail::fake();
    $journal = app(LoginJournal::class);
    $request = fn (string $agent) => \Illuminate\Http\Request::create('/', server: ['HTTP_USER_AGENT' => $agent, 'REMOTE_ADDR' => '192.0.2.7']);
    $chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
    $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    $journal->loggedIn($this->pierre, $request($chrome));   // première connexion : pas d'alerte
    $journal->loggedIn($this->pierre, $request($chrome));   // même navigateur
    Mail::assertNothingSent();

    $journal->loggedIn($this->pierre, $request($iphone));
    Mail::assertSent(Notice::class, fn (Notice $mail) => $mail->hasTo('pierre@example.lu') && str_contains(implode(' ', $mail->paragraphs), 'Safari sur iPhone — adresse 192.0.2.7'));

    config(['bouffe.security.new_device_email' => false]);
    $journal->loggedIn($this->pierre, $request('Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0'));
    Mail::assertSent(Notice::class, 1);

    expect(LoginEvent::where('type', 'login')->count())->toBe(4)
        ->and(LoginEvent::latest('id')->first()->deviceLabel())->toBe('Firefox sur Linux');
});

test('le journal est vidé après la durée de conservation', function () {
    app(LoginJournal::class)->record($this->pierre, 'login');
    $this->travel(181)->days();
    app(LoginJournal::class)->record($this->pierre, 'login');

    expect(app(LoginJournal::class)->purge())->toBe(1)
        ->and(LoginEvent::count())->toBe(1);
});

test('appareils connectés : liste des sessions et déconnexion des autres', function () {
    config(['session.driver' => 'database']);
    $this->actingAs($this->pierre);
    $current = session()->getId();

    DB::table('sessions')->insert([
        ['id' => $current, 'user_id' => $this->pierre->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0', 'payload' => '', 'last_activity' => time()],
        ['id' => 'autre-telephone', 'user_id' => $this->pierre->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'Mozilla/5.0 (Linux; Android 15) Chrome/130.0 Mobile', 'payload' => '', 'last_activity' => time() - 3600],
        ['id' => 'quelquun-dautre', 'user_id' => User::factory()->create()->id, 'ip_address' => '10.0.0.3', 'user_agent' => null, 'payload' => '', 'last_activity' => time()],
    ]);
    $token = $this->pierre->fresh()->remember_token;

    $sessions = app(LoginJournal::class)->sessions($this->pierre);
    expect($sessions)->toHaveCount(2)
        ->and($sessions[0]['current'])->toBeTrue()
        ->and($sessions[1]['label'])->toBe('Chrome sur Android');

    Livewire::test(Account::class)->assertSee('Chrome sur Android')->call('revokeSession', 'autre-telephone');

    expect(DB::table('sessions')->where('id', 'autre-telephone')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'quelquun-dautre')->exists())->toBeTrue()
        ->and($this->pierre->fresh()->remember_token)->not->toBe($token)
        ->and(LoginEvent::where('type', 'revoked')->count())->toBe(1);
});

test('changer de mot de passe demande l\'actuel et le note au journal', function () {
    Livewire::actingAs($this->pierre)->test(Account::class)
        ->set('currentPassword', 'mauvais')->set('newPassword', 'nouveau-mdp')->set('newPassword_confirmation', 'nouveau-mdp')
        ->call('changePassword')->assertHasErrors('currentPassword')
        ->set('currentPassword', 'motdepasse')
        ->call('changePassword')->assertHasNoErrors();

    expect(\Illuminate\Support\Facades\Hash::check('nouveau-mdp', $this->pierre->fresh()->password))->toBeTrue()
        ->and(LoginEvent::where('type', 'password_changed')->count())->toBe(1);
});

test('changer d\'adresse e-mail demande le mot de passe', function () {
    Livewire::actingAs($this->pierre)->test(Account::class)
        ->set('email', 'pierre@autre.lu')->call('saveProfile')->assertHasErrors('profilePassword')
        ->set('profilePassword', 'motdepasse')->call('saveProfile')->assertHasNoErrors();

    expect($this->pierre->fresh()->email)->toBe('pierre@autre.lu');
});

/* ================================================================ 27.6 — mot de passe oublié */

test('mot de passe oublié : même réponse avec ou sans compte, lien par e-mail, trois demandes au plus', function () {
    Mail::fake();

    Livewire::test(ForgotPassword::class)->set('email', 'inconnu@example.lu')->call('send')->assertSet('sent', true)->assertSee('Si un compte existe');
    Mail::assertNothingSent();

    Livewire::test(ForgotPassword::class)->set('email', 'Pierre@Example.lu')->call('send')->assertSet('sent', true);
    Mail::assertSent(Notice::class, fn (Notice $mail) => $mail->hasTo('pierre@example.lu') && str_contains($mail->action[1], '/reinitialiser-mot-de-passe/'));

    Livewire::test(ForgotPassword::class)->set('email', 'x@example.lu')->call('send');
    Livewire::test(ForgotPassword::class)->set('email', 'y@example.lu')->call('send')->assertHasErrors('email');
});

test('réinitialisation : nouveau mot de passe, sessions fermées, jeton à usage unique', function () {
    config(['session.driver' => 'database']);
    DB::table('sessions')->insert(['id' => 'ancienne', 'user_id' => $this->pierre->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => time()]);
    $token = Password::createToken($this->pierre);

    $this->get(route('password.reset', ['token' => $token, 'email' => 'pierre@example.lu']))->assertOk()->assertSee('Nouveau mot de passe');

    Livewire::test(ResetPassword::class, ['token' => $token])
        ->set('email', 'pierre@example.lu')->set('password', 'tout-neuf-1')->set('password_confirmation', 'tout-neuf-1')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('login'));

    expect(\Illuminate\Support\Facades\Hash::check('tout-neuf-1', $this->pierre->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $this->pierre->id)->exists())->toBeFalse()
        ->and(LoginEvent::where('type', 'password_reset')->count())->toBe(1);

    Livewire::test(ResetPassword::class, ['token' => $token])
        ->set('email', 'pierre@example.lu')->set('password', 'encore-autre')->set('password_confirmation', 'encore-autre')
        ->call('save')
        ->assertHasErrors('email')
        ->assertSee('plus valable');
});

test('l\'e-mail de réinitialisation est en français, en HTML et en texte', function () {
    $mail = Notice::passwordReset($this->pierre, 'jeton123');

    $mail->assertSeeInHtml('Choisir un nouveau mot de passe')
        ->assertSeeInText("n'êtes pas à l'origine")
        ->assertSeeInHtml('/reinitialiser-mot-de-passe/jeton123');
    expect($mail->envelope()->subject)->toBe('Bouffe — réinitialiser votre mot de passe');
});

/* ================================================================ 27.7 — en-têtes */

test('en-têtes : politique de contenu avec nonce, présent sur les scripts de la page', function () {
    config(['bouffe.security.csp' => 'enforce']);

    $response = $this->get('/connexion')->assertOk();
    $csp = $response->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([^']+)'/", (string) $csp, $m);

    expect($csp)->toContain("default-src 'self'")->toContain("frame-ancestors 'self'")->toContain("object-src 'none'")
        ->and($m[1] ?? null)->not->toBeNull()
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Permissions-Policy'))->toContain('camera=(self)')
        ->and($response->headers->has('Strict-Transport-Security'))->toBeFalse();   // http:// : pas de HSTS

    // Chaque <script> de la page porte le nonce.
    preg_match_all('/<script\b[^>]*>/', $response->getContent(), $scripts);
    expect($scripts[0])->not->toBeEmpty();
    foreach ($scripts[0] as $tag) {
        expect($tag)->toContain('nonce="'.$m[1].'"');
    }

    $this->get('https://bouffe.test/connexion')->assertHeader('Strict-Transport-Security', 'max-age=31536000');

    config(['bouffe.security.csp' => 'report']);
    expect($this->get('/connexion')->headers->has('Content-Security-Policy-Report-Only'))->toBeTrue();
});

test('aucune vue n\'utilise d\'attribut onclick ni de <script> sans nonce', function () {
    foreach (File::allFiles(resource_path('views')) as $file) {
        $content = $file->getContents();

        expect(preg_match('/\son(click|change|submit|load|input|error)=/', $content))->toBe(0, $file->getRelativePathname().' : attribut on…=')
            ->and(preg_match('/<script(?![^>]*@nonce)(?![^>]*\ssrc=)[^>]*>/', $content))->toBe(0, $file->getRelativePathname().' : <script> sans @nonce');
    }
});

/* ================================================================ 27.3 — tâches planifiées */

test('adresse des tâches : jeton faux → 404, bon jeton → passage noté « service externe »', function () {
    $token = app(TaskToken::class)->ensure();

    $this->get('/taches/'.str_repeat('x', 40))->assertNotFound();
    expect(Settings::get('tasks.last_run'))->toBeNull();

    $this->getJson('/taches/'.$token)->assertOk()->assertJson(['ok' => true, 'erreurs' => 0])
        ->assertCookieMissing(config('session.cookie'));   // sans session

    expect(Settings::get('tasks.last_run')['source'])->toBe('externe')
        ->and(Settings::get('notifications.last_run')['at'])->toStartWith('2026-10-19T10:00')
        ->and(DB::table('settings')->where('key', 'tasks.token')->value('value'))->not->toContain($token);   // chiffré

    // Nouveau jeton : l'ancienne adresse ne marche plus.
    Livewire::actingAs($this->pierre)->test(Online::class)->call('regenerateToken')->assertSee('/taches/');
    $this->get('/taches/'.$token)->assertNotFound();
});

test('cron.php et bouffe:tasks : même travail, origine notée, sauvegarde du jour quand elle manque', function () {
    $dir = storage_path('framework/testing/backups-'.uniqid());
    config(['bouffe.backups.path' => $dir, 'bouffe.backups.auto_days' => 1]);
    Storage::fake('local');

    $this->artisan('bouffe:tasks', ['--source' => 'cron'])->assertSuccessful();

    $last = app(TaskRunner::class)->lastRun();
    expect($last['source'])->toBe('tâche planifiée OVH')
        ->and(app(BackupManager::class)->all())->toHaveCount(1);

    $this->travel(3)->hours();
    $this->artisan('bouffe:tasks')->assertSuccessful();
    expect(app(BackupManager::class)->all())->toHaveCount(1);   // une par jour

    File::deleteDirectory($dir);
    expect(file_get_contents(base_path('cron.php')))->toContain("'command' => 'bouffe:tasks'");
});

test('un passage en cours n\'est pas doublé ; une étape en échec n\'arrête pas les autres', function () {
    $lock = Cache::lock('bouffe.tasks', 60);
    $lock->get();
    expect(app(TaskRunner::class)->run('externe')['ran'])->toBeFalse();
    $lock->release();

    config(['bouffe.backups.path' => '/dev/null/impossible', 'bouffe.backups.auto_days' => 1]);
    $result = app(TaskRunner::class)->run('externe');

    expect($result['ran'])->toBeTrue()
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0])->toStartWith('Sauvegarde')
        ->and(Settings::get('notifications.last_run'))->not->toBeNull();   // les rappels sont passés quand même
});

/* ================================================================ 27.9 — sauvegardes en ligne */

test('e-mail de sauvegarde : le jour choisi, une fois par semaine, aux administrateurs', function () {
    Mail::fake();
    $dir = storage_path('framework/testing/backups-'.uniqid());
    config(['bouffe.backups.path' => $dir, 'bouffe.backups.weekly_email' => true, 'bouffe.backups.weekly_email_day' => 1]);
    Storage::fake('local');
    app(BackupManager::class)->create('auto');
    $runner = app(TaskRunner::class);

    expect($runner->weeklyBackupEmail(now()))->toBeTrue()
        ->and($runner->weeklyBackupEmail(now()->addHour()))->toBeFalse()           // déjà envoyé
        ->and($runner->weeklyBackupEmail(now()->addDays(2)))->toBeFalse()          // pas le bon jour
        ->and($runner->weeklyBackupEmail(now()->addDays(7)))->toBeTrue();          // la semaine suivante

    Mail::assertSent(Notice::class, 2);
    Mail::assertSent(Notice::class, fn (Notice $mail) => $mail->hasTo('pierre@example.lu') && str_contains($mail->action[1], '/parametres/sauvegardes/bouffe-'));

    File::deleteDirectory($dir);
});

/* ================================================================ 27.8 — MariaDB → MySQL */

test('structure MariaDB adaptée à MySQL : uuid, current_timestamp(), contrôles JSON, interclassements', function () {
    $mariadb = "CREATE TABLE `receipts` (\n  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `uuid` uuid NOT NULL,\n"
        ."  `photo_paths` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`photo_paths`)),\n"
        ."  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),\n"
        ."  PRIMARY KEY (`id`),\n  CONSTRAINT `outcome` CHECK (json_valid(`outcome`))\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci";

    $mysql = (new SqlDialect(DB::connection(), mariadb: false, collations: ['utf8mb4_unicode_ci', 'utf8mb4_bin', 'utf8mb4_0900_ai_ci']))->adapt($mariadb);

    expect($mysql)->toContain('`uuid` char(36) NOT NULL')
        ->toContain('DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP')
        ->toContain('COLLATE utf8mb4_bin DEFAULT NULL,')
        ->toContain('COLLATE=utf8mb4_unicode_ci')
        ->not->toContain('json_valid')
        ->not->toContain('CONSTRAINT `outcome`');

    // Dans l'autre sens : commentaire MySQL 8 retiré, interclassement 0900 inconnu de MariaDB 10.
    $fromMysql = "CREATE TABLE `tags` (\n  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci /*!80016 DEFAULT ENCRYPTION='N' */";
    $maria = (new SqlDialect(DB::connection(), mariadb: true, collations: ['utf8mb4_unicode_ci']))->adapt($fromMysql);

    expect($maria)->toEndWith('COLLATE=utf8mb4_unicode_ci')->not->toContain('80016')
        ->and((new SqlDialect(DB::connection(), mariadb: true, collations: []))->adapt('INSERT INTO `x` VALUES (\'current_timestamp()\')'))->toContain('current_timestamp()');   // données intactes
});

/* ================================================================ 27.11 — surveillance */

test('/sante : 200 quand tout va bien, 503 quand les tâches ne passent plus, rien de privé', function () {
    $this->getJson('/sante')->assertOk()
        ->assertJson(['status' => 'ok', 'checks' => ['base' => ['status' => 'ok'], 'stockage' => ['status' => 'ok'], 'taches' => ['detail' => 'jamais lancées']]])
        ->assertCookieMissing(config('session.cookie'));

    app(TaskRunner::class)->run('externe');
    $this->travel(91)->minutes();

    $this->getJson('/sante')->assertStatus(503)->assertJsonPath('checks.taches.status', 'erreur');
});

test('erreur grave : un e-mail à l\'administrateur, pas plus d\'un par heure', function () {
    Mail::fake();
    config(['bouffe.security.error_email' => true]);
    Cache::forget(ErrorAlert::THROTTLE_KEY);

    app(ErrorAlert::class)->report(new RuntimeException('La base a disparu'));
    app(ErrorAlert::class)->report(new RuntimeException('Encore'));

    Mail::assertSent(Notice::class, 1);
    Mail::assertSent(Notice::class, fn (Notice $mail) => $mail->hasTo('pierre@example.lu') && str_contains(implode(' ', $mail->paragraphs), 'La base a disparu'));

    config(['bouffe.security.error_email' => false]);
    Cache::forget(ErrorAlert::THROTTLE_KEY);
    app(ErrorAlert::class)->report(new RuntimeException('Silence'));
    Mail::assertSent(Notice::class, 1);
});

test('bouffe:deploy passe en maintenance puis rouvre le site', function () {
    $this->artisan('bouffe:deploy', ['--sans-sauvegarde' => true])->expectsOutputToContain('Site rouvert');

    expect(app()->isDownForMaintenance())->toBeFalse();
});

/* ================================================================ 27.12 — vos données, compte */

test('« Vos données » se lit sans compte et dit ce que fait vraiment l\'installation', function () {
    Settings::set('receipts.provider', 'none');

    $this->get(route('privacy'))->assertOk()
        ->assertSee('Ce qui est enregistré')
        ->assertSee('Désactivée sur cette installation')
        ->assertSee('Aucun serveur d\'envoi', false)
        ->assertDontSee('Pierre');   // pas de prénom pour un visiteur

    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'ssl0.ovh.net']);
    Settings::set('receipts.provider', 'mistral');
    Settings::set('receipts.mistral_key', 'cle-secrete');

    Livewire::actingAs($this->pierre)->test(Privacy::class)
        ->assertSee('Mistral')->assertSee('ssl0.ovh.net')->assertSee('Pierre')
        ->assertDontSee('cle-secrete');
});

test('supprimer son compte : bloqué si seul dans un foyer ou dernier responsable, sinon données personnelles effacées', function () {
    $manager = app(HouseholdManager::class);
    $home = Household::query()->orderBy('id')->first();
    $leo = User::factory()->create(['name' => 'Léo', 'password' => 'motdepasse']);   // responsable du foyer n° 1 avec Pierre
    $solo = $manager->create('Chez Léo seul', $leo);
    $manager->attach($solo, $leo, UserRole::Owner);

    $deletion = app(AccountDeletion::class);
    expect($deletion->blockers($leo))->toHaveCount(1)
        ->and($deletion->blockers($leo)[0])->toContain('seul dans « Chez Léo seul »')
        ->and($deletion->blockers($this->pierre)[0])->toContain('administrez');

    $manager->requestDeletion($solo);
    app(LoginJournal::class)->record($leo, 'login');

    Livewire::actingAs($leo)->test(Account::class)
        ->set('deletePassword', 'motdepasse')->call('deleteAccount')->assertHasErrors('confirmDeletion')
        ->set('confirmDeletion', true)->call('deleteAccount')
        ->assertRedirect(route('login'));

    expect(User::find($leo->id))->toBeNull()
        ->and(LoginEvent::where('user_id', $leo->id)->count())->toBe(0)
        ->and(DB::table('household_user')->where('household_id', $home->id)->where('user_id', $this->pierre->id)->exists())->toBeTrue();
    $this->assertGuest();
});

test('pages publiques et privées : Mon compte, Mise en ligne (administrateur seulement)', function () {
    $monique = User::factory()->create(['name' => 'Monique']);

    $this->get(route('account.show'))->assertRedirect(route('login'));
    $this->actingAs($monique)->get(route('account.show'))->assertOk()->assertSee('Appareils connectés');
    $this->actingAs($monique)->get(route('settings.online'))->assertForbidden();
    $this->actingAs($this->pierre)->get(route('settings.online'))->assertOk()
        ->assertSee('Tâches planifiées')->assertSee('/taches/••••••••')->assertDontSee(app(TaskToken::class)->ensure());
    $this->get(route('password.request'))->assertRedirect();   // connecté : pas de page « oublié »
});
