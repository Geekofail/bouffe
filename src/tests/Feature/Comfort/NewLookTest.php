<?php

use App\Enums\Course;
use App\Livewire\Recipes\Index as RecipesIndex;
use App\Livewire\Settings\Display;
use App\Models\MealSlot;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use App\Support\DishIllustration;
use App\Support\NameNormalizer;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/*
 * Nouvelle apparence (lot 29) : illustrations des plats, affichage des recettes, taille du texte,
 * « + » au centre de la barre du bas, polices servies par Bouffe, pages vides qui proposent quoi faire.
 * Le rendu (couleurs, contrastes, mode sombre) est vérifié dans le navigateur : npm run test:browser.
 */

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Pierre']);
    $this->actingAs($this->user);
    MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 1]);
});

/* ================================================================ Illustrations (29.3) */

test('le dessin d\'un plat se choisit d\'après les mots du titre', function (string $title, string $kind) {
    expect(DishIllustration::kind(NameNormalizer::normalize($title)))->toBe($kind);
})->with([
    ['Velouté de potiron', 'soupe'],
    ['Salade grecque', 'salade'],
    ['Quiche lorraine', 'tarte'],
    ['Tarte aux pommes, pâte brisée', 'tarte'],       // « pâte brisée » : une tarte, pas des pâtes
    ['Spaghetti bolognaise', 'pates'],
    ['Curry de poulet au lait de coco', 'curry'],
    ['Crêpes de la Chandeleur', 'crepes'],
    ['Gratin dauphinois', 'gratin'],
    ['Gâteau au yaourt', 'gateau'],
    ['Poulet rôti', 'assiette'],
]);

test('sans mot parlant dans le titre, les catégories choisissent le dessin', function () {
    expect(DishIllustration::kind('poulet roti', ['dessert']))->toBe('gateau')
        ->and(DishIllustration::kind('poulet roti', ['soupe']))->toBe('soupe')
        ->and(DishIllustration::kind('poulet roti', ['vegetarien']))->toBe('assiette');
});

test('la teinte suit la place du plat dans le repas, sinon les catégories', function () {
    expect(DishIllustration::tone([], Course::Dessert))->toBe('dessert')
        ->and(DishIllustration::tone(['dessert'], Course::Starter))->toBe('entree')
        ->and(DishIllustration::tone([], Course::Main))->toBe('plat')
        ->and(DishIllustration::tone(['accompagnement']))->toBe('accompagnement')
        ->and(DishIllustration::tone(['salade']))->toBe('entree')
        ->and(DishIllustration::tone([]))->toBe('plat');
});

test('une recette sans photo montre son illustration, teintée par ses catégories', function () {
    $recipe = Recipe::factory()->create(['title' => 'Crème brûlée']);
    $recipe->tags()->attach(Tag::factory()->create(['name' => 'Dessert']));

    expect(DishIllustration::for($recipe->load('tags')))->toBe(['kind' => 'gateau', 'tone' => 'dessert']);

    $html = Blade::render('<x-dish-illustration :recipe="$recipe" class="size-12" />', ['recipe' => $recipe->load('tags')]);
    expect($html)->toContain('data-dish="gateau"')
        ->toContain('data-tone="dessert"')
        ->toContain('aria-hidden="true"')
        ->toContain('var(--color-course-dessert)');
});

test('une illustration demandée directement garde ses valeurs, et retombe sur l\'assiette si elles sont inconnues', function () {
    expect(Blade::render('<x-dish-illustration kind="soupe" tone="accompagnement" />'))
        ->toContain('data-dish="soupe"')->toContain('var(--color-course-side)');
    expect(Blade::render('<x-dish-illustration kind="inconnu" tone="bleu" />'))
        ->toContain('data-dish="assiette"')->toContain('data-tone="plat"');
});

/* ================================================================ Recettes : cartes ou liste (29.4) */

test('les recettes s\'affichent en cartes, ou en liste compacte gardée dans l\'adresse', function () {
    Recipe::factory()->create(['title' => 'Velouté de potiron']);

    Livewire::test(RecipesIndex::class)
        ->assertSet('layout', 'cartes')
        ->assertSee('data-dish="soupe"', false)
        ->assertDontSee('recipe-row-', false)
        ->set('layout', 'liste')
        ->assertSee('Velouté de potiron')
        ->assertSee('aria-pressed="true" title="En liste"', false);

    Livewire::withQueryParams(['affichage' => 'liste'])->test(RecipesIndex::class)->assertSet('layout', 'liste');
});

test('l\'image d\'une carte est un doublon du titre : elle est cachée aux lecteurs d\'écran', function () {
    Recipe::factory()->create(['title' => 'Quiche lorraine']);

    Livewire::test(RecipesIndex::class)
        ->assertSee('class="block" tabindex="-1" aria-hidden="true"', false)
        ->assertSee('Quiche lorraine');
});

/* ================================================================ Écrans vides (29.7) */

test('un carnet vide propose d\'ajouter ou d\'importer une recette', function () {
    Livewire::test(RecipesIndex::class)
        ->assertSee('Le carnet est vide')
        ->assertSee('data-dish="assiette"', false)
        ->assertSee(route('recipes.create'), false)
        ->assertSee(route('recipes.import'), false);
});

/* ================================================================ Taille du texte (29.8) */

test('le texte peut être agrandi, sur toutes les pages', function () {
    $this->get(route('dashboard'))->assertOk()->assertDontSee('text-large', false);

    Livewire::test(Display::class)->assertSee('Taille du texte')->call('setTextSize', 'grand');
    expect($this->user->fresh()->preference('text_size'))->toBe('grand');

    $this->actingAs($this->user->fresh())->get(route('dashboard'))->assertOk()->assertSee('text-large', false);
});

test('une taille inconnue revient à la taille normale', function () {
    Livewire::test(Display::class)->call('setTextSize', 'énorme');
    expect($this->user->fresh()->preference('text_size'))->toBe('normal');
});

/* ================================================================ Polices (29.1) et barre du bas (maquette validée) */

test('sur téléphone, le « + » est au centre de la barre du bas et « Plus » passe en haut', function () {
    $html = $this->get(route('dashboard'))->assertOk()->getContent();

    $nav = str($html)->after('aria-label="Navigation mobile"')->before('Toutes les sections')->toString();
    expect(substr_count($nav, 'wire:navigate'))->toBe(4)   // quatre raccourcis
        ->and($nav)->toContain('<span class="sr-only">Ajouter</span>');

    // Le « + » vient après les deux premiers raccourcis.
    $links = explode('wire:navigate', str($nav)->before('<span class="sr-only">Ajouter</span>')->toString());
    expect(count($links) - 1)->toBe(2);

    expect($html)->toContain("\$dispatch('open-more')")
        ->toContain('x-on:open-more.window="more = true"');
});

test('les polices sont servies par Bouffe, sans appel à un service extérieur', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain("@import '@fontsource/figtree/latin-400.css'")
        ->toContain("@import '@fontsource/fraunces/latin-600.css'")
        ->toContain('--font-display')
        ->not->toContain('fonts.googleapis.com');

    expect($this->get(route('dashboard'))->getContent())->not->toContain('fonts.googleapis.com');
});
