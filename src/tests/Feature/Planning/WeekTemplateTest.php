<?php

use App\Enums\MealType;
use App\Livewire\Planner\Templates;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\User;
use App\Models\WeekTemplate;
use App\Services\Planning\WeekPlanner;
use App\Services\Planning\WeekTemplateManager;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00'));
    $this->actingAs(User::factory()->create(['name' => 'Pierre']));
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 2]);
    $this->lunch = MealSlot::factory()->create(['name' => 'Déjeuner', 'sort_order' => 1]);
    $this->planner = app(WeekPlanner::class);
    $this->manager = app(WeekTemplateManager::class);
    $this->source = Carbon::parse('2026-09-14');   // lundi
    $this->target = Carbon::parse('2026-09-21');
});

test('une semaine se transforme en modèle et se réapplique ailleurs', function () {
    $lasagnes = Recipe::factory()->create(['title' => 'Lasagnes', 'servings' => 4]);
    $soupe = Recipe::factory()->create(['title' => 'Soupe de légumes']);

    $meal = $this->planner->addRecipe('2026-09-14', $this->dinner, $lasagnes, 4);
    $this->planner->addLeftover('2026-09-15', $this->lunch, $meal, 2);
    $this->planner->addFree('2026-09-16', $this->dinner, 'Resto');
    $this->planner->addRecipe('2026-09-17', $this->dinner, $soupe);

    $template = $this->manager->saveFromWeek($this->source, 'Semaine rapide');

    expect($template->meals)->toHaveCount(4)
        ->and($template->meals->firstWhere('type', MealType::Leftover)->leftover_weekday)->toBe(1);

    $report = $this->manager->apply($template, $this->target);

    expect($report['placed'])->toBe(4)
        ->and($report['skipped'])->toBe([]);

    $meals = $this->planner->mealsForWeek($this->target);

    expect($meals)->toHaveCount(4)
        ->and($meals->firstWhere('type', MealType::Free)->free_text)->toBe('Resto')
        ->and($meals->firstWhere('type', MealType::Leftover)->date->toDateString())->toBe('2026-09-22');
});

test('les portions suivent les convives de la semaine d\'arrivée, pas celles du modèle', function () {
    $recipe = Recipe::factory()->create(['title' => 'Curry']);
    $this->planner->addRecipe('2026-09-14', $this->dinner, $recipe, 6);

    $template = $this->manager->saveFromWeek($this->source, 'Semaine curry');

    // Le foyer compte 2 personnes : les 6 portions du modèle ne sont pas reprises.
    $this->manager->apply($template, $this->target);

    expect(PlannedMeal::query()->whereDate('date', '2026-09-21')->first()->servings)->toBe(2.0);
});

test('le mode « remplir les vides » respecte ce qui est déjà planifié', function () {
    $a = Recipe::factory()->create(['title' => 'Poulet rôti']);
    $b = Recipe::factory()->create(['title' => 'Quiche']);

    $this->planner->addRecipe('2026-09-14', $this->dinner, $a);
    $this->planner->addRecipe('2026-09-15', $this->dinner, $b);
    $template = $this->manager->saveFromWeek($this->source, 'Deux repas');

    $dejaLa = Recipe::factory()->create(['title' => 'Déjà prévu']);
    $this->planner->addRecipe('2026-09-21', $this->dinner, $dejaLa);

    $report = $this->manager->apply($template, $this->target, 'fill');

    expect($report['placed'])->toBe(1)
        ->and($report['skipped'][0])->toContain('la case est déjà occupée')
        ->and(PlannedMeal::query()->whereDate('date', '2026-09-21')->first()->recipe_id)->toBe($dejaLa->id);
});

test('le mode « remplacer » vide la semaine avant d\'appliquer le modèle', function () {
    $a = Recipe::factory()->create(['title' => 'Poulet rôti']);
    $this->planner->addRecipe('2026-09-14', $this->dinner, $a);
    $template = $this->manager->saveFromWeek($this->source, 'Un repas');

    $this->planner->addRecipe('2026-09-21', $this->dinner, Recipe::factory()->create(['title' => 'Déjà prévu']));
    $this->planner->addRecipe('2026-09-23', $this->dinner, Recipe::factory()->create(['title' => 'Autre']));

    $report = $this->manager->apply($template, $this->target, 'replace');

    expect($report['removed'])->toBe(2)
        ->and($report['placed'])->toBe(1)
        ->and(PlannedMeal::query()->whereBetween('date', ['2026-09-21', '2026-09-27'])->count())->toBe(1);
});

test('une recette archivée depuis est signalée et sa case reste vide', function () {
    $recipe = Recipe::factory()->create(['title' => 'Blanquette']);
    $this->planner->addRecipe('2026-09-14', $this->dinner, $recipe);
    $template = $this->manager->saveFromWeek($this->source, 'Semaine blanquette');

    $recipe->update(['archived_at' => now()]);

    $report = $this->manager->apply($template, $this->target);

    expect($report['placed'])->toBe(0)
        ->and($report['skipped'][0])->toContain('recette archivée');
});

test('un invité allergique de la semaine d\'arrivée est signalé', function () {
    $guest = \App\Models\Guest::factory()->create(['name' => 'Julie']);
    $arachide = \App\Models\Ingredient::factory()->create(['name' => 'Arachide']);
    $guest->restrictions()->create(['type' => \App\Enums\RestrictionType::Allergy->value, 'ingredient_id' => $arachide->id]);

    $satay = Recipe::factory()->create(['title' => 'Poulet satay']);
    $satay->ingredients()->create(['ingredient_id' => $arachide->id, 'quantity' => 50, 'sort_order' => 1]);

    $this->planner->addRecipe('2026-09-14', $this->dinner, $satay);
    $template = $this->manager->saveFromWeek($this->source, 'Semaine satay');

    app(\App\Services\Planning\OccasionService::class)->save('2026-09-21', $this->dinner, ['guest_ids' => [$guest->id]]);

    $report = $this->manager->apply($template, $this->target);

    expect($report['placed'])->toBe(1)
        ->and($report['warnings'][0])->toContain('Julie');
});

test('une semaine vide ne peut pas être enregistrée', function () {
    expect(fn () => $this->manager->saveFromWeek($this->target, 'Vide'))
        ->toThrow(InvalidArgumentException::class, 'Cette semaine est vide');
});

test('l\'écran enregistre, applique et supprime une semaine type', function () {
    $recipe = Recipe::factory()->create(['title' => 'Chili']);
    $this->planner->addRecipe('2026-09-14', $this->dinner, $recipe);

    Livewire::withQueryParams(['semaine' => '2026-09-14'])->test(Templates::class)
        ->call('openSave')
        ->set('name', 'Semaine d\'hiver')
        ->call('save')
        ->assertHasNoErrors();

    $template = WeekTemplate::firstWhere('name', 'Semaine d\'hiver');
    expect($template)->not->toBeNull();

    Livewire::withQueryParams(['semaine' => '2026-09-21'])->test(Templates::class)
        ->call('openApply', $template->id)
        ->call('apply')
        ->assertSet('report.placed', 1);

    expect(PlannedMeal::query()->whereDate('date', '2026-09-21')->count())->toBe(1);

    Livewire::test(Templates::class)->call('delete', $template->id);
    expect(WeekTemplate::count())->toBe(0);
});

test('deux semaines types ne peuvent pas porter le même nom', function () {
    $this->planner->addRecipe('2026-09-14', $this->dinner, Recipe::factory()->create());
    $this->manager->saveFromWeek($this->source, 'Semaine rapide');

    Livewire::withQueryParams(['semaine' => '2026-09-14'])->test(Templates::class)
        ->call('openSave')
        ->set('name', 'Semaine rapide')
        ->call('save')
        ->assertHasErrors('name');
});
