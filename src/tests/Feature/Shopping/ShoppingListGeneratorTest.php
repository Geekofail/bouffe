<?php

use App\Models\MealSlot;
use App\Models\Unit;
use App\Services\Planning\WeekPlanner;
use App\Services\Shopping\ShoppingListGenerator;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00'));
    $this->seed([UnitSeeder::class, IngredientSeeder::class]);
    $this->planner = app(WeekPlanner::class);
    $this->generator = app(ShoppingListGenerator::class);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner']);
    $this->u = Unit::pluck('id', 'code');
});

test('addition détaillée par ingrédient', function (array $contributions, string $expected) {
    $meals = [];
    foreach ($contributions as $i => [$qty, $unit, $name]) {
        $recipe = recipeWith("R{$i}", 2, [[$qty, $unit, $name, false]]);
        $meals[] = $this->planner->addRecipe('2026-09-16', $this->dinner, $recipe, 2);
    }

    $lines = $this->generator->generate($this->generator->meals(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20')));

    expect(array_values(linesText($lines)))->toBe([$expected]);
})->with([
    'grammes + kilos' => [[[200, 'g', 'Spaghetti'], [1, 'kg', 'Spaghetti']], '1,2 kg de spaghetti'],
    'pièces + grammes → pièces (unité habituelle)' => [[[1, 'piece', 'Oignon'], [200, 'g', 'Oignon']], '3 oignons'],
    'pièces seules arrondies au supérieur' => [[[1.5, 'piece', 'Oignon'], [0.25, 'piece', 'Oignon']], '2 oignons'],
    'kilos + pièces → grammes (unité habituelle g)' => [[[1, 'kg', 'Pomme de terre'], [4, 'piece', 'Pomme de terre']], '1,6 kg de pommes de terre'],
    'cuillères identiques gardées' => [[[2, 'cs', 'Moutarde'], [1, 'cs', 'Moutarde']], '3 c. à soupe de moutarde'],
    'cuillères mélangées → ml' => [[[2, 'cs', 'Moutarde'], [1, 'cc', 'Moutarde']], '35 ml de moutarde'],
    'cl + ml' => [[[20, 'cl', 'Crème liquide'], [50, 'ml', 'Crème liquide']], '250 ml de crème liquide'],
    'unités incompatibles juxtaposées' => [[[2, 'boite', 'Tomates concassées'], [200, 'g', 'Tomates concassées']], '200 g de tomates concassées + 2 boîtes'],
    'gousses (unité habituelle de l\'ail)' => [[[2, 'gousse', 'Ail'], [1, 'gousse', 'Ail']], "3 gousses d'ail"],
    'sans quantité' => [[[null, null, 'Sel'], [null, null, 'Sel']], 'Sel'],
    'avec et sans quantité' => [[[null, null, 'Sucre'], [100, 'g', 'Sucre']], '100 g de sucre'],
    'pièce sans poids moyen' => [[[2, 'piece', 'Laurier'], [1, 'piece', 'Laurier']], '3 laurier'],
]);

test('R1 : mise à l\'échelle selon les portions planifiées', function () {
    $recipe = recipeWith('Gratin', 6, [[1200, 'g', 'Pomme de terre', false], [3, 'piece', 'Œuf', false]]);
    $this->planner->addRecipe('2026-09-16', $this->dinner, $recipe, 2);

    $lines = $this->generator->generate($this->generator->meals(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20')));

    expect(linesText($lines))->toBe(['Œuf' => '1 œuf', 'Pomme de terre' => '400 g de pommes de terre']);
});

test('R4 : restes, repas libres, repas passés et repas exclus ignorés ; facultatif et produits de base signalés', function () {
    $past = recipeWith('Passée', 2, [[100, 'g', 'Farine', false]]);
    $future = recipeWith('Future', 4, [[250, 'g', 'Farine', false], [1, 'pincee', 'Sel', false], [50, 'g', 'Parmesan', true]]);
    $excluded = recipeWith('Exclue', 2, [[1, 'piece', 'Avocat', false]]);

    $this->planner->addRecipe('2026-09-14', $this->dinner, $past);
    $meal = $this->planner->addRecipe('2026-09-16', $this->dinner, $future, 4);
    $this->planner->addLeftover('2026-09-17', $this->dinner, $meal);
    $this->planner->addFree('2026-09-18', $this->dinner, 'Restaurant');
    $skip = $this->planner->addRecipe('2026-09-19', $this->dinner, $excluded);

    $meals = $this->generator->meals(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20'), false, [$skip->id]);
    $lines = $this->generator->generate($meals)->keyBy(fn ($l) => $l->ingredient->name);

    expect($meals)->toHaveCount(1)
        ->and($lines->keys()->all())->toBe(['Farine', 'Parmesan', 'Sel'])
        ->and((float) $lines['Farine']->quantity())->toBe(250.0)
        ->and($lines['Farine']->isStaple)->toBeTrue()
        ->and($lines['Parmesan']->isOptional)->toBeTrue()
        ->and($lines['Farine']->isOptional)->toBeFalse();

    $withPast = $this->generator->meals(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20'), true);
    expect($withPast)->toHaveCount(3);
});

test('R5 : provenance de chaque contribution', function () {
    $a = recipeWith('Chili', 4, [[1, 'piece', 'Oignon', false]]);
    $b = recipeWith('Curry', 2, [[1, 'piece', 'Oignon', false]]);
    $this->planner->addRecipe('2026-09-16', $this->dinner, $a, 8);
    $this->planner->addRecipe('2026-09-18', $this->dinner, $b, 2);

    $line = $this->generator->generate($this->generator->meals(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20')))->first();

    expect($line->sources)->toHaveCount(2)
        ->and($line->sources[0])->toMatchArray(['recipe_title' => 'Chili', 'meal_date' => '2026-09-16', 'slot_name' => 'Dîner', 'quantity' => 2.0])
        ->and($line->sources[1])->toMatchArray(['recipe_title' => 'Curry', 'meal_date' => '2026-09-18', 'quantity' => 1.0]);
});
