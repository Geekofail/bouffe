<?php

use App\Models\User;
use Illuminate\Support\Facades\Validator;

dataset('pages', [
    'accueil' => ['/', 'Bonjour'],
    'recettes' => ['/recettes', 'Recettes'],
    'planning' => ['/planning', 'Planning de la semaine'],
    'invités' => ['/invites', 'Invités'],
    'courses' => ['/courses', 'Listes de courses'],
    'paramètres' => ['/parametres', 'Paramètres'],
]);

test('un invité est redirigé vers la connexion', function (string $url) {
    $this->get($url)->assertRedirect(route('login'));
})->with('pages');

test('un utilisateur connecté accède à la page', function (string $url, string $text) {
    $this->travelTo(now()->setTime(10, 0));

    $this->actingAs(User::factory()->create(['name' => 'Pierre']))
        ->get($url)
        ->assertOk()
        ->assertSee($text)
        ->assertSee('Pierre')
        ->assertSee('Se déconnecter');
})->with('pages');

test("l'application est en français", function () {
    // Le validateur traduit à la fois le message (lang/fr/validation.php)
    // et le nom du champ (tableau "attributes" du même fichier).
    $errors = Validator::make(['email' => ''], ['email' => 'required'])->errors();

    expect(app()->getLocale())->toBe('fr')
        ->and($errors->first('email'))->toBe('Le champ adresse e-mail est obligatoire.');
});

test('une page inexistante affiche la page 404 en français', function () {
    $this->get('/page-qui-n-existe-pas')
        ->assertNotFound()
        ->assertSee('Page introuvable');
});
