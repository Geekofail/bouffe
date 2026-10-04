<?php

use App\Enums\MealType;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\Tag;
use App\Services\Planning\WeekPlanner;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00')); // mercredi
    $this->planner = app(WeekPlanner::class);
    $this->lunch = MealSlot::factory()->create(['name' => 'Déjeuner', 'sort_order' => 1]);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 2]);
    $this->recipe = Recipe::factory()->create(['title' => 'Lasagnes']);
});

test('la semaine commence le lundi', function () {
    expect($this->planner->weekStart('2026-09-16')->toDateString())->toBe('2026-09-14')
        ->and($this->planner->weekStart('2026-09-20')->toDateString())->toBe('2026-09-14')
        ->and($this->planner->weekStart('2026-09-21')->toDateString())->toBe('2026-09-21')
        ->and($this->planner->days(Carbon::parse('2026-09-14'))->last()->toDateString())->toBe('2026-09-20');
});

test('ajout de recettes et de repas libres dans une case, positions successives', function () {
    $a = $this->planner->addRecipe('2026-09-14', $this->dinner, $this->recipe);
    $b = $this->planner->addFree('2026-09-14', $this->dinner, '  Fromage  ');

    expect($a)->type->toBe(MealType::Recipe)->servings->toBe(2.0)->position->toBe(1)
        ->and($a->date->toDateString())->toBe('2026-09-14')
        ->and($b)->type->toBe(MealType::Free)->free_text->toBe('Fromage')->position->toBe(2);
});

test('portions et texte invalides refusés', function () {
    expect(fn () => $this->planner->addRecipe('2026-09-14', $this->dinner, $this->recipe, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->planner->addFree('2026-09-14', $this->dinner, '   '))->toThrow(InvalidArgumentException::class);
});

test('calcul des restes et case suivante', function () {
    $meal = $this->planner->addRecipe('2026-09-14', $this->dinner, $this->recipe, 6);

    expect($this->planner->remainingLeftovers($meal))->toBe(4.0);

    $next = $this->planner->nextSlot('2026-09-14', $this->dinner);
    expect($next['date']->toDateString())->toBe('2026-09-15')->and($next['slot']->id)->toBe($this->lunch->id);

    $next = $this->planner->nextSlot('2026-09-15', $this->lunch);
    expect($next['date']->toDateString())->toBe('2026-09-15')->and($next['slot']->id)->toBe($this->dinner->id);

    $leftover = $this->planner->addLeftover('2026-09-15', $this->lunch, $meal);
    expect($leftover)->type->toBe(MealType::Leftover)->servings->toBe(2.0)->leftover_of_id->toBe($meal->id)
        ->and($leftover->label())->toBe('Restes : Lasagnes')
        ->and($this->planner->remainingLeftovers($meal))->toBe(2.0);

    $this->planner->addLeftover('2026-09-16', $this->lunch, $meal, 2);
    expect($this->planner->remainingLeftovers($meal))->toBe(0.0)
        ->and(fn () => $this->planner->addLeftover('2026-09-17', $this->lunch, $meal, 1))->toThrow(InvalidArgumentException::class);
});

test('pas de restes avant le repas d\'origine ni pour un repas sans surplus', function () {
    $meal = $this->planner->addRecipe('2026-09-15', $this->dinner, $this->recipe, 4);
    $small = $this->planner->addRecipe('2026-09-15', $this->lunch, $this->recipe, 2);

    expect(fn () => $this->planner->addLeftover('2026-09-14', $this->lunch, $meal))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->planner->addLeftover('2026-09-16', $this->lunch, $small))->toThrow(InvalidArgumentException::class);
});

test('restes disponibles sur les 7 derniers jours', function () {
    $recent = $this->planner->addRecipe('2026-09-14', $this->dinner, $this->recipe, 4);
    $this->planner->addRecipe('2026-09-01', $this->dinner, $this->recipe, 4); // trop ancien
    $this->planner->addRecipe('2026-09-14', $this->lunch, $this->recipe, 2);  // pas de surplus

    $available = $this->planner->availableLeftovers('2026-09-16');

    expect($available)->toHaveCount(1)
        ->and($available[0]['meal']->id)->toBe($recent->id)
        ->and($available[0]['remaining'])->toBe(2.0);
});

test('déplacement entre cases avec renumérotation', function () {
    $a = $this->planner->addRecipe('2026-09-14', $this->dinner, $this->recipe);
    $b = $this->planner->addFree('2026-09-14', $this->dinner, 'Salade');
    $c = $this->planner->addFree('2026-09-15', $this->lunch, 'Soupe');

    $this->planner->move($a, '2026-09-15', $this->lunch, 0);

    expect($a->fresh())->meal_slot_id->toBe($this->lunch->id)->position->toBe(1)
        ->and($a->fresh()->date->toDateString())->toBe('2026-09-15')
        ->and($c->fresh()->position)->toBe(2)
        ->and($b->fresh()->position)->toBe(1);

    $this->planner->move($a, '2026-09-15', $this->lunch, 5);
    expect($a->fresh()->position)->toBe(2)->and($c->fresh()->position)->toBe(1);
});

test('déplacement incohérent des restes refusé', function () {
    $meal = $this->planner->addRecipe('2026-09-15', $this->dinner, $this->recipe, 4);
    $leftover = $this->planner->addLeftover('2026-09-16', $this->lunch, $meal);

    expect(fn () => $this->planner->move($leftover, '2026-09-14', $this->lunch, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->planner->move($meal, '2026-09-17', $this->lunch, 0))->toThrow(InvalidArgumentException::class);
});

test('modification des portions protégée par les restes déjà placés', function () {
    $meal = $this->planner->addRecipe('2026-09-15', $this->dinner, $this->recipe, 6);
    $this->planner->addLeftover('2026-09-16', $this->lunch, $meal, 2);

    $this->planner->update($meal, ['servings' => 4, 'comment' => ' Inviter Julie ']);
    expect($meal->fresh())->servings->toBe(4.0)->comment->toBe('Inviter Julie')
        ->and(fn () => $this->planner->update($meal, ['servings' => 3]))->toThrow(InvalidArgumentException::class);
});

test('cuisiné, duplication et suppression (avec ses restes)', function () {
    $meal = $this->planner->addRecipe('2026-09-15', $this->dinner, $this->recipe, 4);
    $leftover = $this->planner->addLeftover('2026-09-16', $this->lunch, $meal);
    $other = $this->planner->addFree('2026-09-15', $this->dinner, 'Pain');

    $this->planner->toggleCooked($meal);
    expect($meal->fresh()->cooked_at)->not->toBeNull();

    $copy = $this->planner->duplicate($meal, '2026-09-18', $this->dinner);
    expect($copy)->recipe_id->toBe($this->recipe->id)->servings->toBe(4.0)->cooked_at->toBeNull()
        ->and(fn () => $this->planner->duplicate($leftover, '2026-09-18', $this->dinner))->toThrow(InvalidArgumentException::class);

    $this->planner->delete($meal);
    expect(PlannedMeal::find($leftover->id))->toBeNull()
        ->and($other->fresh()->position)->toBe(1);
});

test('copie d\'une semaine avec ses restes, puis remplacement', function () {
    $meal = $this->planner->addRecipe('2026-09-14', $this->dinner, $this->recipe, 4);
    $this->planner->addLeftover('2026-09-15', $this->lunch, $meal);
    $this->planner->addFree('2026-09-20', $this->lunch, 'Resto');
    $this->planner->toggleCooked($meal);

    $count = $this->planner->copyWeek(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-23'));
    expect($count)->toBe(3);

    $copies = $this->planner->mealsForWeek(Carbon::parse('2026-09-21'));
    $copiedMeal = $copies->firstWhere('type', MealType::Recipe);
    $copiedLeftover = $copies->firstWhere('type', MealType::Leftover);

    expect($copiedMeal->date->toDateString())->toBe('2026-09-21')
        ->and($copiedMeal->cooked_at)->toBeNull()
        ->and($copiedLeftover->leftover_of_id)->toBe($copiedMeal->id)
        ->and($copies->firstWhere('type', MealType::Free)->date->toDateString())->toBe('2026-09-27');

    $this->planner->addFree('2026-09-22', $this->dinner, 'À remplacer');
    $this->planner->copyWeek(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-21'), replace: true);

    expect($this->planner->mealsForWeek(Carbon::parse('2026-09-21')))->toHaveCount(3)
        ->and(fn () => $this->planner->copyWeek(Carbon::parse('2026-09-14'), Carbon::parse('2026-09-16')))->toThrow(InvalidArgumentException::class);
});

test('vider une semaine retire aussi les restes placés la semaine suivante', function () {
    $meal = $this->planner->addRecipe('2026-09-20', $this->dinner, $this->recipe, 4);
    $this->planner->addLeftover('2026-09-21', $this->lunch, $meal);
    $this->planner->addFree('2026-09-22', $this->lunch, 'Garder');

    expect($this->planner->clearWeek(Carbon::parse('2026-09-14')))->toBe(1)
        ->and(PlannedMeal::count())->toBe(1);
});

test('suggestions : jamais planifiées puis les plus anciennes, hors semaine en cours et archives', function () {
    $recent = Recipe::factory()->create(['title' => 'Récente']);
    $old = Recipe::factory()->create(['title' => 'Ancienne']);
    $never = Recipe::factory()->create(['title' => 'Jamais']);
    Recipe::factory()->archived()->create(['title' => 'Archivée']);

    $this->planner->addRecipe('2026-09-15', $this->dinner, $this->recipe);   // cette semaine : exclue
    $this->planner->addRecipe('2026-09-08', $this->dinner, $recent);
    $this->planner->addRecipe('2026-08-01', $this->dinner, $old);

    $titles = $this->planner->suggestions(Carbon::parse('2026-09-14'))->pluck('title')->all();
    expect($titles)->toBe(['Jamais', 'Ancienne', 'Récente']);

    $tag = Tag::factory()->create();
    $old->tags()->attach($tag);
    expect($this->planner->suggestions(Carbon::parse('2026-09-14'), tagIds: [$tag->id])->pluck('title')->all())->toBe(['Ancienne'])
        ->and($this->planner->suggestions(Carbon::parse('2026-09-14'))->firstWhere('title', 'Ancienne')->last_planned_on)->toStartWith('2026-08-01');
});
