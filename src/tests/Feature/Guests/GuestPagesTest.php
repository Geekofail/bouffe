<?php

use App\Livewire\Guests\GuestEditor;
use App\Livewire\Guests\Index;
use App\Livewire\Guests\Show as GuestShow;
use App\Livewire\Planner\MealPicker;
use App\Livewire\Planner\OccasionEditor;
use App\Livewire\Planner\Week;
use App\Livewire\Settings\Ingredients;
use App\Livewire\Settings\MealSlots;
use App\Livewire\Shopping\Index as ShoppingIndex;
use App\Models\Aisle;
use App\Models\Guest;
use App\Models\MealOccasion;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Tag;
use App\Models\User;
use App\Services\Planning\GuestManager;
use App\Services\Planning\OccasionService;
use App\Services\Planning\WeekPlanner;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00')); // mercredi
    $this->pierre = User::factory()->create(['name' => 'Pierre']);
    $this->monique = User::factory()->create(['name' => 'Monique']);
    $this->actingAs($this->pierre);
    Aisle::factory()->create(['name' => 'Divers']);
    $this->planner = app(WeekPlanner::class);
    $this->occasions = app(OccasionService::class);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 2]);
    $this->lunch = MealSlot::factory()->create(['name' => 'Déjeuner', 'sort_order' => 1]);

    $this->julie = app(GuestManager::class)->save(null, ['name' => 'Julie', 'group_name' => 'Amis'], [['type' => 'allergy', 'ingredient' => 'Noix']]);
    $this->paul = app(GuestManager::class)->save(null, ['name' => 'Paul', 'group_name' => 'Amis']);
});

/* ------------------------------------------------------------------ Carnet */

test('le carnet liste les invités par groupe avec leurs contraintes', function () {
    Guest::factory()->create(['name' => 'Mamie', 'group_name' => 'Famille']);
    Guest::factory()->create(['name' => 'Voisin']);
    Guest::factory()->create(['name' => 'Ancien', 'archived_at' => now()]);

    Livewire::test(Index::class)
        ->assertSeeInOrder(['Amis', 'Julie', 'Paul', 'Famille', 'Mamie', 'Sans groupe', 'Voisin'])
        ->assertSee('Allergie · Noix')
        ->assertDontSee('Ancien')
        ->set('showArchived', true)->assertSee('Ancien')
        ->set('search', 'fam')->assertSee('Mamie')->assertDontSee('Julie');
});

test('créer un invité avec ses contraintes depuis la fenêtre', function () {
    $vege = Tag::factory()->create(['name' => 'Végétarien']);

    Livewire::test(GuestEditor::class)
        ->call('open', null, 'Léa')
        ->assertSet('name', 'Léa')
        ->set('groupName', 'Famille')
        ->set('isChild', true)
        ->call('addRestriction', 'allergy')
        ->set('restrictions.0.ingredient', 'Kiwi')
        ->call('addRestriction', 'diet')
        ->set('restrictions.1.tag_id', $vege->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('show', false)
        ->assertDispatched('guest-saved')
        ->assertDispatched('notify', message: '« Léa » ajouté au carnet. Nouvel ingrédient créé : Kiwi (rayon Divers).');

    $lea = Guest::firstWhere('name', 'Léa');
    expect($lea->is_child)->toBeTrue()->and($lea->restrictions)->toHaveCount(2);

    Livewire::test(GuestEditor::class)
        ->call('open', $lea->id)
        ->assertSet('restrictions.0.ingredient', 'Kiwi')
        ->call('removeRestriction', 0)
        ->call('addRestriction', 'dislike')
        ->call('save')
        ->assertSee('indiquez l\'ingrédient');
});

test('la fiche invité montre l\'historique et empêche de supprimer un invité déjà reçu', function () {
    $this->occasions->save('2026-09-12', $this->dinner, ['guest_ids' => [$this->julie->id], 'title' => 'Crémaillère']);
    $this->planner->addRecipe('2026-09-12', $this->dinner, dish('Paella', ['Riz']));

    Livewire::test(GuestShow::class, ['guest' => $this->julie])
        ->assertSee('Crémaillère')->assertSee('Paella')->assertSee('Allergie')->assertSee('Noix')
        ->call('delete')
        ->assertDispatched('notify', type: 'warning')
        ->call('toggleArchive');

    expect($this->julie->fresh()->isArchived())->toBeTrue();

    Livewire::test(GuestShow::class, ['guest' => $this->paul])->call('delete')->assertRedirect(route('guests.index'));
    expect(Guest::find($this->paul->id))->toBeNull();
});

/* ------------------------------------------------------------------ Convives dans le planning */

test('la fenêtre Convives enregistre présents, invités et groupe, puis le planning propose d\'adapter les portions', function () {
    $meal = $this->planner->addRecipe('2026-09-19', $this->dinner, dish('Lasagnes', ['Pâtes']), 4);

    Livewire::test(OccasionEditor::class)
        ->call('open', '2026-09-19', $this->dinner->id)
        ->assertSet('presentUserIds', [$this->monique->id, $this->pierre->id])
        ->assertSee('Lasagnes')
        ->call('addGroup', 'Amis')
        ->call('adjust', 'extraChildren', 1)
        ->set('title', 'Anniversaire de Julie')
        ->assertSee('Allergie · Noix')
        ->assertSet('diners.people', 5)->assertSet('diners.portions', 4.5)   // 2 + 2 + 0,5 → 4,5 portions (R33)
        ->set('guestSearch', 'Marc')
        ->call('createGuest')
        ->assertSet('diners.people', 6)->assertSet('diners.portions', 5.5)
        ->call('save')
        ->assertDispatched('occasion-saved', date: '2026-09-19', slotId: $this->dinner->id, before: 2, after: 5.5);

    $occasion = MealOccasion::sole();
    expect($occasion->guests->pluck('name')->all())->toBe(['Julie', 'Marc', 'Paul'])
        ->and($occasion->extra_children)->toBe(1);

    Livewire::test(Week::class)
        ->call('occasionSaved', '2026-09-19', $this->dinner->id, 2, 6)
        ->assertSet('servingsOffer.after', 6)
        ->assertSee('Adapter les portions du plat')
        ->call('acceptServingsOffer')
        ->assertSet('servingsOffer', null);

    expect($meal->fresh()->servings)->toBe(8.0);
});

test('le planning affiche les convives, les alertes et le nombre de repas avec invités', function () {
    $this->occasions->save('2026-09-19', $this->dinner, ['guest_ids' => [$this->julie->id, $this->paul->id], 'title' => 'Apéro dînatoire']);
    $this->planner->addRecipe('2026-09-19', $this->dinner, dish('Salade aux noix', ['Noix', 'Salade']));

    $this->get(route('planner.week'))
        ->assertOk()
        ->assertSee('1 repas avec invités')
        ->assertSee('Apéro dînatoire')
        ->assertSee('Contient : Noix — allergie de Julie');

    $meal = PlannedMeal::sole();
    Livewire::test(Week::class)
        ->call('selectMeal', $meal->id)
        ->assertSee('4 personnes')
        ->assertSee('Julie, Paul')
        ->assertSee('repas='.$meal->id, false);
});

test('le sélecteur propose les portions des convives, signale conflits et « déjà servi », filtre les compatibles', function () {
    $salade = dish('Salade aux noix', ['Noix']);
    $gratin = dish('Gratin', ['Pommes de terre']);
    $this->occasions->save('2026-06-01', $this->dinner, ['guest_ids' => [$this->paul->id]]);
    $this->planner->addRecipe('2026-06-01', $this->dinner, $gratin);

    $this->occasions->save('2026-09-19', $this->dinner, ['guest_ids' => [$this->julie->id, $this->paul->id]]);

    Livewire::test(MealPicker::class)
        ->call('open', '2026-09-19', $this->dinner->id)
        ->assertSet('servings', 4)
        ->assertSee('4 personnes')
        ->assertSee('Contient : Noix — allergie de Julie')
        ->assertSee('Déjà servi à Paul le 1 juin 2026')
        ->set('compatibleOnly', true)
        ->assertDontSee('Salade aux noix')
        ->assertSee('Gratin')
        ->call('changeOccasion')
        ->assertSet('show', false)
        ->assertDispatched('open-occasion');
});

test('la fiche recette ouverte depuis un repas surligne les ingrédients à risque', function () {
    $salade = dish('Salade aux noix', ['Noix', 'Salade']);
    $this->occasions->save('2026-09-19', $this->dinner, ['guest_ids' => [$this->julie->id]]);
    $meal = $this->planner->addRecipe('2026-09-19', $this->dinner, $salade);

    $this->get(route('recipes.show', ['recipe' => $salade, 'repas' => $meal->id]))
        ->assertOk()
        ->assertSee('3 personnes')->assertSee('(Julie)')
        ->assertSee('Contient : Noix — allergie de Julie')
        ->assertSee('bg-red-50', false);

    $this->get(route('recipes.show', $salade))->assertDontSee('allergie de Julie');
});

test('« Liste de courses pour ce repas » ne coche que les plats du repas', function () {
    $this->occasions->save('2026-09-19', $this->dinner, ['guest_ids' => [$this->julie->id], 'title' => 'Crémaillère']);
    $main = $this->planner->addRecipe('2026-09-19', $this->dinner, dish('Paella', ['Riz']));
    $lunch = $this->planner->addRecipe('2026-09-19', $this->lunch, dish('Soupe', ['Poireau']));

    Livewire::withQueryParams(['generer' => '2026-09-19', 'repas' => (string) $main->id])->test(ShoppingIndex::class)
        ->assertSet('showGenerate', true)
        ->assertSet('from', '2026-09-19')->assertSet('to', '2026-09-19')
        ->assertSet('excludedMealIds', [$lunch->id])
        ->assertSet('name', 'Courses — Crémaillère')
        ->call('generate');

    $source = \App\Models\ShoppingListItemSource::sole();
    expect($source->recipe_title)->toBe('Paella')->and($source->servings)->toBe(3.0);
});

test('l\'accueil affiche les convives du jour', function () {
    $this->occasions->save('2026-09-16', $this->dinner, ['extra_adults' => 2, 'title' => 'Voisins']);

    $this->get(route('dashboard'))->assertSee('Voisins');
});

test('un ingrédient ou un créneau utilisé par les invités ne peut pas être supprimé', function () {
    $noix = \App\Models\Ingredient::firstWhere('name', 'Noix');
    $this->occasions->save('2026-09-19', $this->dinner, ['extra_adults' => 1]);

    Livewire::test(Ingredients::class)->call('delete', $noix->id)->assertDispatched('notify', type: 'warning');
    Livewire::test(MealSlots::class)->call('delete', $this->dinner->id)->assertDispatched('notify', type: 'warning');

    expect($noix->fresh())->not->toBeNull()->and($this->dinner->fresh())->not->toBeNull();
});

test('le menu principal donne accès aux invités', function () {
    $this->get(route('guests.index'))->assertOk()->assertSee('Nouvel invité');
    $this->get(route('dashboard'))->assertSee(route('guests.index'), false);
});
