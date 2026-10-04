<?php

use App\Livewire\Dashboard;
use App\Livewire\Guests\GuestEditor;
use App\Livewire\Planner\MealPicker;
use App\Livewire\Planner\Week;
use App\Livewire\Recipes\Show as RecipeShow;
use App\Livewire\Settings\Household as HouseholdScreen;
use App\Models\Aisle;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\User;
use App\Services\Planning\Appetites;
use App\Services\Planning\GuestManager;
use App\Services\Planning\Lunchboxes;
use App\Services\Planning\OccasionService;
use App\Services\Planning\WeekPlanner;
use App\Services\Shopping\ShoppingListGenerator;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

require_once __DIR__.'/../Guests/helpers.php';

/*
 * Lot 32 — Portions justes : appétit de chacun (32.1, R33), affichage « 4 personnes · 3,5 portions »
 * (32.2) et gamelles du midi (32.3).
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00')); // mercredi
    $this->pierre = User::factory()->create(['name' => 'Pierre']);
    $this->monique = User::factory()->create(['name' => 'Monique']);
    $this->actingAs($this->pierre);
    Aisle::factory()->create(['name' => 'Divers']);

    $this->appetites = app(Appetites::class);
    $this->occasions = app(OccasionService::class);
    $this->planner = app(WeekPlanner::class);
    $this->lunch = MealSlot::factory()->create(['name' => 'Déjeuner', 'sort_order' => 1]);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 2]);
});

/** La personne d'un compte (lot 39 : une gamelle est pour une personne du foyer). */
function lunchPerson(User $user): int
{
    return app(\App\Services\People\HouseholdPeople::class)->forUser($user)->id;
}

/** Pierre et Monique (comptes), Lina (petite) et Hugo (moyen), sans compte. */
function familyTable(): void
{
    app(Appetites::class)->saveTable([
        ['name' => 'Pierre', 'appetite' => 'grand', 'user_id' => test()->pierre->id],
        ['name' => 'Monique', 'appetite' => 'normal', 'user_id' => test()->monique->id],
        ['name' => 'Lina', 'appetite' => 'petit', 'user_id' => null],
        ['name' => 'Hugo', 'appetite' => 'moyen', 'user_id' => null],
    ]);
}

/* ================================================================ R33 : parts et arrondi */

test('les parts par défaut, l\'arrondi à la demi-portion supérieure et l\'écriture à la française', function () {
    expect($this->appetites->parts())->toBe(['petit' => 0.5, 'moyen' => 0.75, 'normal' => 1.0, 'grand' => 1.5])
        ->and(Appetites::roundUp(3.25))->toBe(3.5)
        ->and(Appetites::roundUp(3.5))->toBe(3.5)
        ->and(Appetites::roundUp(3.0))->toBe(3.0)
        ->and(Appetites::roundUp(3.01))->toBe(3.5)
        ->and(Appetites::format(3.5))->toBe('3,5')
        ->and(Appetites::format(4.0))->toBe('4')
        ->and(Appetites::label(1))->toBe('1 portion')
        ->and(Appetites::label(1.5))->toBe('1,5 portion')
        ->and(Appetites::label(2.5))->toBe('2,5 portions')
        ->and(Appetites::formatPart(0.75))->toBe('0,75')
        ->and(Appetites::formatPart(1.0))->toBe('1')
        ->and(Appetites::clamp('2,4'))->toBe(2.5)
        ->and(Appetites::clamp(0))->toBe(0.5)
        ->and(Appetites::clamp(80))->toBe(50.0);
});

test('tant que la table n\'est pas réglée, chacun compte pour une portion, comme avant', function () {
    expect($this->appetites->isConfigured())->toBeFalse()
        ->and($this->occasions->diners(null))->toBe(2.0)
        ->and($this->occasions->summary(null))->toBe('2 personnes')
        ->and(collect($this->appetites->table())->pluck('name')->all())->toBe(['Pierre', 'Monique']);
});

test('les portions du foyer sont la somme des appétits des présents, arrondie (R33)', function () {
    familyTable();

    // 1,5 + 1 + 0,5 + 0,75 = 3,75 → 4 portions
    expect($this->occasions->diners(null))->toBe(4.0)
        ->and($this->occasions->summary(null))->toBe('4 personnes')
        ->and($this->occasions->householdSize())->toBe(4);

    // Pierre absent au déjeuner : 1 + 0,5 + 0,75 = 2,25 → 2,5 portions
    $this->occasions->save('2026-09-17', $this->lunch, ['absent_user_ids' => [$this->pierre->id]]);
    expect($this->occasions->dinersAt('2026-09-17', $this->lunch))->toBe(2.5)
        ->and($this->occasions->summary($this->occasions->find('2026-09-17', $this->lunch)))->toBe('3 personnes · 2,5 portions')
        ->and($this->occasions->servingsAt('2026-09-17', $this->lunch))->toBe(2.5);

    // Les parts se règlent : un « petit » à 0,75 fait passer le total à 4.
    $this->appetites->saveParts(['petit' => '0,75', 'moyen' => '0.75', 'normal' => 1, 'grand' => '1,5']);
    expect($this->appetites->part('petit'))->toBe(0.75)
        ->and(fn () => $this->appetites->saveParts(['petit' => '5']))->toThrow(InvalidArgumentException::class, 'Une part va de 0,25 à 3.');
});

test('l\'appétit d\'un invité : selon l\'âge par défaut, ou choisi', function () {
    familyTable();
    $manager = app(GuestManager::class);
    $lea = $manager->save(null, ['name' => 'Léa', 'is_child' => true]);
    $marc = $manager->save(null, ['name' => 'Marc', 'appetite' => 'grand']);
    $anne = $manager->save(null, ['name' => 'Anne', 'appetite' => 'n\'importe quoi']);

    expect($lea->appetiteLevel())->toBe('petit')
        ->and($marc->appetiteLevel())->toBe('grand')
        ->and($anne->appetite)->toBeNull()
        ->and($anne->appetiteLevel())->toBe('normal');

    // 3,75 (foyer) + 0,5 + 1,5 + 1 = 6,75 → 7 portions pour 7 personnes
    $this->occasions->save('2026-09-19', $this->dinner, ['guest_ids' => [$lea->id, $marc->id, $anne->id]]);
    expect($this->occasions->dinersAt('2026-09-19', $this->dinner))->toBe(7.0)
        ->and($this->occasions->peopleAt('2026-09-19', $this->dinner))->toBe(7);

    Livewire::test(GuestEditor::class)
        ->call('open', $anne->id)
        ->assertSet('appetite', '')
        ->assertSee('Selon l\'âge (normal · 1)', false)
        ->assertSee('Moyen — 6 à 12 ans (0,75)')
        ->set('appetite', 'moyen')
        ->call('save')
        ->assertHasNoErrors();

    expect($anne->fresh()->appetite)->toBe('moyen')
        ->and($this->occasions->dinersAt('2026-09-19', $this->dinner))->toBe(6.5);   // 6,5 : 3,75 + 0,5 + 1,5 + 0,75

    Livewire::test(GuestEditor::class)->call('open', $anne->id)->set('appetite', 'géant')->call('save')->assertHasErrors('appetite');
});

/* ================================================================ Réglages du foyer */

test('Paramètres › Foyer : les personnes du foyer et leur appétit', function () {
    $screen = Livewire::test(HouseholdScreen::class)
        ->assertSee('Les personnes du foyer', false)
        ->assertSee('Compte de Pierre')
        ->assertSee('Compte de Monique')
        ->call('openPerson')
        ->set('person.appetite', 'petit')
        ->call('savePerson')
        ->assertHasErrors('person.name')
        ->assertSee('Chaque personne a un prénom.')
        ->set('person.name', 'Lina')
        ->call('savePerson')
        ->assertHasNoErrors()
        ->assertDispatched('notify', message: 'Lina : enregistré. À table d\'habitude : 3 personnes · 2,5 portions.');

    // Pierre ne mange plus à la maison d'habitude : il quitte la table, ses goûts restent.
    $pierre = \App\Models\HouseholdPerson::query()->where('user_id', $this->pierre->id)->firstOrFail();
    $screen->call('openPerson', $pierre->id)->set('person.at_table', false)->call('savePerson')->assertHasNoErrors();

    expect($this->occasions->diners(null))->toBe(1.5)
        ->and($this->occasions->householdSize())->toBe(2);

    Livewire::test(HouseholdScreen::class)
        ->assertSet('parts.moyen', '0,75')
        ->set('parts.grand', '4')
        ->call('saveParts')
        ->assertHasErrors('parts')
        ->set('parts.grand', '2')
        ->call('saveParts')
        ->assertHasNoErrors()
        ->assertDispatched('notify', message: 'Parts enregistrées.');

    expect($this->appetites->part('grand'))->toBe(2.0);
});

test('choisir un compte remplit le prénom ; un compte hors du foyer n\'est pas retenu', function () {
    $stranger = User::factory()->create(['name' => 'Voisin']);
    app(\App\Services\Households\HouseholdManager::class)->detach(\App\Support\CurrentHousehold::get(), $stranger);

    Livewire::test(HouseholdScreen::class)
        ->call('openPerson')
        ->set('person.user_id', (string) $this->monique->id)
        ->assertSet('person.name', 'Monique')
        ->call('savePerson')
        ->assertHasErrors('person.name');   // Monique a déjà sa personne

    $this->appetites->saveTable([['name' => 'Bob', 'appetite' => 'normal', 'user_id' => $stranger->id]]);
    expect($this->appetites->table()[0]['user_id'])->toBeNull()
        ->and(fn () => $this->appetites->saveTable([]))->toThrow(InvalidArgumentException::class);
});

/* ================================================================ 32.2 Affichage, recettes et courses */

test('le planning affiche « À table » et les demi-portions ; la liste de courses suit', function () {
    familyTable();
    $this->occasions->save('2026-09-17', $this->dinner, ['absent_user_ids' => [$this->pierre->id]]);

    $recipe = dish('Gratin', ['Pommes de terre'], [], 2);   // 100 g pour 2 portions
    Livewire::test(MealPicker::class)
        ->call('open', '2026-09-17', $this->dinner->id)
        ->assertSet('servings', 2.5)
        ->assertSee('3 personnes · 2,5 portions')
        ->call('pickRecipe', $recipe->id)
        ->assertHasNoErrors();

    $meal = PlannedMeal::sole();
    expect($meal->servings)->toBe(2.5);

    Livewire::test(Week::class)
        ->assertSee('À table : 4 personnes')
        ->call('selectMeal', $meal->id)
        ->assertSee('3 personnes · 2,5 portions');

    $line = app(ShoppingListGenerator::class)->generate([$meal->fresh(['recipe', 'slot'])])->sole();
    expect($line->quantity())->toBe(125.0)
        ->and($line->sources[0]['servings'])->toBe(2.5);
});

test('la fiche recette propose les portions du foyer, à la demi-portion', function () {
    familyTable();
    $this->appetites->saveParts(['petit' => 0.5, 'moyen' => 0.5, 'normal' => 1, 'grand' => 1.5]);   // 3,5 portions
    $recipe = dish('Curry', ['Riz'], [], 4);

    Livewire::test(RecipeShow::class, ['recipe' => $recipe])
        ->assertSee('Pour le foyer : 3,5 portions')
        ->call('useHouseholdServings')
        ->assertSet('servings', 3.5)
        ->call('decrement')
        ->assertSet('servings', 2.5);

    $this->get(route('recipes.show', ['recipe' => $recipe, 'portions' => '2,4']))->assertOk()->assertSee('2,5 portions');
});

/* ================================================================ 32.3 Gamelles */

test('placer des restes en gamelle : la portion de la personne, un libellé clair', function () {
    familyTable();
    $source = $this->planner->addRecipe('2026-09-21', $this->dinner, dish('Lasagnes', ['Pâtes']), 8);

    $box = $this->planner->addLeftover('2026-09-22', $this->lunch, $source, null, lunchPerson($this->pierre));
    $monique = $this->planner->addLeftover('2026-09-22', $this->lunch, $source, null, lunchPerson($this->monique));

    expect($box->is_lunchbox)->toBeTrue()
        ->and($box->servings)->toBe(1.5)                   // Pierre a un grand appétit
        ->and($monique->servings)->toBe(1.0)
        ->and($box->fresh('forPerson', 'leftoverOf.recipe')->label())->toBe('Gamelle de Pierre : Lasagnes')
        ->and(PlannedMeal::lunchboxLabelFor('Anne'))->toBe('Gamelle d\'Anne')
        ->and(PlannedMeal::lunchboxLabelFor('Hugo'))->toBe('Gamelle d\'Hugo');

    $stranger = User::factory()->create();
    app(\App\Services\Households\HouseholdManager::class)->detach(\App\Support\CurrentHousehold::get(), $stranger);
    expect(fn () => $this->planner->addLeftover('2026-09-22', $this->lunch, $source, null, 999999))
        ->toThrow(InvalidArgumentException::class, 'Cette personne ne fait pas partie du foyer.')
        ->and(fn () => $this->planner->setLunchbox($source, lunchPerson($this->pierre)))
        ->toThrow(InvalidArgumentException::class, 'Seuls des restes peuvent partir en gamelle.');

    $this->planner->setLunchbox($monique, null);
    expect($monique->fresh())->is_lunchbox->toBeFalse()->for_person_id->toBeNull();
});

test('le sélecteur et le détail d\'un repas mettent des restes en gamelle', function () {
    $source = $this->planner->addRecipe('2026-09-14', $this->dinner, dish('Chili', ['Haricots']), 6);

    Livewire::test(MealPicker::class)
        ->call('open', '2026-09-17', $this->lunch->id)
        ->set('tab', 'leftover')
        ->assertSee('Gamelle de Monique')
        ->assertSee('4 portions restantes')
        ->set('lunchboxFor', (string) lunchPerson($this->monique))
        ->call('pickLeftover', $source->id)
        ->assertHasNoErrors();

    $box = PlannedMeal::query()->where('is_lunchbox', true)->sole();
    expect($box->forPerson->user_id)->toBe($this->monique->id)->and($box->servings)->toBe(1.0);

    Livewire::test(Week::class)
        ->assertSee('Gamelle de Monique')
        ->assertSee('Gamelles (1)')
        ->call('selectMeal', $box->id)
        ->assertSee('Emporté en gamelle ?')
        ->call('setLunchbox', $box->id, (string) lunchPerson($this->pierre))
        ->assertDispatched('notify', message: 'Gamelle de Pierre, jeudi.')
        ->call('setLunchbox', $box->id, '')
        ->assertDispatched('notify', message: 'Restes mangés à table.');

    expect($box->fresh()->is_lunchbox)->toBeFalse();
});

test('la veille : l\'accueil, la liste à préparer et les étiquettes', function () {
    $source = $this->planner->addRecipe('2026-09-15', $this->dinner, dish('Hachis', ['Bœuf']), 6);
    $this->planner->addLeftover('2026-09-17', $this->lunch, $source, null, lunchPerson($this->pierre));
    $this->planner->addLeftover('2026-09-18', $this->lunch, $source, null, lunchPerson($this->monique));

    expect(app(Lunchboxes::class)->toPrepareOn('2026-09-16'))->toHaveCount(1)
        ->and(app(Lunchboxes::class)->byEvening('2026-09-17', '2026-09-18')->keys()->all())->toBe(['2026-09-16', '2026-09-17']);

    Livewire::test(Dashboard::class)
        ->assertSee('Gamelles à préparer ce soir')
        ->assertSee('Gamelle de Pierre · Hachis')
        ->assertSee('restes du mardi 15/09, dîner')
        ->assertDontSee('Gamelle de Monique');

    $this->get(route('planner.lunchboxes', ['du' => '2026-09-17', 'au' => '2026-09-18']))
        ->assertOk()
        ->assertSeeInOrder(['À préparer la veille', 'Mercredi 16 septembre soir', 'Gamelle de Pierre', 'Jeudi 17 septembre soir', 'Gamelle de Monique', 'Étiquettes'])
        ->assertSee('Cuisiné le')
        ->assertSee('data-qr="'.route('planner.week', ['repas' => PlannedMeal::where('for_person_id', lunchPerson($this->pierre))->value('id')]).'"', false);

    $this->get(route('planner.lunchboxes', ['du' => '2026-10-05', 'au' => '2026-10-01']))->assertOk()->assertSee('Aucune gamelle');
});

test('le QR code d\'une étiquette ouvre le repas dans le planning', function () {
    $source = $this->planner->addRecipe('2026-09-21', $this->dinner, dish('Soupe', ['Poireau']), 4);
    $box = $this->planner->addLeftover('2026-09-23', $this->lunch, $source, null, lunchPerson($this->pierre));

    Livewire::withQueryParams(['repas' => (string) $box->id])->test(Week::class)
        ->assertSet('selectedMealId', $box->id)
        ->assertSet('week', '2026-09-21');
});
