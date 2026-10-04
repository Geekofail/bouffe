<?php

use App\Enums\MealType;
use App\Livewire\Dashboard;
use App\Livewire\Planner\MealPicker;
use App\Livewire\Planner\Week;
use App\Livewire\Recipes\Show;
use App\Livewire\Settings\MealSlots;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Planning\WeekPlanner;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00')); // mercredi
    $this->actingAs(User::factory()->create(['name' => 'Pierre']));
    $this->planner = app(WeekPlanner::class);
    $this->lunch = MealSlot::factory()->create(['name' => 'Déjeuner', 'sort_order' => 1]);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 2]);
    MealSlot::factory()->inactive()->create(['name' => 'Goûter', 'sort_order' => 3]);
    $this->recipe = Recipe::factory()->create(['title' => 'Lasagnes']);
});

test('la page affiche la semaine en cours avec ses créneaux actifs et ses repas', function () {
    $this->planner->addRecipe('2026-09-16', $this->dinner, $this->recipe, 4, 'avec salade');
    $this->planner->addFree('2026-09-17', $this->lunch, 'Restaurant');

    $this->get(route('planner.week'))
        ->assertOk()
        ->assertSee('Semaine du 14 septembre au 20 septembre 2026')
        ->assertSee('Déjeuner')->assertSee('Dîner')->assertDontSee('Goûter')
        ->assertSee('Lasagnes')->assertSee('avec salade')->assertSee('Restaurant')
        ->assertSee("Aujourd'hui", false);
});

test('navigation entre les semaines et semaine passée en paramètre', function () {
    Livewire::test(Week::class)
        ->assertSet('week', '2026-09-14')
        ->call('nextWeek')->assertSet('week', '2026-09-21')
        ->call('previousWeek')->call('previousWeek')->assertSet('week', '2026-09-07')
        ->call('currentWeek')->assertSet('week', '2026-09-14');

    Livewire::withQueryParams(['semaine' => '2026-10-01'])->test(Week::class)->assertSet('week', '2026-09-28');
    Livewire::withQueryParams(['semaine' => 'n-importe-quoi'])->test(Week::class)->assertOk();
});

test('ajouter une recette via le sélecteur, puis accepter la proposition de restes', function () {
    Livewire::test(MealPicker::class)
        ->call('open', '2026-09-16', $this->dinner->id)
        ->assertSet('show', true)
        ->assertSee('Lasagnes')              // suggestion
        ->set('servings', 4)
        ->call('pickRecipe', $this->recipe->id)
        ->assertSet('show', false)
        ->assertDispatched('meal-planned');

    $meal = PlannedMeal::sole();
    expect($meal)->servings->toBe(4.0)->type->toBe(MealType::Recipe);

    Livewire::test(Week::class)
        ->call('mealPlanned', $meal->id)
        ->assertSet('leftoverOffer.date', '2026-09-17')
        ->assertSet('leftoverOffer.slot_id', $this->lunch->id)
        ->assertSee('Il reste')
        ->call('acceptLeftoverOffer')
        ->assertSet('leftoverOffer', null)
        ->assertDispatched('notify');

    $leftover = PlannedMeal::where('type', 'leftover')->sole();
    expect($leftover->date->toDateString())->toBe('2026-09-17')->and($leftover->leftover_of_id)->toBe($meal->id);
});

test('pas de proposition de restes pour 2 portions', function () {
    $meal = $this->planner->addRecipe('2026-09-16', $this->dinner, $this->recipe, 2);

    Livewire::test(Week::class)->call('mealPlanned', $meal->id)->assertSet('leftoverOffer', null);
});

test('sélecteur : recherche, repas libre, restes, validation', function () {
    $source = $this->planner->addRecipe('2026-09-15', $this->dinner, $this->recipe, 4);
    Recipe::factory()->create(['title' => 'Soupe au pistou']);
    Recipe::factory()->archived()->create(['title' => 'Soupe archivée']);

    Livewire::test(MealPicker::class)
        ->call('open', '2026-09-16', $this->lunch->id)
        ->set('search', 'soupe')
        ->assertSee('Soupe au pistou')->assertDontSee('Soupe archivée')->assertDontSee('Lasagnes')
        ->set('tab', 'leftover')
        ->assertSee('2 portions restantes')
        ->call('pickLeftover', $source->id)
        ->assertDispatched('meal-planned');

    Livewire::test(MealPicker::class)
        ->call('open', '2026-09-16', $this->lunch->id)
        ->set('tab', 'free')
        ->call('pickFree')->assertHasErrors('freeText')
        ->call('pickFree', 'Chez la famille')->assertHasNoErrors()->assertDispatched('meal-planned');

    expect(PlannedMeal::where('free_text', 'Chez la famille')->exists())->toBeTrue()
        ->and(PlannedMeal::whereDate('date', '2026-09-16')->where('meal_slot_id', $this->lunch->id)->count())->toBe(2);
});

test('glisser-déposer un repas vers une autre case', function () {
    $meal = $this->planner->addRecipe('2026-09-16', $this->dinner, $this->recipe);

    Livewire::test(Week::class)->call('moveMeal', (string) $meal->id, 0, '2026-09-18|'.$this->lunch->id);

    expect($meal->fresh())->meal_slot_id->toBe($this->lunch->id)
        ->and($meal->fresh()->date->toDateString())->toBe('2026-09-18');
});

test('un déplacement interdit affiche un avertissement', function () {
    $meal = $this->planner->addRecipe('2026-09-16', $this->dinner, $this->recipe, 4);
    $leftover = $this->planner->addLeftover('2026-09-17', $this->lunch, $meal);

    Livewire::test(Week::class)
        ->call('moveMeal', $leftover->id, 0, '2026-09-15|'.$this->lunch->id)
        ->assertDispatched('notify', type: 'warning');

    expect($leftover->fresh()->date->toDateString())->toBe('2026-09-17');
});

test('détail d\'un repas : portions, commentaire, mangé, duplication, suppression', function () {
    $meal = $this->planner->addRecipe('2026-09-16', $this->dinner, $this->recipe, 2);

    Livewire::test(Week::class)
        ->call('selectMeal', $meal->id)
        ->assertSee('Voir la recette')
        ->set('editServings', 6)
        ->set('editComment', 'Inviter Julie')
        ->call('saveMeal')
        ->assertHasNoErrors()
        ->assertSet('selectedMealId', null)
        ->assertSet('leftoverOffer.remaining', 4)
        ->call('toggleCooked', $meal->id)
        ->call('selectMeal', $meal->id)
        ->call('duplicateMeal', '2026-09-19', $this->lunch->id)
        ->call('deleteMeal', $meal->id);

    expect(PlannedMeal::count())->toBe(1)
        ->and(PlannedMeal::sole())->servings->toBe(6.0)->comment->toBe('Inviter Julie')->cooked_at->toBeNull();
});

test('portions trop basses par rapport aux restes déjà placés : erreur sur le champ', function () {
    $meal = $this->planner->addRecipe('2026-09-16', $this->dinner, $this->recipe, 4);
    $this->planner->addLeftover('2026-09-17', $this->lunch, $meal);

    Livewire::test(Week::class)
        ->call('selectMeal', $meal->id)
        ->set('editServings', 2)
        ->call('saveMeal')
        ->assertHasErrors('editServings');

    expect($meal->fresh()->servings)->toBe(4.0);
});

test('copier et vider la semaine depuis la page', function () {
    $this->planner->addRecipe('2026-09-16', $this->dinner, $this->recipe);

    Livewire::test(Week::class)
        ->call('openCopy')
        ->assertSet('copyTarget', '2026-09-21')
        ->set('copyMode', 'add')
        ->call('copyWeek')
        ->assertSet('week', '2026-09-21')
        ->assertSee('Lasagnes')
        ->call('clearWeek');

    // On vérifie la semaine elle-même plutôt que le texte de la page : la liste des envies
    // propose toutes les recettes, « Lasagnes » comprise, sans que ce soit un repas planifié.
    expect(PlannedMeal::whereBetween('date', ['2026-09-21', '2026-09-27'])->count())->toBe(0)
        ->and(PlannedMeal::count())->toBe(1);
});

test('planifier depuis la fiche recette et statistiques', function () {
    $this->planner->addRecipe('2026-09-01', $this->dinner, $this->recipe);

    Livewire::test(Show::class, ['recipe' => $this->recipe])
        ->assertSee('Mangée 1 fois')
        ->call('openPlan')
        ->assertSet('planDate', '2026-09-16')
        ->assertSet('planSlotId', $this->dinner->id)
        ->set('planDate', '2026-09-24')
        ->set('planServings', 3)
        ->call('plan')
        ->assertRedirect(route('planner.week', ['semaine' => '2026-09-21']));

    expect(PlannedMeal::whereDate('date', '2026-09-24')->sole()->servings)->toBe(3.0);

    Livewire::test(Show::class, ['recipe' => $this->recipe])->assertSee('Prévue jeudi 24 septembre');
});

test('une recette planifiée ne peut pas être supprimée, un créneau utilisé non plus', function () {
    $this->planner->addRecipe('2026-09-16', $this->dinner, $this->recipe);

    Livewire::test(Show::class, ['recipe' => $this->recipe])->call('delete')->assertDispatched('notify', type: 'warning');
    Livewire::test(MealSlots::class)->call('delete', $this->dinner->id)->assertDispatched('notify', type: 'warning');

    expect($this->recipe->fresh())->not->toBeNull()->and($this->dinner->fresh())->not->toBeNull();
});

test('le tri « pas mangées depuis longtemps » met en premier les jamais planifiées', function () {
    $never = Recipe::factory()->create(['title' => 'Jamais']);
    $this->planner->addRecipe('2026-09-10', $this->dinner, $this->recipe);
    $old = Recipe::factory()->create(['title' => 'Ancienne']);
    $this->planner->addRecipe('2026-08-01', $this->dinner, $old);

    $titles = Livewire::test(\App\Livewire\Recipes\Index::class)->set('sort', 'forgotten')->viewData('recipes')->pluck('title')->all();

    expect($titles)->toBe(['Jamais', 'Ancienne', 'Lasagnes']);
});

test('le tableau de bord affiche le menu du jour et l\'avancement de la semaine', function () {
    $meal = $this->planner->addRecipe('2026-09-16', $this->dinner, $this->recipe);
    $this->planner->addFree('2026-09-17', $this->lunch, 'Restaurant');

    Livewire::test(Dashboard::class)
        ->assertSee('Lasagnes')
        ->assertSee('Demain')->assertSee('Restaurant')
        ->assertSee('2')->assertSee('/ 14')
        ->call('toggleCooked', $meal->id);

    expect($meal->fresh()->cooked_at)->not->toBeNull();
});
