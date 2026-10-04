<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('la commande crée un compte', function () {
    $this->artisan('bouffe:user', [
        'email' => 'pierre@exemple.lu',
        '--name' => 'Pierre',
        '--password' => 'motdepasse-solide',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $user = User::firstWhere('email', 'pierre@exemple.lu');

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Pierre')
        ->and(Hash::check('motdepasse-solide', $user->password))->toBeTrue();
});

test('la commande met à jour le mot de passe sans changer le nom', function () {
    $user = User::factory()->create(['email' => 'pierre@exemple.lu', 'name' => 'Pierre']);

    $this->artisan('bouffe:user', [
        'email' => 'pierre@exemple.lu',
        '--password' => 'nouveau-mot-de-passe',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $user->refresh();

    expect(User::count())->toBe(1)
        ->and($user->name)->toBe('Pierre')
        ->and(Hash::check('nouveau-mot-de-passe', $user->password))->toBeTrue();
});

test('la commande refuse un mot de passe trop court', function () {
    $this->artisan('bouffe:user', [
        'email' => 'pierre@exemple.lu',
        '--name' => 'Pierre',
        '--password' => 'court',
        '--no-interaction' => true,
    ])->assertFailed();

    expect(User::count())->toBe(0);
});
