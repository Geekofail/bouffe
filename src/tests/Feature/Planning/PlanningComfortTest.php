<?php

use App\Livewire\Planner\FillWeek;
use App\Livewire\Planner\MealPicker;
use App\Livewire\Planner\Week;
use App\Livewire\Planner\WishBox;
use App\Livewire\Recipes\Show as RecipeShow;
use App\Livewire\Settings\Planning as PlanningSettings;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use App\Models\Wish;
use App\Services\Planning\PlanningRules;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00'));
    $this->actingAs($this->pierre = User::factory()->create(['name' => 'Pierre']));
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 2]);
    Settings::flush();
});

/* ================================================================ 14.5 — envies */

test('une envie s\'ajoute par son texte et se rattache à une recette si elle existe', function () {
    $recipe = Recipe::factory()->create(['title' => 'Curry de légumes']);

    Livewire::test(WishBox::class)->set('text', 'Raclette')->call('add')->assertHasNoErrors();
    Livewire::test(WishBox::class)->set('text', 'curry de legumes')->call('add')->assertHasNoErrors();

    $wishes = Wish::query()->open()->get();

    expect($wishes)->toHaveCount(2)
        ->and($wishes->firstWhere('text', 'Raclette')->recipe_id)->toBeNull()
        ->and($wishes->firstWhere('recipe_id', $recipe->id))->not->toBeNull()
        ->and($wishes->first()->user_id)->toBe($this->pierre->id);
});

test('une envie se retire ou se marque comme faite', function () {
    Livewire::test(WishBox::class)->set('text', 'Raclette')->call('add');
    $wish = Wish::query()->first();

    Livewire::test(WishBox::class)->call('done', $wish->id);
    expect(Wish::query()->open()->count())->toBe(0);

    Livewire::test(WishBox::class)->call('remove', $wish->id);
    expect(Wish::count())->toBe(0);
});

test('la fiche recette ajoute et retire la recette des envies', function () {
    $recipe = Recipe::factory()->create(['title' => 'Tarte aux pommes']);

    $component = Livewire::test(RecipeShow::class, ['recipe' => $recipe])
        ->assertSet('isWished', false)
        ->call('addToWishes')
        ->assertSet('isWished', true);

    expect(Wish::query()->open()->where('recipe_id', $recipe->id)->count())->toBe(1);

    // Deux clics ne créent pas deux envies.
    $component->call('addToWishes');
    expect(Wish::query()->open()->where('recipe_id', $recipe->id)->count())->toBe(1);

    $component->call('removeFromWishes')->assertSet('isWished', false);
    expect(Wish::query()->open()->count())->toBe(0);
});

test('une envie se place sur une case depuis le choix du repas', function () {
    $recipe = Recipe::factory()->create(['title' => 'Raclette']);
    $wish = Wish::create(['user_id' => $this->pierre->id, 'recipe_id' => $recipe->id]);

    Livewire::test(MealPicker::class)
        ->call('open', '2026-09-18', $this->dinner->id)
        ->set('tab', 'wish')
        ->assertSee('Raclette')
        ->call('pickWish', $wish->id);

    expect(PlannedMeal::query()->whereDate('date', '2026-09-18')->first()->recipe_id)->toBe($recipe->id)
        ->and($wish->fresh()->planned_at)->not->toBeNull();
});

test('une envie écrite devient un repas libre', function () {
    $wish = Wish::create(['user_id' => $this->pierre->id, 'text' => 'Resto japonais']);

    Livewire::test(MealPicker::class)
        ->call('open', '2026-09-18', $this->dinner->id)
        ->set('tab', 'wish')
        ->call('pickWish', $wish->id);

    $meal = PlannedMeal::query()->whereDate('date', '2026-09-18')->first();

    expect($meal->free_text)->toBe('Resto japonais')
        ->and($wish->fresh()->planned_at)->not->toBeNull();
});

/* ================================================================ 14.4 — règles de la semaine */

test('l\'écran des règles enregistre les temps, les quotas et l\'heure des rappels', function () {
    $poisson = Tag::create(['name' => 'Poisson']);

    Livewire::test(PlanningSettings::class)
        ->set('slotRules.'.$this->dinner->id.'.max_minutes', 30)
        ->set('slotRules.'.$this->dinner->id.'.weeknights_only', true)
        ->call('addQuota')
        ->set('quotas.0.tag_id', $poisson->id)
        ->set('quotas.0.max', 1)
        ->set('reminderHour', 17)
        ->call('save')
        ->assertHasNoErrors();

    $rules = app(PlanningRules::class)->all();

    expect($rules['slots'][$this->dinner->id]['max_minutes'])->toBe(30)
        ->and($rules['quotas'][0]['tag_id'])->toBe($poisson->id)
        ->and($rules['quotas'][0]['max'])->toBe(1)
        ->and(Settings::int('planning.reminder_hour'))->toBe(17);
});

test('le bilan des règles apparaît sur le planning', function () {
    $vege = Tag::create(['name' => 'Végétarien']);
    app(PlanningRules::class)->save(['slots' => [], 'quotas' => [['tag_id' => $vege->id, 'min' => 2, 'max' => null]]]);

    $recipe = Recipe::factory()->create(['title' => 'Dahl de lentilles']);
    $recipe->tags()->attach($vege);
    app(\App\Services\Planning\WeekPlanner::class)->addRecipe('2026-09-16', $this->dinner, $recipe);

    Livewire::withQueryParams(['semaine' => '2026-09-14'])->test(Week::class)
        ->assertSee('Végétarien : 1 sur 2 souhaités');
});

test('un temps maximum trop court est refusé', function () {
    Livewire::test(PlanningSettings::class)
        ->set('slotRules.'.$this->dinner->id.'.max_minutes', 2)
        ->call('save')
        ->assertHasErrors('slotRules.'.$this->dinner->id.'.max_minutes');
});

/* ================================================================ 14.1 — écran de remplissage */

test('l\'écran de remplissage propose, verrouille, relance et valide', function () {
    Recipe::factory()->count(10)->create();

    $component = Livewire::withQueryParams(['semaine' => '2026-09-21'])->test(FillWeek::class)
        ->set('useStock', false);

    $proposals = $component->get('proposals');
    expect($proposals)->toHaveCount(7);

    $key = $proposals[0]['key'];
    $before = $proposals[0]['recipe_id'];

    $component->call('toggleLock', $key);
    expect(collect($component->get('proposals'))->firstWhere('key', $key)['locked'])->toBeTrue();

    // Une relance générale garde la case verrouillée.
    $component->call('regenerate');
    expect(collect($component->get('proposals'))->firstWhere('key', $key)['recipe_id'])->toBe($before);

    // Retirer une case laisse le planning vide à cet endroit.
    $other = collect($component->get('proposals'))->firstWhere('key', '!=', $key)['key'];
    $component->call('remove', $other);
    expect($component->get('proposals'))->toHaveCount(6);

    $component->call('apply')->assertRedirect();

    expect(PlannedMeal::query()->whereBetween('date', ['2026-09-21', '2026-09-27'])->count())->toBe(6);
});

test('relancer une case propose une autre recette', function () {
    Recipe::factory()->count(12)->create();

    $component = Livewire::withQueryParams(['semaine' => '2026-09-21'])->test(FillWeek::class)->set('useStock', false);
    $first = $component->get('proposals')[0];

    $component->call('reroll', $first['key']);
    $after = collect($component->get('proposals'))->firstWhere('key', $first['key']);

    expect($after['recipe_id'])->not->toBe($first['recipe_id']);
});

test('sans recette, l\'écran de remplissage le dit sans planter', function () {
    Livewire::withQueryParams(['semaine' => '2026-09-21'])->test(FillWeek::class)
        ->assertSet('proposals', [])
        ->assertSee('Aucune proposition')
        ->call('apply')
        ->assertNoRedirect();
});

/* ================================================================ 14.9 — vue liste */

test('le planning bascule entre grille et liste', function () {
    $recipe = Recipe::factory()->create(['title' => 'Blanquette de veau']);
    app(\App\Services\Planning\WeekPlanner::class)->addRecipe('2026-09-16', $this->dinner, $recipe);

    Livewire::withQueryParams(['semaine' => '2026-09-14'])->test(Week::class)
        ->assertSet('view', 'grille')
        ->call('toggleView')
        ->assertSet('view', 'liste')
        ->assertSee('Blanquette de veau')
        ->assertSee('mercredi 16 septembre')
        ->call('toggleView')
        ->assertSet('view', 'grille');
});
