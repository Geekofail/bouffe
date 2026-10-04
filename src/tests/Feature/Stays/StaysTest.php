<?php

use App\Enums\RestrictionType;
use App\Enums\UserRole;
use App\Livewire\Planner\Week;
use App\Livewire\Shopping\Show as ShoppingShow;
use App\Livewire\Stays\Index as StaysIndex;
use App\Livewire\Stays\Show as StayShow;
use App\Models\Guest;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\ShoppingList;
use App\Models\Stay;
use App\Models\StayPayment;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Models\User;
use App\Services\Households\HouseholdManager;
use App\Services\Linked\HouseholdLinks;
use App\Services\Planning\Appetites;
use App\Services\Shopping\ShoppingListManager;
use App\Services\Stays\StayCosts;
use App\Services\Stays\StayPacking;
use App\Services\Stays\StayService;
use App\Services\Stays\StayShopping;
use App\Support\CurrentHousehold;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

require_once __DIR__.'/../Shopping/helpers.php';

/*
 * Lot 34 — Séjours et grandes tablées : le séjour et ses participants (34.1), son planning (34.1),
 * ses courses (34.2), les frais partagés (34.3, R37), ce qu'on emporte de la maison (34.4).
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00'));   // mercredi
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);
    $this->home = Household::query()->orderBy('id')->first();
    $this->home->update(['name' => 'Pierre et Monique']);
    $this->actingAs($this->pierre = User::factory()->create(['name' => 'Pierre']));
    $this->monique = User::factory()->create(['name' => 'Monique']);
    app(Appetites::class)->saveTable([
        ['name' => 'Pierre', 'appetite' => 'normal', 'user_id' => $this->pierre->id],
        ['name' => 'Monique', 'appetite' => 'normal', 'user_id' => $this->monique->id],
        ['name' => 'Lou', 'appetite' => 'petit', 'user_id' => null],
    ]);
    $this->lunch = MealSlot::factory()->create(['name' => 'Déjeuner', 'sort_order' => 1]);
    $this->dinner = MealSlot::factory()->create(['name' => 'Dîner', 'sort_order' => 2]);

    $this->gratin = recipeWith('Gratin dauphinois', 4, [[1000, 'g', 'Pomme de terre', false], [40, 'cl', 'Crème liquide', false]]);
    $this->stays = app(StayService::class);
    $this->stay = $this->stays->create(['name' => 'Chalet à Vianden', 'place' => 'Vianden', 'starts_on' => '2026-10-24', 'ends_on' => '2026-10-27']);
});

/* ================================================================ 34.1 Le séjour et ses participants */

test('créer un séjour : les personnes à table d\'habitude sont ajoutées, dates contrôlées', function () {
    Livewire::test(StaysIndex::class)
        ->call('open')
        ->set('name', 'Week-end chez les parents')
        ->set('startsOn', '2026-11-07')->set('endsOn', '2026-11-08')
        ->call('create')
        ->assertHasNoErrors();

    $stay = Stay::where('name', 'Week-end chez les parents')->sole();
    expect($stay->participants->pluck('name')->all())->toBe(['Pierre', 'Monique', 'Lou'])
        ->and($stay->participants->pluck('group_label')->unique()->all())->toBe(['Pierre et Monique'])
        ->and($stay->period())->toBe('du 7 au 8 novembre 2026');

    Livewire::test(StaysIndex::class)->call('open')->set('name', 'Trop long')->set('startsOn', '2026-11-01')->set('endsOn', '2026-12-15')->call('create')
        ->assertHasErrors('endsOn');
    Livewire::test(StaysIndex::class)->call('open')->set('name', 'À l\'envers')->set('startsOn', '2026-11-09')->set('endsOn', '2026-11-08')->call('create')
        ->assertHasErrors('endsOn');

    $this->get(route('stays.index'))->assertOk()->assertSee('Week-end chez les parents')->assertSee('Chalet à Vianden');
});

test('participants : invités du carnet, autres personnes, appétits, présence et portions du jour', function () {
    $this->stays->addHousehold($this->stay);
    expect($this->stays->addHousehold($this->stay))->toBe(0);   // pas de doublon

    $mamie = Guest::factory()->create(['name' => 'Mamie Jeanne', 'group_name' => 'Les grands-parents', 'appetite' => 'moyen']);
    $mamie->restrictions()->create(['type' => RestrictionType::Allergy, 'ingredient_id' => Ingredient::firstWhere('name', 'Crème liquide')->id]);
    $this->stays->addGuest($this->stay, $mamie);
    expect(fn () => $this->stays->addGuest($this->stay, $mamie))->toThrow(InvalidArgumentException::class, 'participe déjà');

    $marc = $this->stays->addPerson($this->stay, 'Marc', 'grand', 'Les grands-parents');
    $this->stays->updateParticipant($marc, ['present_from' => '2026-10-26']);
    expect(fn () => $this->stays->updateParticipant($marc, ['present_to' => '2026-10-25']))->toThrow(InvalidArgumentException::class, 'précède');

    $stay = $this->stay->fresh('participants');
    // 1 + 1 + 0,5 (Lou) + 0,75 (Mamie) = 3,25 → 3,5 ; Marc (1,5) arrive le 26.
    expect($this->stays->portionsOn($stay, Carbon::parse('2026-10-24')))->toBe(3.5)
        ->and($this->stays->portionsOn($stay, Carbon::parse('2026-10-26')))->toBe(5.0)
        ->and($marc->fresh()->daysPresent($stay))->toBe(2);

    Livewire::test(StayShow::class, ['stay' => $this->stay])
        ->assertSee('Les grands-parents')
        ->assertSee('Allergie : Crème liquide')
        ->call('updateParticipant', $marc->id, 'appetite', 'normal')
        ->assertHasNoErrors();
    expect($marc->fresh()->appetite)->toBe('normal');
});

test('participants : un foyer relié vient, avec les contraintes que ses membres partagent', function () {
    $manager = app(HouseholdManager::class);
    $martins = $manager->create('Les Martin');
    $leo = User::factory()->create(['name' => 'Léo', 'share_restrictions' => true]);
    $clara = User::factory()->create(['name' => 'Clara', 'share_restrictions' => false]);
    foreach ([$leo, $clara] as $user) {
        $manager->attach($martins, $user, UserRole::Owner);
        $manager->detach($this->home, $user);
    }
    CurrentHousehold::run($martins, fn () => app(\App\Services\Planning\HouseholdService::class)->addRestriction($leo, RestrictionType::Allergy, Ingredient::firstWhere('name', 'Pomme de terre')->id));

    expect(fn () => $this->stays->addLinkedHousehold($this->stay, $martins))->toThrow(InvalidArgumentException::class, 'pas relié');

    $links = app(HouseholdLinks::class);
    $links->accept($links->invite($this->home, $this->pierre)['link'], $martins, $leo);

    expect($this->stays->addLinkedHousehold($this->stay, $martins))->toBe(2);
    $stay = $this->stay->fresh('participants');
    $meal = $this->stays->addMeal($stay, '2026-10-25', $this->dinner->id, $this->gratin->id);

    $conflicts = $this->stays->conflicts($meal->fresh('recipe'), $stay);
    expect($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['level'])->toBe('danger')
        ->and($conflicts[0]['message'])->toContain('Pomme de terre');

    Livewire::test(StayShow::class, ['stay' => $this->stay])
        ->assertSee('Les Martin')
        ->assertSee('Contraintes non partagées');

    // La liste du séjour s'ouvre aux foyers reliés qui viennent (26.8).
    expect(app(StayShopping::class)->create($stay)->shared_with_links)->toBeTrue();
});

/* ================================================================ 34.1 Le planning du séjour */

test('planning du séjour : portions d\'après les présents, fixées à la main, rien dans le planning de la maison', function () {
    $this->stays->addHousehold($this->stay);
    $this->stays->addPerson($this->stay, 'Marc', 'grand', 'Marc');

    Livewire::test(StayShow::class, ['stay' => $this->stay, 'tab' => 'repas'])
        ->call('selectTab', 'repas')
        ->call('openCell', '2026-10-25', $this->dinner->id)
        ->set('recipeSearch', 'gratin')
        ->assertSee('Gratin dauphinois')
        ->call('pickRecipe', $this->gratin->id)
        ->call('openCell', '2026-10-26', $this->lunch->id)
        ->set('freeText', 'Restaurant')
        ->call('addFreeText')
        ->call('openCell', '2026-11-02', $this->lunch->id)
        ->set('freeText', 'Hors séjour')
        ->call('addFreeText')
        ->assertHasErrors('cell')
        ->assertSee('Gratin dauphinois')
        ->assertSee('Restaurant');

    $meal = $this->stay->meals()->whereNotNull('recipe_id')->sole();
    $stay = $this->stay->fresh('participants');
    // 1 + 1 + 0,5 + 1,5 = 4 portions
    expect($this->stays->servingsFor($meal, $stay))->toBe(4.0);
    $this->stays->setServings($meal, '6');
    expect($this->stays->servingsFor($meal->fresh(), $stay))->toBe(6.0);

    expect(PlannedMeal::count())->toBe(0);
    Livewire::test(Week::class)->set('week', '2026-10-19')->assertSee('Chalet à Vianden')->assertSee('ses repas et ses courses sont dans le séjour');

    // Raccourcir le séjour retire les repas hors des nouvelles dates.
    expect($this->stays->update($this->stay, ['name' => 'Chalet', 'starts_on' => '2026-10-24', 'ends_on' => '2026-10-25']))->toBe(1);
});

/* ================================================================ 34.2 Courses du séjour */

test('courses du séjour : calculées sur ses repas, sans le stock, hors des listes de la maison', function () {
    $this->stays->addHousehold($this->stay);    // 2,5 portions → 2,5
    $stay = $this->stay->fresh('participants');
    $this->stays->addMeal($stay, '2026-10-25', $this->dinner->id, $this->gratin->id);
    StockItem::create(['ingredient_id' => Ingredient::firstWhere('name', 'Pomme de terre')->id, 'quantity' => 5000, 'unit_id' => Unit::firstWhere('code', 'g')->id,
        'is_present' => true, 'storage_location_id' => StorageLocation::query()->value('id')]);
    // Un repas de la maison le même jour : pas dans la liste du séjour.
    PlannedMeal::factory()->create(['date' => '2026-10-25', 'meal_slot_id' => $this->lunch->id, 'recipe_id' => recipeWith('Salade', 2, [[1, null, 'Concombre', false]])->id]);

    $list = app(StayShopping::class)->create($stay);
    $items = $list->items()->with('unit', 'sources')->get()->keyBy('label');

    expect($list->stay_id)->toBe($this->stay->id)
        ->and($list->deduct_stock)->toBeFalse()
        ->and($items->keys()->sort()->values()->all())->toBe(['Crème liquide', 'Pomme de terre'])
        ->and((float) $items['Pomme de terre']->quantity)->toBe(625.0)          // 1 kg × 2,5 / 4 : le stock de la maison ne compte pas
        ->and($items['Pomme de terre']->stock_status)->toBeNull()
        ->and($items['Pomme de terre']->sources->first()->planned_meal_id)->toBeNull()
        ->and($items['Pomme de terre']->sources->first()->recipe_title)->toBe('Gratin dauphinois');

    // Hors des « listes en cours » : l'ajout rapide ne s'y perd pas.
    expect(ShoppingList::query()->active()->count())->toBe(0);
    $home = app(ShoppingListManager::class)->currentOrNew();
    expect($home->id)->not->toBe($list->id);

    // « Mettre à jour » depuis la liste recalcule d'après les repas du séjour.
    $this->stays->addPerson($stay, 'Marc', 'grand', 'Marc');
    app(ShoppingListManager::class)->regenerate($list);
    expect((float) $list->items()->where('label', 'Pomme de terre')->value('quantity'))->toBe(1000.0);   // 4 portions

    Livewire::test(ShoppingShow::class, ['shoppingList' => $list])
        ->assertSee('Séjour « Chalet à Vianden »', false)
        ->assertViewHas('toPutAway', 0);
    Livewire::test(StayShow::class, ['stay' => $this->stay])->call('selectTab', 'courses')->assertSee('Ouvrir la liste');
});

/* ================================================================ 34.4 Emporter de la maison */

test('à emporter : déduit des courses, retiré du stock au départ, le reste remis au retour', function () {
    $this->stays->addHousehold($this->stay);
    $stay = $this->stay->fresh('participants');
    $this->stays->addMeal($stay, '2026-10-25', $this->dinner->id, $this->gratin->id);
    $g = Unit::firstWhere('code', 'g');
    $location = StorageLocation::query()->value('id');
    $potatoes = StockItem::create(['ingredient_id' => Ingredient::firstWhere('name', 'Pomme de terre')->id, 'quantity' => 2000, 'unit_id' => $g->id, 'is_present' => true, 'storage_location_id' => $location]);
    $lasagnes = StockItem::create(['label' => 'Lasagnes maison', 'quantity' => 1, 'is_present' => true, 'storage_location_id' => $location]);
    $list = app(StayShopping::class)->create($stay);
    $packing = app(StayPacking::class);

    Livewire::test(StayShow::class, ['stay' => $this->stay])
        ->call('selectTab', 'emporter')
        ->assertSee('Lasagnes maison')
        ->set('packQuantities.'.$potatoes->id, '500')
        ->call('pack', $potatoes->id)
        ->call('pack', $lasagnes->id)
        ->set('packQuantities.'.$potatoes->id, '9000')
        ->assertSee('prévu');

    expect(fn () => $packing->pack($stay, $potatoes, 9000))->toThrow(InvalidArgumentException::class, 'entre 0 et');

    // 625 g nécessaires − 500 g emportés = 125 g à acheter.
    $item = $list->items()->where('label', 'Pomme de terre')->sole();
    expect((float) $item->quantity)->toBe(125.0)->and($item->stock_status)->toBe('partial')->and($item->stock_note)->toContain('emporté de la maison');

    // Rien ne bouge dans le stock avant le départ.
    expect((float) $potatoes->fresh()->quantity)->toBe(2000.0);
    expect($packing->depart($stay))->toBe(2);
    expect((float) $potatoes->fresh()->quantity)->toBe(1500.0)
        ->and($lasagnes->fresh()->finished_at)->not->toBeNull()
        ->and(StockMovement::where('reason', StayPacking::REASON)->count())->toBe(1);

    // Au retour : 200 g de pommes de terre reviennent, les lasagnes ont été mangées.
    $packed = $this->stay->packedItems()->get()->keyBy('label');
    Livewire::test(StayShow::class, ['stay' => $this->stay])
        ->call('selectTab', 'emporter')
        ->set('returned.'.$packed['Pomme de terre']->id, '200')
        ->set('returned.'.$packed['Lasagnes maison']->id, '0')
        ->call('returnHome');

    expect((float) $potatoes->fresh()->quantity)->toBe(1700.0)
        ->and(StockItem::query()->active()->where('label', 'Lasagnes maison')->exists())->toBeFalse()
        ->and($this->stay->fresh()->returned_at)->not->toBeNull();
});

test('à emporter : un article déjà fini à la maison revient comme nouvel article ; on ne retire pas ce qui est parti', function () {
    $location = StorageLocation::query()->value('id');
    $cheese = StockItem::create(['label' => 'Tomme de Savoie', 'quantity' => 1, 'is_present' => true, 'storage_location_id' => $location]);
    $packing = app(StayPacking::class);

    $packed = $packing->pack($this->stay, $cheese);
    $packing->depart($this->stay);
    expect(fn () => $packing->unpack($packed->fresh()))->toThrow(InvalidArgumentException::class, 'Déjà parti');

    $packing->returnHome($this->stay->fresh(), [$packed->id => '1']);
    expect(StockItem::query()->active()->where('label', 'Tomme de Savoie')->sole()->note)->toBe('Revenu du séjour');
});

/* ================================================================ 34.3 Frais partagés (R37) */

test('frais : part par appétit et jours de présence, arrondi au plus gros payeur, remboursements minimaux', function () {
    $this->stays->addHousehold($this->stay);                                              // 1 + 1 + 0,5 = 2,5 × 4 jours = 10
    $this->stays->addPerson($this->stay, 'Marc', 'normal', 'Les Dupont');                 // 1 × 4 = 4
    $anne = $this->stays->addPerson($this->stay, 'Anne', 'normal', 'Les Dupont');         // 1 × 2 = 2
    $this->stays->updateParticipant($anne, ['present_from' => '2026-10-26']);
    $this->stays->addPerson($this->stay, 'Zoé', 'normal', 'Zoé');                          // 1 × 4 = 4
    $costs = app(StayCosts::class);
    $stay = $this->stay->fresh();

    $costs->addExpense($stay, 'Pierre et Monique', 'Courses Cactus', '150,00', '2026-10-24');
    $costs->addExpense($stay, 'Les Dupont', 'Location', '50', '2026-10-24');
    expect(fn () => $costs->addExpense($stay, 'Zoé', 'Rien', '0'))->toThrow(InvalidArgumentException::class);

    $summary = $costs->summary($stay->fresh());
    $groups = collect($summary['groups'])->keyBy('label');

    // Total 200 € pour un poids de 20 : 100 / 60 / 40.
    expect($summary['total'])->toBe(200.0)
        ->and($summary['weight'])->toBe(20.0)
        ->and($groups['Pierre et Monique']['share'])->toBe(100.0)
        ->and($groups['Les Dupont']['share'])->toBe(60.0)
        ->and($groups['Zoé']['share'])->toBe(40.0)
        ->and($groups['Pierre et Monique']['balance'])->toBe(50.0)
        ->and($summary['transfers'])->toBe([
            ['from' => 'Zoé', 'to' => 'Pierre et Monique', 'amount' => 40.0],
            ['from' => 'Les Dupont', 'to' => 'Pierre et Monique', 'amount' => 10.0],
        ]);

    // Par personne : 6 personnes, 22 jours-personne (Lou compte pour 1, Anne pour 2 jours).
    $stay->update(['split_mode' => 'person']);
    $person = collect($costs->summary($stay->fresh())['groups'])->keyBy('label');
    expect(round($person['Pierre et Monique']['share'] + $person['Les Dupont']['share'] + $person['Zoé']['share'], 2))->toBe(200.0)
        ->and($person['Pierre et Monique']['share'])->toBe(109.09)
        ->and($person['Les Dupont']['share'])->toBe(54.55);

    // « Remboursé » : noté, les comptes s'équilibrent.
    $stay->update(['split_mode' => 'appetite']);
    Livewire::test(StayShow::class, ['stay' => $this->stay])
        ->call('selectTab', 'frais')
        ->assertSee('Zoé')
        ->call('settle', 'Zoé', 'Pierre et Monique', '40')
        ->call('settle', 'Les Dupont', 'Pierre et Monique', '10')
        ->call('settle', 'Les Dupont', 'Pierre et Monique', '999')     // pas proposé : ignoré
        ->assertSee('Les comptes sont équilibrés');

    expect(StayPayment::where('kind', StayPayment::REFUND)->count())->toBe(2);
});

test('frais : l\'arrondi restant va à celui qui a le plus avancé', function () {
    foreach (['Anne', 'Bruno', 'Chloé'] as $name) {
        $this->stays->addPerson($this->stay, $name, 'normal', $name);
    }
    $costs = app(StayCosts::class);
    $costs->addExpense($this->stay, 'Bruno', 'Courses', '100');
    $costs->addExpense($this->stay, 'Anne', 'Pain', '0,01');

    $groups = collect($costs->summary($this->stay->fresh())['groups'])->keyBy('label');
    // 100,01 / 3 = 33,3366… → 33,34 + 33,34 + 33,34 = 100,02 : le centime de trop est retiré à Bruno.
    expect($groups->pluck('share')->sum())->toEqualWithDelta(100.01, 0.001)
        ->and($groups['Bruno']['share'])->toBe(33.33)
        ->and($groups['Anne']['share'])->toBe(33.34);
});

test('remboursements : paires qui s\'annulent d\'abord, jamais plus que « groupes − 1 »', function () {
    $costs = app(StayCosts::class);

    expect($costs->transfers(['A' => -3000, 'B' => 3000, 'C' => -1000, 'D' => 500, 'E' => 500]))
        ->toBe([['A', 'B', 3000], ['C', 'D', 500], ['C', 'E', 500]]);

    $balances = ['A' => 4523, 'B' => -1234, 'C' => -2000, 'D' => -1289, 'E' => 0];
    $transfers = $costs->transfers($balances);
    expect(count($transfers))->toBeLessThanOrEqual(3)
        ->and(array_sum(array_column($transfers, 2)))->toBe(4523);
});

/* ================================================================ Sécurité et nettoyage */

test('un séjour reste dans son foyer ; le supprimer supprime sa liste', function () {
    $list = app(StayShopping::class)->create($this->stay);

    $other = app(HouseholdManager::class)->create('Les voisins');
    $voisin = User::factory()->create();
    app(HouseholdManager::class)->attach($other, $voisin, UserRole::Owner);
    app(HouseholdManager::class)->detach($this->home, $voisin);
    $this->actingAs($voisin);
    $this->get(route('stays.show', $this->stay))->assertNotFound();

    $this->actingAs($this->pierre);
    Livewire::test(StayShow::class, ['stay' => $this->stay])->call('delete')->assertRedirect(route('stays.index'));
    expect(Stay::count())->toBe(0)->and(ShoppingList::find($list->id))->toBeNull();
});

test('un compte en consultation voit le séjour sans rien modifier', function () {
    $viewer = User::factory()->create(['role' => UserRole::Viewer]);
    $this->actingAs($viewer);

    $this->get(route('stays.show', $this->stay))->assertOk()->assertDontSee('Nouveau séjour');
    Livewire::test(StayShow::class, ['stay' => $this->stay])->call('addHousehold');
    expect($this->stay->participants()->count())->toBe(0);
});
