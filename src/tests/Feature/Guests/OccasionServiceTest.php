<?php

use App\Models\Guest;
use App\Models\MealOccasion;
use App\Models\MealSlot;
use App\Models\User;
use App\Services\Planning\OccasionService;
use App\Services\Planning\WeekPlanner;
use Illuminate\Support\Carbon;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00'));
    config(['bouffe.household_size' => 2, 'bouffe.child_portion' => 0.5]);
    $this->pierre = User::factory()->create(['name' => 'Pierre']);
    $this->monique = User::factory()->create(['name' => 'Monique']);
    $this->actingAs($this->pierre);
    $this->service = app(OccasionService::class);
    $this->planner = app(WeekPlanner::class);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner']);
    $this->lunch = MealSlot::factory()->create(['name' => 'Déjeuner']);
});

test('sans convives saisis, le repas compte le foyer', function () {
    expect($this->service->dinersAt('2026-09-20', $this->dinner))->toBe(2.0)
        ->and(MealOccasion::count())->toBe(0);
});

test('convives = présents du foyer + adultes + enfants pondérés, arrondi au supérieur', function () {
    $julie = Guest::factory()->create(['name' => 'Julie']);
    $leo = Guest::factory()->child()->create(['name' => 'Léo']);

    $result = $this->service->save('2026-09-20', $this->dinner, [
        'guest_ids' => [$julie->id, $leo->id],
        'extra_adults' => 1,
        'extra_children' => 2,
        'absent_user_ids' => [$this->monique->id],
        'title' => 'Anniversaire de Julie',
    ]);

    // 1 présent + Julie + 1 adulte = 3 ; 3 enfants × 0,5 = 1,5 → 4,5 (lot 32, R33 : à la demi-portion)
    expect($result['before'])->toBe(2.0)
        ->and($result['after'])->toBe(4.5)
        ->and($this->service->people($result['occasion']))->toBe(6)
        ->and($this->service->summary($result['occasion']))->toBe('6 personnes · 4,5 portions')
        ->and($result['occasion']->title)->toBe('Anniversaire de Julie')
        ->and($this->service->guestSummary($result['occasion']))->toBe('Julie, Léo, +1 adulte, +2 enfants, sans Monique');

    config(['bouffe.child_portion' => 1]);
    expect($this->service->dinersAt('2026-09-20', $this->dinner))->toBe(6.0);
});

test('tout le monde absent : 0 convive, mais 1 portion proposée au minimum', function () {
    $this->service->save('2026-09-20', $this->lunch, ['absent_user_ids' => [$this->pierre->id, $this->monique->id]]);

    expect($this->service->dinersAt('2026-09-20', $this->lunch))->toBe(0.0)
        ->and($this->service->servingsAt('2026-09-20', $this->lunch))->toBe(1.0);
});

test('revenir à la situation par défaut supprime l\'enregistrement', function () {
    $guest = Guest::factory()->create();
    $this->service->save('2026-09-20', $this->dinner, ['guest_ids' => [$guest->id]]);
    expect(MealOccasion::count())->toBe(1);

    $result = $this->service->save('2026-09-20', $this->dinner, []);

    expect($result['occasion'])->toBeNull()
        ->and($result['before'])->toBe(3.0)
        ->and($result['after'])->toBe(2.0)
        ->and(MealOccasion::count())->toBe(0)
        ->and($guest->fresh())->not->toBeNull();
});

test('refuse un nombre d\'invités hors limites', function () {
    $this->service->save('2026-09-20', $this->dinner, ['extra_adults' => 51]);
})->throws(InvalidArgumentException::class);

test('adapter les portions garde les portions prévues pour les restes', function () {
    $lasagnes = $this->planner->addRecipe('2026-09-20', $this->dinner, dish('Lasagnes', ['Pâtes']), 4);   // 2 + 2 de restes
    $dessert = $this->planner->addRecipe('2026-09-20', $this->dinner, dish('Tiramisu', ['Mascarpone']), 2);
    $free = $this->planner->addFree('2026-09-20', $this->dinner, 'Fromages');

    ['before' => $before, 'after' => $after] = $this->service->save('2026-09-20', $this->dinner, ['extra_adults' => 4]);
    $count = $this->service->adaptServings('2026-09-20', $this->dinner, $before, $after);

    expect($count)->toBe(2)
        ->and($lasagnes->fresh()->servings)->toBe(8.0)
        ->and($dessert->fresh()->servings)->toBe(6.0)
        ->and($free->fresh()->servings)->toBe(2.0);
});

test('adapter à la baisse ne supprime pas les restes déjà placés', function () {
    $this->service->save('2026-09-20', $this->dinner, ['extra_adults' => 4]);          // 6 convives
    $meal = $this->planner->addRecipe('2026-09-20', $this->dinner, dish('Chili', ['Haricots rouges']), 8);
    $this->planner->addLeftover('2026-09-21', $this->lunch, $meal, 2);

    ['before' => $before, 'after' => $after] = $this->service->save('2026-09-20', $this->dinner, ['extra_adults' => 0]);
    // 8 − 6 + 2 = 4 portions : 2 mangées + 2 restes, compatible
    expect($this->service->adaptServings('2026-09-20', $this->dinner, $before, $after))->toBe(1)
        ->and($meal->fresh()->servings)->toBe(4.0);
});

/* ------------------------------------------------------------------ R7 dans le planning */

test('les portions proposées suivent les convives de la case', function () {
    $this->service->save('2026-09-20', $this->dinner, ['extra_adults' => 4]);

    $meal = $this->planner->addRecipe('2026-09-20', $this->dinner, dish('Paella', ['Riz']));
    $other = $this->planner->addRecipe('2026-09-21', $this->dinner, dish('Soupe', ['Poireau']));

    expect($meal->servings)->toBe(6.0)->and($other->servings)->toBe(2.0);
});

test('les restes tiennent compte des convives du repas', function () {
    $this->service->save('2026-09-20', $this->dinner, ['extra_adults' => 4]);   // 6 convives
    $meal = $this->planner->addRecipe('2026-09-20', $this->dinner, dish('Lasagnes', ['Pâtes']), 8);

    expect($this->planner->remainingLeftovers($meal))->toBe(2.0)
        ->and($this->planner->availableLeftovers('2026-09-21')->first()['remaining'])->toBe(2.0);

    // Sans invités, 8 portions pour 2 laisseraient 6 portions de restes
    $alone = $this->planner->addRecipe('2026-09-22', $this->dinner, dish('Hachis', ['Boeuf']), 8);
    expect($this->planner->remainingLeftovers($alone))->toBe(6.0);
});

test('des restes placés sur un repas où l\'un est absent prennent une portion', function () {
    $meal = $this->planner->addRecipe('2026-09-20', $this->dinner, dish('Gratin', ['Pommes de terre']), 6);
    $this->service->save('2026-09-21', $this->lunch, ['absent_user_ids' => [$this->monique->id]]);

    $leftover = $this->planner->addLeftover('2026-09-21', $this->lunch, $meal);

    expect($leftover->servings)->toBe(1.0)->and($this->planner->remainingLeftovers($meal))->toBe(3.0);
});

test('réduire les portions d\'un repas avec invités vérifie les restes avec les convives', function () {
    $this->service->save('2026-09-20', $this->dinner, ['extra_adults' => 2]);   // 4 convives
    $meal = $this->planner->addRecipe('2026-09-20', $this->dinner, dish('Couscous', ['Semoule']), 6);
    $this->planner->addLeftover('2026-09-21', $this->lunch, $meal, 2);

    expect(fn () => $this->planner->update($meal, ['servings' => 5]))
        ->toThrow(InvalidArgumentException::class, 'il faut au moins 6 portions');
});
