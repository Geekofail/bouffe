<?php

use App\Enums\RestrictionType;
use App\Livewire\Kitchen\Choice as ChoiceScreen;
use App\Livewire\Planner\Canteen as CanteenScreen;
use App\Livewire\Planner\ChildChoices as ChoicesScreen;
use App\Livewire\Planner\OccasionEditor;
use App\Livewire\Recipes\Cook;
use App\Livewire\Settings\Household as HouseholdScreen;
use App\Models\Aisle;
use App\Models\CanteenMeal;
use App\Models\ChildChoice;
use App\Models\HouseholdPerson;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\User;
use App\Models\Wish;
use App\Services\People\CanteenCalendar;
use App\Services\People\ChildChoices;
use App\Services\People\HouseholdPeople;
use App\Services\Planning\HouseholdService;
use App\Services\Planning\OccasionService;
use App\Services\Planning\WeekBalance;
use App\Services\Planning\WeekFiller;
use App\Services\Planning\WeekPlanner;
use App\Services\Recipes\AdultSteps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Guests/helpers.php';

/*
 * Lot 39 — Enfants, école et cantine : les personnes du foyer (39.1, R40), la cantine du midi
 * (39.2, R41), le choix des enfants (39.3, R42) et « ils cuisinent » (39.4).
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-04 10:00'));   // mercredi
    $this->pierre = User::factory()->create(['name' => 'Pierre']);
    $this->monique = User::factory()->create(['name' => 'Monique']);
    $this->actingAs($this->pierre);
    Aisle::factory()->create(['name' => 'Divers']);

    $this->people = app(HouseholdPeople::class);
    $this->canteen = app(CanteenCalendar::class);
    $this->occasions = app(OccasionService::class);
    $this->planner = app(WeekPlanner::class);
    $this->lunch = MealSlot::factory()->create(['name' => 'Déjeuner', 'sort_order' => 1]);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 2]);
});

/** Léo, 8 ans, sans compte : cantine lundi, mardi, jeudi, vendredi. */
function leo(array $data = []): HouseholdPerson
{
    return app(HouseholdPeople::class)->save(null, [
        'name' => 'Léo', 'appetite' => 'moyen', 'color' => 'ciel', 'canteen_days' => [1, 2, 4, 5], 'canteen_name' => 'École du quartier', ...$data,
    ]);
}

/* ================================================================ 39.1 Les personnes du foyer */

test('la première personne enregistrée garde la table par défaut : les portions ne changent pas', function () {
    expect($this->people->configured())->toBeFalse()
        ->and($this->occasions->diners(null))->toBe(2.0);

    $leo = leo();

    expect($this->people->all()->pluck('name')->all())->toBe(['Pierre', 'Monique', 'Léo'])
        ->and($this->people->all()->firstWhere('name', 'Pierre')->user_id)->toBe($this->pierre->id)
        ->and($leo->canteenDays())->toBe([1, 2, 4, 5])
        ->and($this->occasions->diners(null))->toBe(3.0)                // 1 + 1 + 0,75 → 2,75 → 3
        ->and($this->occasions->householdSize())->toBe(3)
        ->and($leo->hex())->toBe('#3b82c4');
});

test('une personne : prénom obligatoire, compte du foyer rattaché une seule fois, au moins une personne à table', function () {
    $this->people->ensure();
    $stranger = User::factory()->create(['name' => 'Voisin']);
    app(\App\Services\Households\HouseholdManager::class)->detach(\App\Support\CurrentHousehold::get(), $stranger);

    expect(fn () => $this->people->save(null, ['name' => '  ']))->toThrow(InvalidArgumentException::class, 'prénom')
        ->and(fn () => $this->people->save(null, ['name' => 'Bob', 'user_id' => $stranger->id]))->toThrow(InvalidArgumentException::class, 'ne fait pas partie')
        ->and(fn () => $this->people->save(null, ['name' => 'Bis', 'user_id' => $this->monique->id]))->toThrow(InvalidArgumentException::class, 'déjà rattaché');

    $pierre = $this->people->forUser($this->pierre);
    $monique = $this->people->forUser($this->monique);
    $this->people->save($pierre, ['name' => 'Pierre', 'user_id' => $this->pierre->id, 'at_table' => false]);

    expect(fn () => $this->people->save($monique, ['name' => 'Monique', 'user_id' => $this->monique->id, 'at_table' => false]))
        ->toThrow(InvalidArgumentException::class, 'au moins une personne')
        ->and(fn () => $this->people->delete($monique->fresh()))->toThrow(InvalidArgumentException::class, 'au moins une personne');

    // Un compte qui quitte le foyer : sa personne reste, sans compte.
    app(\App\Services\Households\HouseholdManager::class)->detach(\App\Support\CurrentHousehold::get(), $this->monique);
    expect($monique->fresh()->user_id)->toBeNull();
});

test('un enfant a ses allergies : alertes sur le planning, même sans compte', function () {
    $leo = leo();
    $household = app(HouseholdService::class);
    $household->addRestriction($leo, RestrictionType::Allergy, ingredientNamed('Arachide')->id);
    $satay = dish('Poulet satay', ['Poulet', 'Arachide']);
    $meal = $this->planner->addRecipe('2026-11-04', $this->dinner, $satay);

    $conflicts = app(\App\Services\Planning\GuestCompatibility::class)->conflicts($satay, $household->eatersAt('2026-11-04', $this->dinner->id));

    expect($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['message'])->toContain('allergie de Léo')
        ->and(fn () => $household->addRestriction($leo, RestrictionType::Allergy, ingredientNamed('Arachide')->id))->toThrow(InvalidArgumentException::class, 'déjà noté pour Léo');

    // Un compte passe par sa personne (créée « pas à table » s'il n'y en avait pas).
    $household->addRestriction($this->monique, RestrictionType::Dislike, ingredientNamed('Poulet')->id);
    expect($this->monique->restrictions()->count())->toBe(1);

    Livewire::test(\App\Livewire\Planner\Week::class)->assertSee('allergie de Léo');
    expect($meal)->not->toBeNull();
});

test('Convives : une personne sans compte peut être absente d\'un repas', function () {
    $leo = leo();

    Livewire::test(OccasionEditor::class)
        ->call('open', '2026-11-06', $this->dinner->id)
        ->assertSee('Léo')
        ->assertSet('presentPersonIds', [$leo->id])
        ->set('presentPersonIds', [])
        ->call('save')
        ->assertDispatched('notify', message: 'Convives enregistrés : 2 personnes.');

    $occasion = $this->occasions->find('2026-11-06', $this->dinner->id);
    expect($occasion->absent_person_ids)->toBe([$leo->id])
        ->and($this->occasions->dinersAt('2026-11-06', $this->dinner->id))->toBe(2.0)
        ->and($this->occasions->guestSummary($occasion))->toBe('sans Léo');
});

test('Paramètres › Foyer : ajouter un enfant, sa cantine, ses goûts', function () {
    $arachide = ingredientNamed('Arachide');

    Livewire::test(HouseholdScreen::class)
        ->call('openPerson')
        ->set('person.name', 'Léo')
        ->set('person.appetite', 'moyen')
        ->set('person.color', 'ciel')
        ->set('person.canteen_days', ['1', '2', '4', '5'])
        ->set('person.canteen_name', 'École du quartier')
        ->set('person.share_tastes', true)
        ->call('savePerson')
        ->assertHasNoErrors()
        ->assertSee('Cantine lun, mar, jeu, ven')
        ->assertSee('· École du quartier');

    $leo = HouseholdPerson::query()->where('name', 'Léo')->sole();

    Livewire::test(HouseholdScreen::class)
        ->call('openRestriction', $leo->id)
        ->set('restrictionType', 'allergy')
        ->set('subjectId', $arachide->id)
        ->call('addRestriction')
        ->assertHasNoErrors()
        ->assertSee('Arachide')
        ->assertSee('Montrés aux foyers reliés.')
        ->call('movePerson', $leo->id, -1);

    expect($this->people->all()->pluck('name')->all())->toBe(['Pierre', 'Léo', 'Monique'])
        ->and($leo->fresh()->share_tastes)->toBeTrue();

    Livewire::test(HouseholdScreen::class)->call('openPerson', $leo->id)->assertSee('Modifier Léo')->assertSet('person.canteen_days', ['1', '2', '4', '5'])
        ->call('deletePerson')->assertHasNoErrors();
    expect(HouseholdPerson::query()->where('name', 'Léo')->exists())->toBeFalse()
        ->and(\App\Models\PersonRestriction::count())->toBe(0);
});

test('une gamelle peut être pour un enfant sans compte', function () {
    $leo = leo();
    $source = $this->planner->addRecipe('2026-11-03', $this->dinner, dish('Lasagnes', ['Pâtes']), 6);
    $box = $this->planner->addLeftover('2026-11-07', $this->lunch, $source, null, $leo->id);   // samedi

    expect($box->is_lunchbox)->toBeTrue()
        ->and($box->servings)->toBe(1.0)              // 0,75 → 1
        ->and($box->fresh('forPerson', 'leftoverOf.recipe')->label())->toBe('Gamelle de Léo : Lasagnes')
        ->and(app(\App\Services\Planning\Lunchboxes::class)->people()->pluck('name')->all())->toContain('Léo');
});

/* ================================================================ 39.2 La cantine */

test('les jours de cantine, l\'enfant n\'est pas compté au déjeuner ; « pas de cantine » le remet à table', function () {
    $leo = leo();

    expect($this->occasions->dinersAt('2026-11-02', $this->lunch->id))->toBe(2.0)    // lundi midi : à la cantine
        ->and($this->occasions->peopleAt('2026-11-02', $this->lunch->id))->toBe(2)
        ->and($this->occasions->dinersAt('2026-11-02', $this->dinner->id))->toBe(3.0)   // le soir : à table
        ->and($this->occasions->dinersAt('2026-11-04', $this->lunch->id))->toBe(3.0)    // mercredi : pas de cantine
        ->and($this->canteen->absentAt('2026-11-02', $this->lunch->id))->toBe([$leo->id])
        ->and($this->planner->addRecipe('2026-11-02', $this->lunch, dish('Salade', ['Laitue']))->servings)->toBe(2.0);

    $this->canteen->setDay($leo, '2026-11-02', CanteenMeal::HOME);

    expect($this->occasions->dinersAt('2026-11-02', $this->lunch->id))->toBe(3.0)
        ->and(fn () => $this->canteen->setDay($leo, '2026-11-07', CanteenMeal::CANTEEN))->toThrow(InvalidArgumentException::class, 'week-end');

    // Les alertes suivent : à la cantine, ses allergies ne comptent pas pour le déjeuner à la maison.
    app(HouseholdService::class)->addRestriction($leo, RestrictionType::Allergy, ingredientNamed('Arachide')->id);
    expect(app(HouseholdService::class)->eatersAt('2026-11-03', $this->lunch->id))->toHaveCount(0)
        ->and(app(HouseholdService::class)->eatersAt('2026-11-03', $this->dinner->id))->toHaveCount(1);
});

test('un menu collé est découpé par jour, ses familles repérées ; les jours sans cantine sont ignorés', function () {
    $leo = leo();
    $text = "MENU DE LA SEMAINE DU 2 AU 6 NOVEMBRE\nLundi 2/11 : Potage, poisson pané (1, 4), purée\nMardi\n- Lasagnes bolognaise\n- Salade verte\n- Yaourt (7)\nMercredi : Omelette\nJeudi : Poulet rôti, frites\nVendredi : Couscous aux légumes";

    expect($this->canteen->parse($text))->toBe([
        1 => 'Potage, poisson pané, purée',
        2 => 'Lasagnes bolognaise, Salade verte, Yaourt',
        3 => 'Omelette',
        4 => 'Poulet rôti, frites',
        5 => 'Couscous aux légumes',
    ])->and($this->canteen->paste($leo, '2026-11-04', $text))->toBe(4)
        ->and(fn () => $this->canteen->paste($leo, '2026-11-04', 'rien de lisible'))->toThrow(InvalidArgumentException::class, 'Aucun jour');

    $monday = CanteenMeal::query()->whereDate('date', '2026-11-02')->sole();
    expect($monday->families)->toBe(['poisson', 'feculents'])
        ->and($monday->source)->toBe('paste')
        ->and(CanteenMeal::query()->whereDate('date', '2026-11-04')->exists())->toBeFalse()   // mercredi : pas de cantine pour Léo
        ->and(app(WeekBalance::class)->familiesOfText('Poulet rôti, frites'))->toBe(['viande', 'feculents'])
        ->and($this->canteen->servedOn('2026-11-02'))->toBe(['labels' => ['Potage, poisson pané, purée'], 'families' => ['poisson', 'feculents']]);

    // L'équilibre de la semaine compte les midis de cantine au menu connu.
    $balance = app(WeekBalance::class)->week(Carbon::parse('2026-11-02'), Carbon::parse('2026-11-08'));
    expect($balance['canteen'])->toBe(4)
        ->and(collect($balance['families'])->firstWhere('key', 'poisson')['count'])->toBe(1);
});

test('le soir, les idées évitent le plat et la famille servis à la cantine ce midi', function () {
    $leo = leo();
    $this->canteen->setDay($leo, '2026-11-05', CanteenMeal::CANTEEN, 'Lasagnes bolognaise, salade');
    dish('Lasagnes maison', ['Pâtes', 'Bœuf haché']);
    dish('Saumon grillé', ['Saumon', 'Riz']);

    $warnings = collect(app(WeekFiller::class)->ideasFor('2026-11-05', $this->dinner, 3))->pluck('warnings', 'title');

    expect($warnings['Lasagnes maison'])->toContain('déjà servi à la cantine ce midi')
        ->and($warnings['Saumon grillé'])->not->toContain('déjà servi à la cantine ce midi')
        ->and(collect(app(WeekFiller::class)->ideasFor('2026-11-05', $this->dinner, 3))->first()['title'])->toBe('Saumon grillé');   // le mieux noté d'abord

    $this->canteen->setDay($leo, '2026-11-06', CanteenMeal::CANTEEN, 'Filet de poisson, riz');
    $friday = collect(app(WeekFiller::class)->ideasFor('2026-11-06', $this->dinner, 3))->pluck('warnings', 'title');
    expect($friday['Saumon grillé'])->toContain('poisson à la cantine ce midi')
        // Le midi lui-même n'est pas concerné.
        ->and(collect(app(WeekFiller::class)->ideasFor('2026-11-06', $this->lunch, 3))->pluck('warnings')->flatten()->all())->not->toContain('poisson à la cantine ce midi');
});

test('Planning › Cantine : saisir, « pas de cantine », coller ; le planning l\'affiche', function () {
    $leo = leo();
    $emma = leo(['name' => 'Emma', 'canteen_days' => [1, 2, 3, 4, 5], 'color' => 'framboise']);

    Livewire::withQueryParams(['semaine' => '2026-11-02'])->test(CanteenScreen::class)
        ->assertSee('Semaine du 2 novembre')
        ->assertSee('Léo')->assertSee('Emma')
        ->call('saveLabel', $leo->id, '2026-11-02', 'Hachis parmentier')
        ->call('toggleHome', $leo->id, '2026-11-03')
        ->assertDispatched('notify', message: 'À la maison ce midi-là : compté dans les portions.')
        ->set('pasteFor', [(string) $emma->id])
        ->set('pasted', "Mercredi : Pâtes au saumon\nJeudi : Rôti de dinde")
        ->call('paste')
        ->assertHasNoErrors()
        ->assertDispatched('notify', message: '2 jours remplis : relisez avant de compter dessus.')
        ->call('homeAllWeek', $emma->id)
        ->assertDispatched('notify', message: 'Pas de cantine cette semaine pour Emma.');

    expect(CanteenMeal::query()->where('person_id', $leo->id)->whereDate('date', '2026-11-02')->value('label'))->toBe('Hachis parmentier')
        ->and(CanteenMeal::query()->where('person_id', $leo->id)->whereDate('date', '2026-11-03')->value('status'))->toBe('home')
        ->and(CanteenMeal::query()->where('person_id', $emma->id)->where('status', 'home')->count())->toBe(5);

    Livewire::withQueryParams(['semaine' => '2026-11-02'])->test(\App\Livewire\Planner\Week::class)
        ->assertSee('Hachis parmentier')
        ->assertSeeHtml('data-canteen-chip');

    $this->get(route('planner.canteen'))->assertOk();
    Livewire::test(CanteenScreen::class)->call('saveLabel', $leo->id, '2026-11-07', 'Pizza')->assertDispatched('notify', type: 'warning');
});

test('le menu photographié est lu par le service des tickets, sans nom', function () {
    $leo = leo();
    $ocr = Mockery::mock(\App\Services\Receipts\OcrService::class);
    $ocr->shouldReceive('status')->andReturn(['available' => true, 'reason' => null, 'label' => 'Mistral']);
    $ocr->shouldReceive('readDocumentText')->once()->withArgs(fn ($file, $purpose) => $purpose === 'canteen')->andReturn("Lundi : Gratin de pâtes\nMardi : Poisson meunière");
    app()->instance(\App\Services\Receipts\OcrService::class, $ocr);

    Livewire::withQueryParams(['semaine' => '2026-11-09'])->test(CanteenScreen::class)
        ->set('photo', \Illuminate\Http\UploadedFile::fake()->image('menu.jpg'))
        ->assertHasNoErrors()
        ->assertDispatched('notify', message: '2 jours remplis : relisez avant de compter dessus.');

    expect(CanteenMeal::query()->where('person_id', $leo->id)->pluck('source')->unique()->all())->toBe(['photo']);
});

/* ================================================================ 39.3 Le choix des enfants */

test('un adulte propose trois recettes compatibles avec tout le monde ; l\'enfant choisit, c\'est une envie', function () {
    $leo = leo();
    app(HouseholdService::class)->addRestriction($leo, RestrictionType::Allergy, ingredientNamed('Arachide')->id);
    $pasta = dish('Pâtes carbonara', ['Pâtes']);
    $pizza = dish('Pizza maison', ['Farine']);
    $soup = dish('Soupe', ['Poireau']);
    $satay = dish('Poulet satay', ['Poulet', 'Arachide']);
    $choices = app(ChildChoices::class);

    expect(fn () => $choices->propose('2026-11-05', $this->dinner, [$pasta->id, $satay->id], $leo->id))
        ->toThrow(InvalidArgumentException::class, 'ne convient pas à tout le monde')
        ->and(fn () => $choices->propose('2026-11-05', $this->dinner, [$pasta->id], $leo->id))->toThrow(InvalidArgumentException::class, 'deux ou trois')
        ->and(fn () => $choices->propose('2026-11-01', $this->dinner, [$pasta->id, $pizza->id], $leo->id))->toThrow(InvalidArgumentException::class, 'déjà passé')
        ->and(collect($choices->ideas('2026-11-05', $this->dinner))->pluck('title')->all())->not->toContain('Poulet satay');

    $choice = $choices->propose('2026-11-05', $this->dinner, [$pasta->id, $pizza->id, $soup->id], $leo->id);
    expect($choice->dayLabel())->toBe('dîner de jeudi');

    Livewire::test(ChoiceScreen::class)
        ->assertSee('Léo, choisis ton dîner de jeudi')
        ->assertSee('Pizza maison')
        ->call('pick', $pizza->id)
        ->assertSee('Tu choisis')
        ->call('back')
        ->call('pick', $pizza->id)
        ->call('confirm')
        ->assertSee('C\'est noté !', false)
        ->assertSee('Les parents le verront dans les envies.');

    $wish = Wish::query()->sole();
    expect($wish->recipe_id)->toBe($pizza->id)
        ->and($wish->text)->toBe('Choix de Léo pour le dîner de jeudi')
        ->and($wish->planned_at)->toBeNull()
        ->and($choice->fresh()->chosen_recipe_id)->toBe($pizza->id)
        ->and(PlannedMeal::count())->toBe(0)
        ->and(fn () => $choices->choose($choice->fresh(), $pasta->id))->toThrow(InvalidArgumentException::class, 'déjà été fait');
});

test('si l\'adulte l\'a permis, le choix remplace le plat prévu', function () {
    $pasta = dish('Pâtes carbonara', ['Pâtes']);
    $pizza = dish('Pizza maison', ['Farine']);
    $planned = $this->planner->addRecipe('2026-11-05', $this->dinner, dish('Endives au jambon', ['Endive']));

    Livewire::test(ChoicesScreen::class)
        ->set('date', '2026-11-05')
        ->set('slotId', $this->dinner->id)
        ->set('personId', '')
        ->set('recipeIds', [(string) $pasta->id, (string) $pizza->id, ''])
        ->set('allowPlan', true)
        ->call('propose')
        ->assertHasNoErrors()
        ->assertSee('Les enfants · Dîner de jeudi');

    $choice = ChildChoice::query()->sole();
    Livewire::test(ChoiceScreen::class, ['choiceId' => $choice->id])
        ->assertSee('Choisissez votre dîner de jeudi')
        ->call('pick', $pasta->id)->call('confirm')
        ->assertSee('au planning.');

    expect($planned->fresh()->recipe_id)->toBe($pasta->id)
        ->and(Wish::query()->sole()->planned_meal_id)->toBe($planned->id)
        ->and($choice->fresh()->planned_meal_id)->toBe($planned->id);

    // L'écran de cuisine propose le choix en attente.
    app(ChildChoices::class)->propose('2026-11-06', $this->dinner, [$pasta->id, $pizza->id]);
    $this->get(route('kitchen'))->assertOk()->assertSee('à vous de choisir le dîner de vendredi');
    $this->get(route('kitchen.choice'))->assertOk();
    $this->get(route('planner.choices'))->assertOk()->assertSee('À choisir');
});

/* ================================================================ 39.4 Ils cuisinent */

test('une recette « facile avec un enfant » signale les étapes pour un adulte, et se corrige', function () {
    $recipe = dish('Cookies', ['Farine', 'Chocolat']);
    $recipe->steps()->createMany([
        ['position' => 1, 'instruction' => 'Mélanger la farine et le sucre dans un saladier.'],
        ['position' => 2, 'instruction' => 'Préchauffer le four à 180 °C et enfourner 12 minutes.'],
    ]);

    // Pas marquée : rien n'est signalé.
    Livewire::test(Cook::class, ['recipe' => $recipe])->set('step', 2)->assertDontSee('Avec un adulte');

    $recipe->update(['kid_friendly' => true]);
    $adults = app(AdultSteps::class);
    expect($adults->reason('Couper les tomates en dés.'))->toBe('couteau')
        ->and($adults->reason('Verser dans des coupelles.'))->toBeNull()
        ->and($adults->reason('Faire revenir les oignons dans une poêle.'))->toBe('plaque de cuisson');

    Livewire::test(Cook::class, ['recipe' => $recipe->fresh()])
        ->set('step', 1)->assertSee('L\'enfant peut le faire', false)
        ->set('step', 2)->assertSee('Avec un adulte · four')
        ->call('correctAdult', 2)
        ->assertSee('L\'enfant peut le faire', false);

    expect($recipe->steps()->where('position', 2)->value('adult_help'))->toBeFalse();

    // La correction survit à une modification de la recette.
    Livewire::test(\App\Livewire\Recipes\Edit::class, ['recipe' => $recipe->fresh()])->assertSet('form.kid_friendly', true)->call('save')->assertHasNoErrors();
    expect($recipe->steps()->where('position', 2)->value('adult_help'))->toBeFalse();

    Livewire::test(\App\Livewire\Recipes\Index::class)->set('kidsOnly', true)->assertSee('Cookies')->assertSee('Avec un enfant (1)');
});

/* ================================================================ R40 : foyers reliés, séjours */

test('les goûts d\'un enfant sont montrés aux foyers reliés seulement si un adulte l\'a choisi ; en séjour, ses allergies le suivent', function () {
    $leo = leo();
    app(HouseholdService::class)->addRestriction($leo, RestrictionType::Allergy, ingredientNamed('Arachide')->id);
    $household = \App\Support\CurrentHousehold::get();

    expect(app(\App\Services\Linked\LinkedEaters::class)->membersOf($household)->pluck('name')->all())->not->toContain('Léo ('.$household->name.')');

    $this->people->save($leo, [...$leo->only(['name', 'appetite', 'color', 'canteen_days', 'canteen_name']), 'share_tastes' => true]);
    $eater = app(\App\Services\Linked\LinkedEaters::class)->membersOf($household)->firstWhere('name', 'Léo ('.$household->name.')');
    expect($eater->shared)->toBeTrue()->and($eater->restrictions)->toHaveCount(1);

    $stays = app(\App\Services\Stays\StayService::class);
    $stay = $stays->create(['name' => 'Chalet', 'starts_on' => '2026-12-20', 'ends_on' => '2026-12-27']);
    $stays->addHousehold($stay);
    $participant = $stay->participants()->where('name', 'Léo')->sole();

    expect($participant->person_id)->toBe($leo->id)
        ->and($stays->eaters($stay->fresh())->firstWhere('participant.id', $participant->id)['restrictions'])->toHaveCount(1)
        ->and($stays->addHousehold($stay->fresh()))->toBe(0);
});

/* ================================================================ Reprise des données (migration) */

test('la migration reprend la table du lot 32, les contraintes et les gamelles des comptes', function () {
    $household = \App\Support\CurrentHousehold::id();
    $chili = dish('Chili', ['Haricots']);
    $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_11_03_100000_create_lot39_people.php'])->assertSuccessful();

    DB::table('settings')->insert(['household_id' => $household, 'key' => 'table.people', 'value' => json_encode([
        ['name' => 'Pierre', 'appetite' => 'grand', 'user_id' => $this->pierre->id],
        ['name' => 'Lina', 'appetite' => 'petit', 'user_id' => null],
    ]), 'created_at' => now(), 'updated_at' => now()]);
    $arachide = ingredientNamed('Arachide');
    DB::table('household_restrictions')->insert([
        ['household_id' => $household, 'user_id' => $this->pierre->id, 'type' => 'allergy', 'ingredient_id' => $arachide->id, 'tag_id' => null, 'note' => null, 'created_at' => now(), 'updated_at' => now()],
        ['household_id' => $household, 'user_id' => $this->monique->id, 'type' => 'dislike', 'ingredient_id' => $arachide->id, 'tag_id' => null, 'note' => 'cuites', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $source = DB::table('planned_meals')->insertGetId(['household_id' => $household, 'date' => '2026-11-03', 'meal_slot_id' => $this->dinner->id, 'position' => 1, 'type' => 'recipe', 'recipe_id' => $chili->id, 'servings' => 6, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('planned_meals')->insert(['household_id' => $household, 'date' => '2026-11-04', 'meal_slot_id' => $this->lunch->id, 'position' => 1, 'type' => 'leftover', 'leftover_of_id' => $source, 'servings' => 1, 'for_user_id' => $this->monique->id, 'is_lunchbox' => true, 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('migrate')->assertSuccessful();

    $people = HouseholdPerson::query()->ordered()->get();
    expect($people->map(fn ($p) => [$p->name, $p->appetite, $p->user_id, $p->at_table])->all())->toBe([
        ['Pierre', 'grand', $this->pierre->id, true],
        ['Lina', 'petit', null, true],
        ['Monique', 'normal', $this->monique->id, false],      // une contrainte et une gamelle, pas à table d'habitude
    ])->and(\App\Models\PersonRestriction::query()->with('person')->get()->map(fn ($r) => $r->person->name.' '.$r->type->value)->sort()->values()->all())->toBe(['Monique dislike', 'Pierre allergy'])
        ->and(PlannedMeal::query()->where('is_lunchbox', true)->sole()->forPerson->name)->toBe('Monique')
        ->and(DB::table('settings')->where('key', 'table.people')->exists())->toBeFalse()
        ->and($this->occasions->diners(null))->toBe(2.0);   // 1,5 + 0,5
})->skip(fn () => DB::getDriverName() !== 'sqlite', 'Changements de schéma dans une transaction : SQLite seulement (MariaDB et MySQL valident tout seuls).');
