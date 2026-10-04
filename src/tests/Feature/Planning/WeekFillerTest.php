<?php

use App\Enums\MealType;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use App\Models\Wish;
use App\Services\Planning\PlanningRules;
use App\Services\Planning\WeekFiller;
use App\Services\Planning\WeekPlanner;
use App\Support\Settings;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00'));          // mercredi
    $this->monday = Carbon::parse('2026-09-21');                 // semaine suivante, vide
    $this->actingAs($this->pierre = User::factory()->create(['name' => 'Pierre']));
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 2]);
    $this->filler = app(WeekFiller::class);
    $this->planner = app(WeekPlanner::class);
    Settings::flush();
});

/** Propositions indexées par clé de case. */
function byKey(array $proposals): array
{
    return collect($proposals)->keyBy('key')->all();
}

test('les sept cases vides d\'un créneau reçoivent chacune une recette différente', function () {
    Recipe::factory()->count(10)->create();

    $proposals = $this->filler->propose($this->monday, ['useStock' => false, 'pickFrom' => 1]);

    expect($proposals)->toHaveCount(7);
    expect(collect($proposals)->pluck('recipe_id')->unique())->toHaveCount(7);
    expect(collect($proposals)->pluck('date')->all())->toBe([
        '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25', '2026-09-26', '2026-09-27',
    ]);
});

test('une case déjà occupée est laissée telle quelle', function () {
    Recipe::factory()->count(8)->create();
    $this->planner->addRecipe('2026-09-22', $this->dinner, Recipe::factory()->create(['title' => 'Déjà là']));

    $proposals = byKey($this->filler->propose($this->monday, ['useStock' => false, 'pickFrom' => 1]));

    expect($proposals)->toHaveCount(6)
        ->and($proposals)->not->toHaveKey('2026-09-22|'.$this->dinner->id);
});

test('une recette déjà prévue dans la semaine n\'est pas reproposée', function () {
    $lasagnes = Recipe::factory()->create(['title' => 'Lasagnes', 'is_favorite' => true]);
    Recipe::factory()->count(6)->create();
    $this->planner->addRecipe('2026-09-21', $this->dinner, $lasagnes);

    $proposals = $this->filler->propose($this->monday, ['useStock' => false, 'pickFrom' => 1]);

    expect(collect($proposals)->pluck('recipe_id'))->not->toContain($lasagnes->id);
});

test('une recette mangée dans les quatorze derniers jours est écartée', function () {
    $recent = Recipe::factory()->create(['title' => 'Soupe de courge', 'is_favorite' => true]);
    Recipe::factory()->count(6)->create();

    // Mangée l'avant-veille : trop récente pour toute la semaine du 21.
    $this->planner->addRecipe('2026-09-15', $this->dinner, $recent);

    $proposals = $this->filler->propose($this->monday, ['useStock' => false, 'pickFrom' => 1]);

    expect(collect($proposals)->pluck('recipe_id'))->not->toContain($recent->id);
});

test('une envie passe devant les autres recettes', function () {
    Recipe::factory()->count(8)->create(['is_favorite' => true]);
    $raclette = Recipe::factory()->create(['title' => 'Raclette']);
    Wish::create(['user_id' => $this->pierre->id, 'recipe_id' => $raclette->id]);

    $proposals = $this->filler->propose($this->monday, ['useStock' => false, 'pickFrom' => 1]);
    $first = $proposals[0];

    expect($first['recipe_id'])->toBe($raclette->id)
        ->and($first['reasons'])->toContain('envie de Pierre')
        ->and($first['wish_id'])->not->toBeNull();
});

test('les envies peuvent être ignorées', function () {
    Recipe::factory()->count(8)->create(['is_favorite' => true]);
    $raclette = Recipe::factory()->create(['title' => 'Raclette']);
    Wish::create(['user_id' => $this->pierre->id, 'recipe_id' => $raclette->id]);

    $proposals = $this->filler->propose($this->monday, ['useStock' => false, 'useWishes' => false, 'pickFrom' => 1]);

    expect(collect($proposals)->firstWhere('recipe_id', $raclette->id)['wish_id'] ?? null)->toBeNull();
});

test('le temps maximum du créneau écarte les recettes trop longues', function () {
    Recipe::factory()->create(['title' => 'Bœuf bourguignon', 'prep_minutes' => 30, 'cook_minutes' => 180, 'is_favorite' => true]);
    Recipe::factory()->count(6)->create(['prep_minutes' => 10, 'cook_minutes' => 10]);

    app(PlanningRules::class)->save([
        'slots' => [$this->dinner->id => ['max_minutes' => 30, 'weeknights_only' => true]],
        'quotas' => [],
    ]);

    $proposals = byKey($this->filler->propose($this->monday, ['useStock' => false, 'pickFrom' => 1]));

    // Du lundi au jeudi : limité à 30 min. Le week-end : pas de limite.
    expect($proposals['2026-09-21|'.$this->dinner->id]['title'])->not->toBe('Bœuf bourguignon');

    $long = collect($proposals)->firstWhere('title', 'Bœuf bourguignon');
    expect($long === null || in_array($long['date'], ['2026-09-25', '2026-09-26', '2026-09-27'], true))->toBeTrue();
});

test('un quota dépassé est pénalisé et signalé', function () {
    $poisson = Tag::create(['name' => 'Poisson']);
    $saumon = Recipe::factory()->create(['title' => 'Saumon au four', 'is_favorite' => true]);
    $cabillaud = Recipe::factory()->create(['title' => 'Cabillaud vapeur', 'is_favorite' => true]);
    $saumon->tags()->attach($poisson);
    $cabillaud->tags()->attach($poisson);
    Recipe::factory()->count(6)->create();

    app(PlanningRules::class)->save([
        'slots' => [],
        'quotas' => [['tag_id' => $poisson->id, 'min' => null, 'max' => 1]],
    ]);

    $proposals = $this->filler->propose($this->monday, ['useStock' => false, 'pickFrom' => 1]);
    $fish = collect($proposals)->filter(fn ($p) => in_array($p['recipe_id'], [$saumon->id, $cabillaud->id], true));

    expect($fish)->toHaveCount(1);
});

test('une recette dangereuse pour un invité de la case n\'est pas proposée', function () {
    $guest = \App\Models\Guest::factory()->create(['name' => 'Julie']);
    $arachide = \App\Models\Ingredient::factory()->create(['name' => 'Arachide']);
    $guest->restrictions()->create(['type' => \App\Enums\RestrictionType::Allergy->value, 'ingredient_id' => $arachide->id]);

    $satay = Recipe::factory()->create(['title' => 'Poulet satay', 'is_favorite' => true]);
    $satay->ingredients()->create(['ingredient_id' => $arachide->id, 'quantity' => 50, 'sort_order' => 1]);
    Recipe::factory()->count(6)->create();

    app(\App\Services\Planning\OccasionService::class)->save('2026-09-21', $this->dinner, ['guest_ids' => [$guest->id]]);

    $proposals = byKey($this->filler->propose($this->monday, ['useStock' => false, 'pickFrom' => 1]));

    expect($proposals['2026-09-21|'.$this->dinner->id]['recipe_id'])->not->toBe($satay->id);
});

test('les cases verrouillées sont conservées et les autres recalculées', function () {
    Recipe::factory()->count(12)->create();

    $first = $this->filler->propose($this->monday, ['useStock' => false, 'pickFrom' => 1]);
    $locked = array_map(fn ($p) => [...$p, 'locked' => true], array_slice($first, 0, 2));

    $second = byKey($this->filler->propose($this->monday, ['useStock' => false, 'pickFrom' => 1, 'keep' => $locked]));

    expect($second[$locked[0]['key']]['recipe_id'])->toBe($locked[0]['recipe_id'])
        ->and($second[$locked[1]['key']]['recipe_id'])->toBe($locked[1]['recipe_id']);
});

test('une recette écartée n\'est pas reproposée dans la même case', function () {
    Recipe::factory()->count(10)->create();

    $first = $this->filler->propose($this->monday, ['useStock' => false, 'pickFrom' => 1]);
    $cell = $first[0];

    $second = byKey($this->filler->propose($this->monday, [
        'useStock' => false, 'pickFrom' => 1,
        'avoid' => [$cell['key'] => [$cell['recipe_id']]],
    ]));

    expect($second[$cell['key']]['recipe_id'])->not->toBe($cell['recipe_id']);
});

test('la validation enregistre les repas et marque les envies comme placées', function () {
    Recipe::factory()->count(8)->create();
    $raclette = Recipe::factory()->create(['title' => 'Raclette']);
    $wish = Wish::create(['user_id' => $this->pierre->id, 'recipe_id' => $raclette->id]);

    $proposals = $this->filler->propose($this->monday, ['useStock' => false, 'pickFrom' => 1]);
    $count = $this->filler->apply($proposals);

    expect($count)->toBe(count($proposals))
        ->and(PlannedMeal::query()->whereBetween('date', ['2026-09-21', '2026-09-27'])->count())->toBe(count($proposals))
        ->and($wish->fresh()->planned_at)->not->toBeNull()
        ->and($wish->fresh()->planned_meal_id)->not->toBeNull();
});

test('une recette qui laisse des portions place ses restes le lendemain', function () {
    // Foyer de 2 personnes, recette prévue pour 6 portions : 4 restent.
    $grande = Recipe::factory()->create(['title' => 'Chili en grande quantité', 'servings' => 6, 'is_favorite' => true]);
    Recipe::factory()->count(6)->create();

    $proposals = byKey($this->filler->propose($this->monday, ['useStock' => false, 'pickFrom' => 1]));
    $monday = $proposals['2026-09-21|'.$this->dinner->id];

    expect($monday['servings'])->toBe(6.0);

    $tuesday = $proposals['2026-09-22|'.$this->dinner->id];
    expect($tuesday['type'])->toBe(MealType::Leftover->value)
        ->and($tuesday['leftover_key'])->toBe($monday['key'])
        ->and($tuesday['title'])->toStartWith('Restes : ');

    $this->filler->apply(array_values($proposals));

    $leftover = PlannedMeal::query()->where('type', MealType::Leftover->value)->whereDate('date', '2026-09-22')->first();
    expect($leftover)->not->toBeNull()
        ->and($leftover->leftoverOf->recipe_id)->toBe($monday['recipe_id']);
});

test('sans recette disponible, aucune proposition n\'est faite', function () {
    $proposals = $this->filler->propose($this->monday, ['useStock' => false, 'pickFrom' => 1]);

    expect($proposals)->toBe([]);
});
