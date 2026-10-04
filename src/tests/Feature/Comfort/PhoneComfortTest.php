<?php

use App\Livewire\Planner\Week;
use App\Livewire\Recipes\Index as RecipesIndex;
use App\Livewire\Stock\Index as StockIndex;
use App\Models\MealSlot;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use App\Services\Planning\WeekPlanner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/*
 * Confort sur téléphone (lot 28) : planning jour par jour et menu « Plus », filtres repliés,
 * étiquettes des cartes, libellés gardés pour les lecteurs d'écran, base des tests navigateur.
 * Le rendu réel (un seul jour visible, balayage, feuilles) est vérifié dans le navigateur :
 * tests/Browser (npm run test:browser).
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00')); // mercredi
    $this->user = User::factory()->create(['name' => 'Pierre']);
    $this->actingAs($this->user);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 1]);
});

/* ================================================================ Planning (28.2, 28.3) */

test('sur téléphone, le planning s\'ouvre sur aujourd\'hui, ou sur lundi pour une autre semaine', function () {
    Livewire::test(Week::class)
        ->assertSee('data-day="2"', false)                  // mercredi = 3e jour
        ->assertSee('data-all="0"', false)
        ->call('nextWeek')
        ->assertSet('day', -1)
        ->assertSee('data-day="0"', false);
});

test('le jour choisi est gardé d\'une action à l\'autre', function () {
    Livewire::test(Week::class)
        ->set('day', 5)
        ->call('toggleMine')
        ->assertSee('data-day="5"', false)
        ->call('toggleAllDays')
        ->assertSee('data-all="1"', false)
        ->call('toggleAllDays')
        ->assertSee('data-all="0"', false);
});

test('balayer au-delà du dimanche mène au lundi suivant, avant le lundi au dimanche précédent', function () {
    Livewire::test(Week::class)
        ->call('shiftDay', 1)
        ->assertSet('week', '2026-09-21')->assertSet('day', 0)
        ->call('shiftDay', -1)
        ->assertSet('week', '2026-09-14')->assertSet('day', 6);
});

test('l\'ajout rapide ouvre le planning sur le jour demandé', function () {
    Livewire::withQueryParams(['ajouter' => '2026-09-19', 'creneau' => $this->dinner->id])
        ->test(Week::class)
        ->assertSet('day', 5)
        ->assertSee('data-day="5"', false);
});

test('le menu « Plus » du planning donne chaque action avec son libellé', function () {
    app(WeekPlanner::class)->addRecipe('2026-09-16', $this->dinner, Recipe::factory()->create(['title' => 'Lasagnes']), 4);

    Livewire::test(Week::class)
        ->assertSee('Actions de la semaine')
        ->assertSeeInOrder(['Cette semaine', 'Copier la semaine', 'Imprimer le menu', 'Semaines types', 'Cuisiner en avance', 'Affichage', 'Mes repas en avant', 'Vue liste compacte', 'Vider la semaine'])
        ->assertSee('aria-label="Mercredi 16 septembre : 1 repas"', false);
});

test('un compte en lecture seule n\'a ni « Remplir » ni « Vider la semaine »', function () {
    $this->actingAs(User::factory()->create(['role' => 'viewer']));

    Livewire::test(Week::class)
        ->assertSee('Copier la semaine')
        ->assertDontSee('Vider la semaine')
        ->assertDontSee('Cuisiner en avance');
});

/* ================================================================ Filtres (28.4) */

test('les filtres des recettes se replient sur téléphone et se comptent', function () {
    $tag = Tag::factory()->create(['name' => 'Soupe']);
    Recipe::factory()->create()->tags()->attach($tag);

    Livewire::test(RecipesIndex::class)
        ->assertSee('max-sm:hidden', false)
        ->assertSee('Filtres')
        ->set('showFilters', true)
        ->assertDontSee('max-sm:hidden', false)
        ->set('favoritesOnly', true)
        ->call('toggleTag', $tag->id)
        ->assertSee('Filtres (2)')
        ->call('clearFilters')
        ->assertDontSee('Filtres (');
});

test('les filtres du stock sont proposés dans une feuille, le filtre actif s\'efface d\'un geste', function () {
    Livewire::test(StockIndex::class)
        ->assertSee('Filtrer le stock')
        ->set('filter', 'depasse')
        ->assertSee('Filtres (1)')
        ->assertSee('Retirer ce filtre');
});

/* ================================================================ Défauts (28.1) */

test('les étiquettes d\'une carte de recette sont rangées ensemble, jamais superposées', function () {
    $recipe = Recipe::factory()->create(['title' => 'Velouté', 'is_to_test' => true]);

    $html = view('components.recipe.card', ['recipe' => $recipe->load('tags'), 'season' => ['status' => 'season']])->render();

    expect(substr_count($html, 'absolute top-2 left-2'))->toBe(1)
        ->and($html)->toContain('À tester')->toContain('De saison');
});

test('un libellé masqué sur téléphone reste lisible par un lecteur d\'écran', function () {
    $offenders = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file) => preg_match('/class="hidden (sm|md|lg|xl):inline"/', $file->getContents()))
        ->map(fn ($file) => $file->getRelativePathname())
        ->values()->all();

    expect($offenders)->toBe([]);   // utiliser « sr-only sm:not-sr-only »
});

test('la barre de progression de la liste a un nom', function () {
    expect(view('components.shopping.progress', ['checked' => 2, 'total' => 5])->render())
        ->toContain('role="progressbar" aria-label="Articles cochés" aria-valuenow="40"');
});

/* ================================================================ Tests navigateur (28.7) */

test('la base des tests navigateur ne peut pas être recréée ailleurs que dans son fichier', function () {
    $users = User::count();

    expect(Artisan::call('bouffe:browser-db'))->toBe(1)
        ->and(Artisan::output())->toContain('Refusé')
        ->and(User::count())->toBe($users);   // rien n'a été effacé
});
