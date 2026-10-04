<?php

use App\Enums\RestrictionType;
use App\Enums\UserRole;
use App\Livewire\Layout\Notifications;
use App\Livewire\Planner\MealPicker;
use App\Livewire\Planner\Week;
use App\Livewire\Settings\Household as HouseholdScreen;
use App\Models\Guest;
use App\Models\Ingredient;
use App\Models\MealReaction;
use App\Models\MealSlot;
use App\Models\PersonRestriction;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use App\Models\Wish;
use App\Services\Planning\HouseholdService;
use App\Services\Planning\MealFeedback;
use App\Services\Planning\OccasionService;
use App\Services\Planning\WeekFiller;
use App\Services\Planning\WeekPlanner;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00'));       // mercredi
    $this->pierre = User::factory()->create(['name' => 'Pierre', 'role' => UserRole::Full->value]);
    $this->monique = User::factory()->create(['name' => 'Monique', 'role' => UserRole::Full->value]);
    $this->actingAs($this->pierre);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 2]);
    $this->planner = app(WeekPlanner::class);
    $this->household = app(HouseholdService::class);
});

/** Recette contenant un ingrédient donné. */
function recipeContaining(Ingredient $ingredient, string $title): Recipe
{
    $recipe = Recipe::factory()->create(['title' => $title]);
    $recipe->ingredients()->create(['ingredient_id' => $ingredient->id, 'quantity' => 100, 'sort_order' => 1]);

    return $recipe;
}

/* ================================================================ 18.1 — préférences du foyer */

test('une contrainte du foyer déclenche les mêmes alertes qu\'un invité', function () {
    $arachide = Ingredient::factory()->create(['name' => 'Arachide']);
    $this->household->addRestriction($this->monique, RestrictionType::Allergy, $arachide->id);

    $satay = recipeContaining($arachide, 'Poulet satay');
    $this->planner->addRecipe('2026-09-17', $this->dinner, $satay);

    $conflicts = Livewire::withQueryParams(['semaine' => '2026-09-14'])->test(Week::class)->get('mealConflicts');
    $meal = PlannedMeal::query()->first();

    expect($conflicts)->toHaveKey($meal->id)
        ->and($conflicts[$meal->id]['level'])->toBe('danger')
        ->and($conflicts[$meal->id]['messages'][0])->toContain('Monique');
});

test('une personne absente de la case n\'apporte pas sa contrainte', function () {
    $arachide = Ingredient::factory()->create(['name' => 'Arachide']);
    $this->household->addRestriction($this->monique, RestrictionType::Allergy, $arachide->id);

    $satay = recipeContaining($arachide, 'Poulet satay');
    $this->planner->addRecipe('2026-09-17', $this->dinner, $satay);
    app(OccasionService::class)->save('2026-09-17', $this->dinner, ['absent_user_ids' => [$this->monique->id]]);

    $conflicts = Livewire::withQueryParams(['semaine' => '2026-09-14'])->test(Week::class)->get('mealConflicts');

    expect($conflicts)->toBe([]);
});

test('un régime du foyer signale les recettes sans la catégorie voulue', function () {
    $vege = Tag::create(['name' => 'Végétarien']);
    $this->household->addRestriction($this->pierre, RestrictionType::Diet, $vege->id);

    $steak = Recipe::factory()->create(['title' => 'Steak frites']);
    $this->planner->addRecipe('2026-09-17', $this->dinner, $steak);

    $conflicts = Livewire::withQueryParams(['semaine' => '2026-09-14'])->test(Week::class)->get('mealConflicts');
    $meal = PlannedMeal::query()->first();

    expect($conflicts[$meal->id]['level'])->toBe('warning')
        ->and($conflicts[$meal->id]['messages'][0])->toContain('Végétarien');
});

test('le remplissage automatique écarte une recette dangereuse pour le foyer', function () {
    $arachide = Ingredient::factory()->create(['name' => 'Arachide']);
    $this->household->addRestriction($this->monique, RestrictionType::Allergy, $arachide->id);

    $satay = recipeContaining($arachide, 'Poulet satay');
    $satay->update(['is_favorite' => true]);
    Recipe::factory()->count(6)->create();

    $proposals = app(WeekFiller::class)->propose(Carbon::parse('2026-09-21'), ['useStock' => false, 'pickFrom' => 1]);

    expect(collect($proposals)->pluck('recipe_id'))->not->toContain($satay->id);
});

test('les contraintes du foyer et des invités se cumulent', function () {
    $arachide = Ingredient::factory()->create(['name' => 'Arachide']);
    $gluten = Ingredient::factory()->create(['name' => 'Farine de blé']);

    $this->household->addRestriction($this->monique, RestrictionType::Allergy, $arachide->id);

    $guest = Guest::factory()->create(['name' => 'Julie']);
    $guest->restrictions()->create(['type' => RestrictionType::Allergy->value, 'ingredient_id' => $gluten->id]);

    $recipe = recipeContaining($arachide, 'Nouilles satay');
    $recipe->ingredients()->create(['ingredient_id' => $gluten->id, 'quantity' => 200, 'sort_order' => 2]);

    app(OccasionService::class)->save('2026-09-17', $this->dinner, ['guest_ids' => [$guest->id]]);
    $this->planner->addRecipe('2026-09-17', $this->dinner, $recipe);

    $conflicts = Livewire::withQueryParams(['semaine' => '2026-09-14'])->test(Week::class)->get('mealConflicts');
    $meal = PlannedMeal::query()->first();

    expect($conflicts[$meal->id]['items'])->toHaveCount(2)
        ->and(collect($conflicts[$meal->id]['items'])->pluck('guest')->sort()->values()->all())->toBe(['Julie', 'Monique']);
});

test('l\'écran du foyer ajoute et retire une contrainte', function () {
    $noix = Ingredient::factory()->create(['name' => 'Noix']);

    Livewire::test(HouseholdScreen::class)
        ->call('openRestriction', app(\App\Services\People\HouseholdPeople::class)->forUser($this->monique)->id)
        ->set('restrictionType', 'dislike')
        ->set('subjectId', $noix->id)
        ->set('note', 'sauf en gâteau')
        ->call('addRestriction')
        ->assertHasNoErrors();

    $restriction = PersonRestriction::query()->first();

    expect($restriction->person->user_id)->toBe($this->monique->id)
        ->and($restriction->type)->toBe(RestrictionType::Dislike)
        ->and($restriction->note)->toBe('sauf en gâteau');

    Livewire::test(HouseholdScreen::class)->call('removeRestriction', $restriction->id);
    expect(PersonRestriction::count())->toBe(0);
});

test('la même contrainte ne peut pas être ajoutée deux fois', function () {
    $noix = Ingredient::factory()->create(['name' => 'Noix']);
    $this->household->addRestriction($this->monique, RestrictionType::Allergy, $noix->id);

    Livewire::test(HouseholdScreen::class)
        ->call('openRestriction', app(\App\Services\People\HouseholdPeople::class)->forUser($this->monique)->id)
        ->set('restrictionType', 'allergy')
        ->set('subjectId', $noix->id)
        ->call('addRestriction')
        ->assertHasErrors('subjectId');
});

/* ================================================================ 14.7 / 18.3 — qui cuisine */

test('un repas s\'attribue à quelqu\'un, ou aux deux', function () {
    $meal = $this->planner->addRecipe('2026-09-17', $this->dinner, Recipe::factory()->create());

    $component = Livewire::withQueryParams(['semaine' => '2026-09-14'])->test(Week::class)
        ->call('selectMeal', $meal->id)
        ->assertSet('editCook', '')
        ->set('editCook', (string) $this->monique->id)
        ->call('saveMeal');

    expect($meal->fresh()->cook_user_id)->toBe($this->monique->id)
        ->and($meal->fresh()->cook_together)->toBeFalse()
        ->and($meal->fresh()->cookLabel())->toBe('Monique');

    $component->call('selectMeal', $meal->id)->set('editCook', 'together')->call('saveMeal');

    expect($meal->fresh()->cook_user_id)->toBeNull()
        ->and($meal->fresh()->cook_together)->toBeTrue()
        ->and($meal->fresh()->cookLabel())->toBe('Ensemble');
});

test('le planning compte les repas de chacun', function () {
    $a = $this->planner->addRecipe('2026-09-16', $this->dinner, Recipe::factory()->create());
    $b = $this->planner->addRecipe('2026-09-17', $this->dinner, Recipe::factory()->create());
    $c = $this->planner->addRecipe('2026-09-18', $this->dinner, Recipe::factory()->create());
    $this->planner->addRecipe('2026-09-19', $this->dinner, Recipe::factory()->create());

    $a->update(['cook_user_id' => $this->pierre->id]);
    $b->update(['cook_user_id' => $this->monique->id]);
    $c->update(['cook_together' => true]);

    $counts = Livewire::withQueryParams(['semaine' => '2026-09-14'])->test(Week::class)->get('cookCounts');

    expect($counts)->toMatchArray(['mine' => 1, 'together' => 1, 'others' => 1, 'unassigned' => 1, 'total' => 4]);
});

test('le filtre « mes repas » se retient dans l\'adresse', function () {
    Livewire::test(Week::class)
        ->assertSet('mineOnly', false)
        ->call('toggleMine')
        ->assertSet('mineOnly', true);
});

/* ================================================================ 18.4 — réactions */

test('on réagit à un repas mangé, et un second clic annule', function () {
    $recipe = Recipe::factory()->create(['title' => 'Chili']);
    $meal = $this->planner->addRecipe('2026-09-16', $this->dinner, $recipe);
    $this->planner->toggleCooked($meal);

    $feedback = app(MealFeedback::class);
    $feedback->toggle($meal->fresh(), $this->pierre, 1);

    expect(MealReaction::count())->toBe(1)
        ->and($feedback->forRecipe($recipe))->toMatchArray(['likes' => 1, 'dislikes' => 0, 'balance' => 1]);

    $feedback->toggle($meal->fresh(), $this->pierre, 1);
    expect(MealReaction::count())->toBe(0);

    $feedback->toggle($meal->fresh(), $this->pierre, -1);
    expect($feedback->forRecipe($recipe))->toMatchArray(['likes' => 0, 'dislikes' => 1, 'balance' => -1]);
});

test('un repas pas encore mangé ne reçoit pas de réaction', function () {
    $meal = $this->planner->addRecipe('2026-09-17', $this->dinner, Recipe::factory()->create());

    expect(fn () => app(MealFeedback::class)->react($meal, $this->pierre, 1))
        ->toThrow(InvalidArgumentException::class, 'mangé');
});

test('la réaction sur des restes compte pour la recette d\'origine', function () {
    $recipe = Recipe::factory()->create(['title' => 'Chili', 'servings' => 6]);
    $source = $this->planner->addRecipe('2026-09-16', $this->dinner, $recipe, 6);
    $leftover = $this->planner->addLeftover('2026-09-17', $this->dinner, $source, 2);

    $this->planner->toggleCooked($leftover);
    app(MealFeedback::class)->toggle($leftover->fresh(), $this->pierre, 1);

    expect(app(MealFeedback::class)->forRecipe($recipe)['likes'])->toBe(1);
});

test('une recette qui a déplu est moins proposée', function () {
    $recipe = Recipe::factory()->create(['title' => 'Chou-fleur vapeur', 'is_favorite' => true]);
    Recipe::factory()->count(8)->create();

    $meal = $this->planner->addRecipe('2026-08-10', $this->dinner, $recipe);   // assez ancien pour être reproposable
    $this->planner->toggleCooked($meal);
    app(MealFeedback::class)->toggle($meal->fresh(), $this->pierre, -1);
    app(MealFeedback::class)->toggle($meal->fresh(), $this->monique, -1);

    $proposals = app(WeekFiller::class)->propose(Carbon::parse('2026-09-21'), ['useStock' => false, 'pickFrom' => 1]);
    $found = collect($proposals)->firstWhere('recipe_id', $recipe->id);

    expect($found === null || in_array('n\'avait pas plu', $found['warnings'], true))->toBeTrue();
});

test('la cloche propose de réagir aux repas mangés récemment', function () {
    $recipe = Recipe::factory()->create(['title' => 'Blanquette']);
    $meal = $this->planner->addRecipe('2026-09-15', $this->dinner, $recipe);
    $this->planner->toggleCooked($meal);

    Livewire::test(Notifications::class)
        ->call('show')
        ->assertSee('C\'était bien ?', false)
        ->assertSee('Blanquette')
        ->call('react', $meal->id, 1);

    expect(MealReaction::query()->where('user_id', $this->pierre->id)->count())->toBe(1);
});

/* ================================================================ 18.2 — envies partagées */

test('l\'envie de l\'autre personne apparaît dans la cloche', function () {
    Wish::create(['user_id' => $this->monique->id, 'text' => 'Lasagnes']);
    Wish::create(['user_id' => $this->pierre->id, 'text' => 'Raclette']);

    $component = Livewire::test(Notifications::class)->call('show');

    expect($component->get('newWishes'))->toHaveCount(1);
    $component->assertSee('Monique')->assertSee('Lasagnes');

    // Une fois vue, l'envie ne revient plus.
    $component->call('markWishesSeen');
    expect(Livewire::test(Notifications::class)->call('show')->get('newWishes'))->toHaveCount(0);
});

/* ================================================================ 18.5 — membres et rôles */

test('un membre se crée avec son rôle', function () {
    Livewire::test(HouseholdScreen::class)
        ->call('openMember')
        ->set('name', 'Léo')
        ->set('email', 'LEO@exemple.lu')
        ->set('password', 'motdepasse')
        ->set('role', UserRole::Viewer->value)
        ->call('addMember')
        ->assertHasNoErrors();

    $leo = User::firstWhere('email', 'leo@exemple.lu');

    expect($leo)->not->toBeNull()
        ->and($leo->name)->toBe('Léo')
        ->and($leo->role)->toBe(UserRole::Viewer)
        ->and($leo->canEdit())->toBeFalse();
});

test('on ne peut pas retirer ses propres droits ni le dernier responsable du foyer', function () {
    // Lot 24 : les comptes « complets » d'avant les foyers sont devenus responsables du foyer.
    expect($this->pierre->fresh()->role)->toBe(UserRole::Owner);

    $this->monique->setRoleIn(UserRole::Viewer);

    Livewire::test(HouseholdScreen::class)->call('changeRole', $this->pierre->id, UserRole::Viewer->value);

    expect($this->pierre->fresh()->role)->toBe(UserRole::Owner);

    // Même en s'y prenant depuis l'autre compte : il faut garder un responsable.
    $this->monique->setRoleIn(UserRole::Owner);
    $this->actingAs($this->monique->fresh());

    Livewire::test(HouseholdScreen::class)->call('changeRole', $this->pierre->id, UserRole::Full->value);
    expect($this->pierre->fresh()->role)->toBe(UserRole::Full);

    Livewire::test(HouseholdScreen::class)->call('changeRole', $this->monique->id, UserRole::Viewer->value);
    expect($this->monique->fresh()->role)->toBe(UserRole::Owner);
});

test('un compte en consultation ne peut pas ouvrir les pages d\'édition', function () {
    $leo = User::factory()->create(['name' => 'Léo', 'role' => UserRole::Viewer->value]);
    $this->actingAs($leo);

    $this->get(route('recipes.create'))->assertRedirect(route('dashboard'));
    $this->get(route('planner.fill'))->assertRedirect(route('dashboard'));
    $this->get(route('settings.ingredients'))->assertRedirect(route('dashboard'));

    // Il consulte normalement.
    $this->get(route('recipes.index'))->assertOk();
    $this->get(route('planner.week'))->assertOk();
    $this->get(route('shopping.index'))->assertOk();
    $this->get(route('settings.household'))->assertOk();
});

test('un compte en consultation ne modifie pas le planning', function () {
    $leo = User::factory()->create(['name' => 'Léo', 'role' => UserRole::Viewer->value]);
    $meal = $this->planner->addRecipe('2026-09-17', $this->dinner, Recipe::factory()->create());

    $this->actingAs($leo);

    Livewire::withQueryParams(['semaine' => '2026-09-14'])->test(Week::class)->call('deleteMeal', $meal->id);
    expect(PlannedMeal::count())->toBe(1);

    Livewire::test(MealPicker::class)
        ->call('open', '2026-09-18', $this->dinner->id)
        ->call('pickFree', 'Resto');

    expect(PlannedMeal::count())->toBe(1);
});

test('un compte en consultation peut réagir à un repas', function () {
    $leo = User::factory()->create(['name' => 'Léo', 'role' => UserRole::Viewer->value]);
    $meal = $this->planner->addRecipe('2026-09-16', $this->dinner, Recipe::factory()->create());
    $this->planner->toggleCooked($meal);

    $this->actingAs($leo);
    Livewire::test(Notifications::class)->call('show')->call('react', $meal->id, 1);

    expect(MealReaction::query()->where('user_id', $leo->id)->count())->toBe(1);
});
