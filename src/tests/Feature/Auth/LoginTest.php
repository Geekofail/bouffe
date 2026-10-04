<?php

use App\Livewire\Auth\Login;
use App\Models\User;
use Livewire\Livewire;

test("l'écran de connexion s'affiche", function () {
    $this->get('/connexion')
        ->assertOk()
        ->assertSeeLivewire(Login::class)
        ->assertSee('Se connecter');
});

test('un utilisateur se connecte avec des identifiants valides', function () {
    $user = User::factory()->create(['password' => 'secret-bouffe']);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'secret-bouffe')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('la case "rester connecté" pose le jeton de mémorisation', function () {
    $user = User::factory()->create(['password' => 'secret-bouffe', 'remember_token' => null]);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'secret-bouffe')
        ->set('remember', true)
        ->call('login');

    expect($user->fresh()->remember_token)->not->toBeNull();
});

test('un mauvais mot de passe est refusé avec un message en français', function () {
    $user = User::factory()->create(['password' => 'secret-bouffe']);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'mauvais')
        ->call('login')
        ->assertHasErrors('email')
        ->assertSee('Ces identifiants ne correspondent pas');

    $this->assertGuest();
});

test('les champs sont obligatoires', function () {
    Livewire::test(Login::class)
        ->set('email', '')
        ->set('password', '')
        ->call('login')
        ->assertHasErrors(['email' => 'required', 'password' => 'required']);
});

test('la connexion est bloquée après trop de tentatives', function () {
    $user = User::factory()->create(['password' => 'secret-bouffe']);

    $component = Livewire::test(Login::class)->set('email', $user->email);

    foreach (range(1, Login::MAX_ATTEMPTS) as $attempt) {
        $component->set('password', 'mauvais')->call('login');
    }

    $component->set('password', 'secret-bouffe')
        ->call('login')
        ->assertHasErrors('email')
        ->assertSee('Tentatives de connexion trop nombreuses');

    $this->assertGuest();
});

test('un utilisateur connecté est redirigé depuis la page de connexion', function () {
    $this->actingAs(User::factory()->create())
        ->get('/connexion')
        ->assertRedirect(route('dashboard'));
});

test('un utilisateur se déconnecte', function () {
    $this->actingAs(User::factory()->create())
        ->post('/deconnexion')
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
