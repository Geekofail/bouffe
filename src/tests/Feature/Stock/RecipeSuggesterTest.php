<?php

use App\Models\Ingredient;
use App\Models\MealSlot;
use App\Models\User;
use App\Services\Planning\WeekPlanner;
use App\Services\Stock\RecipeSuggester;
use App\Services\Stock\StockManager;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../Shopping/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00'));
    $this->actingAs(User::factory()->create());
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);
    $this->stock = app(StockManager::class);
    $this->suggester = app(RecipeSuggester::class);
    $this->planner = app(WeekPlanner::class);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner']);

    // Pour 2 personnes
    $this->omelette = recipeWith('Omelette', 2, [[4, 'piece', 'Œuf', false], [20, 'g', 'Beurre', false], [null, null, 'Sel', false], [30, 'g', 'Parmesan', true]]);
    $this->poulet = recipeWith('Poulet à la crème', 2, [[2, 'piece', 'Blanc de poulet', false], [20, 'cl', 'Crème liquide', false], [1, 'piece', 'Oignon', false]]);
    $this->tarte = recipeWith('Tarte à l\'oignon', 2, [[200, 'g', 'Farine', false], [100, 'g', 'Beurre', false], [3, 'piece', 'Oignon', false], [2, 'piece', 'Œuf', false], [20, 'cl', 'Crème liquide', false]]);
});

function sug(string $name): Ingredient
{
    return Ingredient::firstWhere('name', $name);
}

function titles($rows): array
{
    return $rows->map(fn ($r) => $r['recipe']->title)->all();
}

test('groupes : faisable, presque (1 ou 2 manques), autres ; produit de base jamais suivi réputé disponible', function () {
    $this->stock->add(['ingredient_id' => sug('Œuf')->id, 'quantity' => 6]);
    $this->stock->add(['ingredient_id' => sug('Beurre')->id, 'quantity' => 250]);
    $this->stock->add(['ingredient_id' => sug('Oignon')->id, 'quantity' => 1]);

    $result = $this->suggester->suggest(['servings' => 2]);

    expect(titles($result['feasible']))->toBe(['Omelette'])
        ->and(titles($result['almost']))->toBe(['Tarte à l\'oignon'])          // oignon partiel + crème manquante, 3,5/5
        ->and(titles($result['others']))->toBe(['Poulet à la crème']);     // 2 manques mais seulement 1/3 disponible

    $omelette = $result['feasible'][0];
    expect($omelette['counted'])->toBe(3)                        // parmesan facultatif non compté
        ->and($omelette['lines'][sug('Sel')->id]['status'])->toBe('assumed')
        ->and($omelette['lines'][sug('Parmesan')->id]['optional'])->toBeTrue()
        ->and($omelette['coverage'])->toBe(1.0);

    $poulet = $result['others'][0];
    expect($poulet['available'])->toBe(1)
        ->and($poulet['missing_text'])->toBe('blanc de poulet 2 pièces, crème liquide 200 ml');

    $tarte = $this->suggester->evaluate($this->tarte, 2);
    expect($tarte['lines'][sug('Oignon')->id]['status'])->toBe('partial')
        ->and($tarte['lines'][sug('Oignon')->id]['text'])->toBe('manque 2 pièces')
        ->and($tarte['lines'][sug('Farine')->id]['status'])->toBe('assumed')
        ->and($tarte['available'])->toBe(3.5);                // farine, beurre, œufs + ½ oignon
});

test('portions : les quantités sont mises à l\'échelle ; quantité inconnue = à vérifier', function () {
    $this->stock->add(['ingredient_id' => sug('Œuf')->id, 'quantity' => 6]);
    $this->stock->add(['ingredient_id' => sug('Beurre')->id]);   // quantité inconnue

    $for2 = $this->suggester->evaluate($this->omelette, 2);
    $for4 = $this->suggester->evaluate($this->omelette, 4);

    expect($for2['lines'][sug('Œuf')->id]['status'])->toBe('ok')
        ->and($for2['lines'][sug('Beurre')->id]['status'])->toBe('check')
        ->and($for4['lines'][sug('Œuf')->id]['status'])->toBe('partial')
        ->and($for4['lines'][sug('Œuf')->id]['text'])->toBe('manque 2 pièces');
});

test('un produit de base suivi et épuisé est manquant ; une DLC dépassée ne compte pas', function () {
    $this->stock->setPresence(sug('Sel'), true);
    $this->stock->setPresence(sug('Sel'), false);
    $this->stock->add(['ingredient_id' => sug('Œuf')->id, 'quantity' => 6, 'expires_on' => '2026-09-15', 'expiry_type' => 'dlc']);

    $lines = $this->suggester->evaluate($this->omelette, 2)['lines'];

    expect($lines[sug('Sel')->id]['status'])->toBe('missing')
        ->and($lines[sug('Œuf')->id]['status'])->toBe('missing');
});

test('score : anti-gaspi, plafond, mangée récemment, favorite et bien notée', function () {
    $this->stock->add(['ingredient_id' => sug('Œuf')->id, 'quantity' => 6, 'expires_on' => '2026-09-17', 'expiry_type' => 'dlc']);   // J+1 → +15
    $this->stock->add(['ingredient_id' => sug('Beurre')->id, 'quantity' => 250, 'expires_on' => '2026-09-20', 'expiry_type' => 'dlc']); // J+4 → +8
    $this->stock->add(['ingredient_id' => sug('Blanc de poulet')->id, 'quantity' => 2, 'expires_on' => '2026-10-30']);
    $this->stock->add(['ingredient_id' => sug('Crème liquide')->id, 'quantity' => 20, 'unit_id' => \App\Models\Unit::firstWhere('code', 'cl')->id, 'expires_on' => '2026-10-30']);
    $this->stock->add(['ingredient_id' => sug('Oignon')->id, 'quantity' => 1, 'expires_on' => '2026-10-30']);

    $result = $this->suggester->suggest(['servings' => 2]);
    $scores = $result['feasible']->mapWithKeys(fn ($r) => [$r['recipe']->title => $r['score']])->all();

    expect($scores)->toBe(['Omelette' => 123.0, 'Poulet à la crème' => 100.0])
        ->and($result['feasible'][0]['urgent'])->toBe(2);

    // Mangée il y a 3 jours, favorite, note 4
    $this->poulet->update(['is_favorite' => true]);
    $this->poulet->ratings()->create(['user_id' => auth()->id(), 'rating' => 4]);
    $this->omelette->plannedMeals()->create(['date' => '2026-09-13', 'meal_slot_id' => $this->dinner->id, 'type' => 'recipe', 'servings' => 2]);

    $scores = $this->suggester->suggest(['servings' => 2])['feasible']->mapWithKeys(fn ($r) => [$r['recipe']->title => $r['score']])->all();
    expect($scores)->toBe(['Poulet à la crème' => 110.0, 'Omelette' => 103.0]);

    // « Doit utiliser » : seules les recettes qui contiennent l'ingrédient, +20 plafonné à 40
    $withEggs = $this->suggester->suggest(['servings' => 2, 'mustUse' => [sug('Œuf')->id]]);
    expect(titles($withEggs['feasible']))->toBe(['Omelette'])
        ->and($withEggs['feasible'][0]['score'])->toBe(120.0);    // 100 + min(40, 23 + 20) − 20
});

test('réservations du planning : le stock promis aux 7 prochains jours n\'est pas proposé, sauf « ignorer le planning »', function () {
    $this->stock->add(['ingredient_id' => sug('Blanc de poulet')->id, 'quantity' => 2]);
    $this->stock->add(['ingredient_id' => sug('Crème liquide')->id, 'quantity' => 40, 'unit_id' => \App\Models\Unit::firstWhere('code', 'cl')->id]);
    $this->stock->add(['ingredient_id' => sug('Oignon')->id, 'quantity' => 2]);

    $thursday = $this->planner->addRecipe('2026-09-17', $this->dinner, $this->poulet, 2);

    $result = $this->suggester->suggest(['servings' => 2]);
    expect($result['reserved'])->toBe(1)
        ->and(titles($result['feasible']))->toBe([])
        ->and($result['almost']->first()['missing_text'])->toBe('blanc de poulet 2 pièces');   // crème : 40 − 20 = 20 cl restants

    expect(titles($this->suggester->suggest(['servings' => 2, 'ignorePlanning' => true])['feasible']))->toBe(['Poulet à la crème'])
        ->and(titles($this->suggester->suggest(['servings' => 2, 'excludeMealId' => $thursday->id])['feasible']))->toBe(['Poulet à la crème']);

    // Mangé, ou au-delà de 7 jours : plus réservé
    $thursday->update(['cooked_at' => now()]);
    expect(titles($this->suggester->suggest(['servings' => 2])['feasible']))->toBe(['Poulet à la crème']);

    $thursday->update(['cooked_at' => null, 'date' => '2026-09-23']);
    expect(titles($this->suggester->suggest(['servings' => 2])['feasible']))->toBe(['Poulet à la crème']);
});

test('filtres : catégories, temps total, recettes archivées exclues', function () {
    $this->stock->add(['ingredient_id' => sug('Œuf')->id, 'quantity' => 12]);
    $quick = \App\Models\Tag::factory()->create(['name' => 'Rapide']);
    $this->omelette->tags()->attach($quick);
    $this->omelette->update(['prep_minutes' => 5, 'cook_minutes' => 5]);
    $this->tarte->update(['prep_minutes' => 20, 'cook_minutes' => 40]);
    $this->poulet->update(['archived_at' => now()]);

    $all = fn (array $o) => collect($this->suggester->suggest(['servings' => 2, ...$o]))->except('reserved')->flatten(1)->map(fn ($r) => $r['recipe']->title)->sort()->values()->all();

    expect($all([]))->toBe(['Omelette', 'Tarte à l\'oignon'])
        ->and($all(['tagIds' => [$quick->id]]))->toBe(['Omelette'])
        ->and($all(['maxMinutes' => 30]))->toBe(['Omelette']);
});

test('« presque » demande au moins la moitié des ingrédients', function () {
    $toast = recipeWith('Tartine', 2, [[1, 'piece', 'Tomate', false], [50, 'g', 'Parmesan', false]]);

    expect(titles($this->suggester->suggest(['servings' => 2])['others']))->toContain('Tartine');

    $this->stock->add(['ingredient_id' => sug('Tomate')->id, 'quantity' => 2]);
    expect(titles($this->suggester->suggest(['servings' => 2])['almost']))->toContain('Tartine');
});
