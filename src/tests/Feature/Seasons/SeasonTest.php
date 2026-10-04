<?php

use App\Livewire\Recipes\Index as RecipesIndex;
use App\Livewire\Settings\Seasons as SeasonsPage;
use App\Models\Ingredient;
use App\Models\User;
use App\Services\Seasons\SeasonCalendar;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\SeasonSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Saisons (lot 19 — 17.1, règle R18).
 *
 * Le point délicat : un ingrédient sans saison renseignée ne doit **jamais** rendre une recette
 * hors saison, et un ingrédient secondaire hors saison ne doit pas non plus disqualifier la recette.
 */

require_once __DIR__.'/../Shopping/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 10:00'));      // octobre
    $this->actingAs(User::factory()->create(['name' => 'Pierre']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, IngredientSeeder::class]);

    $this->calendar = app(SeasonCalendar::class);

    $this->fraise = Ingredient::firstWhere('name', 'Fraise') ?? Ingredient::factory()->create(['name' => 'Fraise']);
    $this->potiron = Ingredient::firstWhere('name', 'Potiron') ?? Ingredient::factory()->create(['name' => 'Potiron']);

    $this->fraise->forceFill(['season_months' => [5, 6, 7]])->save();
    $this->potiron->forceFill(['season_months' => [9, 10, 11, 12]])->save();
});

test('un ingrédient sans mois renseignés n\'est jamais hors saison', function () {
    $farine = Ingredient::firstWhere('name', 'Farine');

    expect($this->calendar->hasSeason($farine))->toBeFalse()
        ->and($this->calendar->isInSeason($farine, 1))->toBeTrue()
        ->and($this->calendar->isInSeason($farine, 8))->toBeTrue();
});

test('une recette dont tous les fruits et légumes sont de saison est de saison', function () {
    $recipe = recipeWith('Soupe de potiron', 4, [[800, 'g', 'Potiron', false], [1, 'piece', 'Oignon', false]]);

    $status = $this->calendar->recipeStatus($recipe);

    expect($status['status'])->toBe(SeasonCalendar::IN_SEASON)
        ->and($status['offenders'])->toBe([])
        ->and($status['month'])->toBe(10);
});

test('une recette dont l\'ingrédient principal est hors saison est signalée', function () {
    $recipe = recipeWith('Tarte aux fraises', 6, [[500, 'g', 'Fraise', false], [200, 'g', 'Farine', false]]);

    $status = $this->calendar->recipeStatus($recipe);

    expect($status['status'])->toBe(SeasonCalendar::OFF_SEASON)
        ->and($status['offenders'])->toBe(['Fraise'])
        ->and($status['main'])->toBe('Fraise')
        ->and($this->calendar->explain($status))->toContain('octobre');
});

test('un ingrédient secondaire hors saison ne disqualifie pas la recette', function () {
    // 800 g de potiron (de saison) et 30 g de fraises en décoration.
    $recipe = recipeWith('Velouté surprise', 4, [[800, 'g', 'Potiron', false], [30, 'g', 'Fraise', false]]);

    $status = $this->calendar->recipeStatus($recipe);

    expect($status['status'])->toBe(SeasonCalendar::NEUTRAL)
        ->and($status['offenders'])->toBe(['Fraise'])
        ->and($status['main'])->toBe('Potiron');
});

test('un ingrédient facultatif hors saison est ignoré', function () {
    $recipe = recipeWith('Soupe de potiron', 4, [[800, 'g', 'Potiron', false], [100, 'g', 'Fraise', true]]);

    expect($this->calendar->recipeStatus($recipe)['status'])->toBe(SeasonCalendar::IN_SEASON);
});

test('la saison est évaluée au mois du repas, pas au mois d\'aujourd\'hui', function () {
    $recipe = recipeWith('Tarte aux fraises', 6, [[500, 'g', 'Fraise', false]]);

    expect($this->calendar->recipeStatus($recipe, Carbon::parse('2026-06-10'))['status'])->toBe(SeasonCalendar::IN_SEASON)
        ->and($this->calendar->recipeStatus($recipe, Carbon::parse('2026-10-10'))['status'])->toBe(SeasonCalendar::OFF_SEASON);
});

test('le remplissage automatique favorise les recettes de saison', function () {
    $potiron = recipeWith('Soupe de potiron', 4, [[800, 'g', 'Potiron', false]]);
    $fraises = recipeWith('Tarte aux fraises', 6, [[500, 'g', 'Fraise', false]]);

    expect($this->calendar->fillerBonus($potiron))->toBe(SeasonCalendar::FILLER_BONUS)
        ->and($this->calendar->fillerBonus($fraises))->toBe(SeasonCalendar::FILLER_PENALTY);
});

test('les valeurs de départ ne remplacent jamais un réglage existant', function () {
    $carotte = Ingredient::firstWhere('name', 'Carotte');
    $carotte->forceFill(['season_months' => [7]])->save();

    (new SeasonSeeder)->run();

    expect($carotte->fresh()->season_months)->toBe([7]);
});

test('l\'écran des saisons coche et décoche un mois', function () {
    $page = Livewire::test(SeasonsPage::class)
        ->call('toggle', $this->potiron->id, 1)      // janvier en plus
        ->call('toggle', $this->potiron->id, 9);     // septembre en moins

    expect($this->potiron->fresh()->season_months)->toBe([1, 10, 11, 12]);

    $page->call('clear', $this->potiron->id);

    expect($this->potiron->fresh()->season_months)->toBeNull();
});

test('le filtre « de saison » ne retient que les recettes de saison', function () {
    recipeWith('Soupe de potiron', 4, [[800, 'g', 'Potiron', false]]);
    recipeWith('Tarte aux fraises', 6, [[500, 'g', 'Fraise', false]]);

    Livewire::test(RecipesIndex::class)
        ->set('inSeasonOnly', true)
        ->assertSee('Soupe de potiron')
        ->assertDontSee('Tarte aux fraises');
});
