<?php

use App\Enums\RestrictionType;
use App\Enums\UserRole;
use App\Livewire\Contributions\Board;
use App\Livewire\Stays\Index as StaysIndex;
use App\Livewire\Stays\Show as StayShow;
use App\Models\Contribution;
use App\Models\Guest;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\MealOccasion;
use App\Models\MealSlot;
use App\Models\Scopes\HouseholdScope;
use App\Models\ShoppingList;
use App\Models\ShoppingListAisleOwner;
use App\Models\ShoppingListItem;
use App\Models\StayHousehold;
use App\Models\StayMeal;
use App\Models\StayParticipant;
use App\Models\StayPayment;
use App\Models\StockItem;
use App\Models\User;
use App\Services\Households\HouseholdManager;
use App\Services\Linked\HouseholdLinks;
use App\Services\Linked\SharedMeals;
use App\Services\Shopping\ShoppingListGenerator;
use App\Services\Stays\StayCoorganizers;
use App\Services\Stays\StayService;
use App\Services\Stays\StayShopping;
use App\Services\Together\Contributions;
use App\Support\CurrentHousehold;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\MealSlotSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\TagSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

require_once __DIR__.'/../Shopping/helpers.php';

/*
 * Lot 42 — Ensemble : séjour co-organisé (42.1, R45), qui apporte quoi (42.2), courses à deux en
 * magasin (42.3). Données d'essai inventées.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00'));   // mercredi
    $this->seed([UnitSeeder::class, AisleSeeder::class, TagSeeder::class, MealSlotSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);

    $manager = app(HouseholdManager::class);
    $this->home = Household::query()->orderBy('id')->first();
    $this->home->update(['name' => 'Pierre et Monique']);
    $this->pierre = User::factory()->create(['name' => 'Pierre Tripodi']);
    $this->monique = User::factory()->create(['name' => 'Monique']);

    $this->martins = $manager->create('Les Martin');
    $this->leo = User::factory()->create(['name' => 'Léo Martin', 'share_restrictions' => false]);
    $manager->attach($this->martins, $this->leo, UserRole::Owner);
    $manager->detach($this->home, $this->leo);

    $links = app(HouseholdLinks::class);
    $links->accept($links->invite($this->home, $this->pierre)['link'], $this->martins, $this->leo);

    $this->actingAs($this->pierre);
    $this->dinner = MealSlot::query()->where('name', 'like', 'D%ner')->firstOrFail();
    $this->gratin = recipeWith('Gratin dauphinois', 4, [[1000, 'g', 'Pomme de terre', false], [40, 'cl', 'Crème liquide', false]]);
    $this->stays = app(StayService::class);
    $this->stay = $this->stays->create(['name' => 'Chalet à Vianden', 'place' => 'Vianden', 'starts_on' => '2026-10-24', 'ends_on' => '2026-10-27', 'notes' => 'Boîte à clés : 1234']);
    $this->stays->addHousehold($this->stay);
});

function togetherAs(User $user): void
{
    test()->actingAs($user);
    CurrentHousehold::forget();
    app()->forgetScopedInstances();
}

/** Les Martin co-organisent le séjour. */
function coorganize(): StayHousehold
{
    $row = app(StayCoorganizers::class)->invite(test()->stay, test()->martins->id, test()->pierre);
    togetherAs(test()->leo);
    $row = app(StayCoorganizers::class)->respond($row->id, true);
    togetherAs(test()->pierre);

    return $row;
}

/* ================================================================ 42.1 Séjour co-organisé */

test('inviter un foyer relié à co-organiser : il accepte, voit la fiche ; un autre foyer, non', function () {
    $voisins = app(HouseholdManager::class)->create('Les voisins');
    $zoe = User::factory()->create(['name' => 'Zoé']);
    app(HouseholdManager::class)->attach($voisins, $zoe, UserRole::Owner);
    app(HouseholdManager::class)->detach($this->home, $zoe);

    Livewire::test(StayShow::class, ['stay' => $this->stay])
        ->set('coorganizerId', (string) $voisins->id)->call('inviteCoorganizer')->assertHasErrors('coorganizerId')
        ->set('coorganizerId', (string) $this->martins->id)->call('inviteCoorganizer')->assertHasNoErrors();

    togetherAs($this->leo);
    $this->get(route('stays.show', $this->stay))->assertNotFound();
    $row = StayHousehold::query()->sole();

    Livewire::test(StaysIndex::class)
        ->assertSee('vous propose d\'organiser ensemble', false)->assertSee('Chalet à Vianden')
        ->call('respond', $row->id, true)
        ->assertRedirect(route('stays.show', ['stay' => $this->stay->id, 'onglet' => 'participants']));

    expect($row->fresh()->status)->toBe('accepted');
    $this->get(route('stays.show', $this->stay))->assertOk()
        ->assertSee('Séjour organisé par « Pierre et Monique »')
        ->assertSee('Boîte à clés : 1234');
    Livewire::test(StaysIndex::class)->assertSee('Chalet à Vianden')->assertSee('avec « Pierre et Monique »');

    // Un foyer qui ne co-organise pas ne voit rien.
    togetherAs($zoe);
    $this->get(route('stays.show', $this->stay))->assertNotFound();

    // Seul l'organisateur change le séjour.
    togetherAs($this->leo);
    Livewire::test(StayShow::class, ['stay' => $this->stay])
        ->set('name', 'Autre nom')->set('startsOn', '2026-10-24')->set('endsOn', '2026-10-27')->call('save');
    expect($this->stay->fresh()->name)->toBe('Chalet à Vianden');
});

test('chacun ses participants et ses plats ; une recette de l\'autre foyer ne montre que son titre', function () {
    coorganize();
    togetherAs($this->leo);
    $crepes = recipeWith('Crêpes de Léo', 4, [[250, 'g', 'Farine', false]]);
    $pierreRow = $this->stay->participants()->where('name', 'Pierre Tripodi')->firstOrFail();

    Livewire::test(StayShow::class, ['stay' => $this->stay->fresh()])
        ->call('addHousehold')
        ->call('updateParticipant', $pierreRow->id, 'appetite', 'grand')
        ->call('selectTab', 'repas')
        ->call('openCell', '2026-10-25', $this->dinner->id)
        ->call('pickRecipe', $crepes->id)
        ->assertHasNoErrors();

    $leoRow = StayParticipant::query()->where('name', 'Léo Martin')->sole();
    $meal = StayMeal::query()->sole();
    expect($leoRow->linked_household_id)->toBe($this->martins->id)
        ->and($leoRow->linked_user_id)->toBe($this->leo->id)
        ->and($leoRow->group_label)->toBe('Les Martin')
        ->and($pierreRow->fresh()->appetite)->toBe('normal')
        ->and($meal->household_id)->toBe($this->martins->id)
        ->and($meal->meal_slot_id)->toBe($this->dinner->id);

    togetherAs($this->pierre);
    $this->stays->addMeal($this->stay, '2026-10-26', $this->dinner->id, $this->gratin->id);
    $gratinMeal = StayMeal::query()->where('recipe_id', $this->gratin->id)->sole();

    Livewire::test(StayShow::class, ['stay' => $this->stay->fresh()])
        ->call('updateParticipant', $leoRow->id, 'appetite', 'petit')
        ->call('selectTab', 'repas')
        ->assertSee('Crêpes de Léo')->assertSee('prévu par « Les Martin »')
        ->assertDontSee(route('recipes.show', $crepes))
        ->assertSee(route('recipes.show', $this->gratin));
    expect($leoRow->fresh()->appetite)->toBe('normal');

    // Léo ne retire pas le plat de Pierre ; Pierre, organisateur, peut retirer tout.
    togetherAs($this->leo);
    Livewire::test(StayShow::class, ['stay' => $this->stay->fresh()])->call('removeMeal', $gratinMeal->id);
    expect($gratinMeal->fresh())->not->toBeNull();
});

test('alertes : chaque foyer vérifie ses convives ; l\'autre voit l\'alerte sans le nom', function () {
    coorganize();
    togetherAs($this->leo);
    $zoe = Guest::create(['name' => 'Zoé', 'appetite' => 'normal']);
    $zoe->restrictions()->create(['type' => RestrictionType::Allergy, 'ingredient_id' => Ingredient::firstWhere('name', 'Crème liquide')->id]);
    Livewire::test(StayShow::class, ['stay' => $this->stay->fresh()])->set('guestId', (string) $zoe->id)->call('addGuest')->assertHasNoErrors();

    togetherAs($this->pierre);
    $meal = $this->stays->addMeal($this->stay, '2026-10-25', $this->dinner->id, $this->gratin->id);
    $stay = $this->stay->fresh('participants');
    $messages = collect($this->stays->conflicts($meal->fresh(), $stay))->pluck('message');
    expect($messages->join(' '))->toContain('Crème liquide — allergie de quelqu\'un de « Les Martin »')
        ->not->toContain('Zoé');

    togetherAs($this->leo);
    $messages = collect($this->stays->conflicts($meal->fresh(), $this->stay->fresh('participants')))->pluck('message');
    expect($messages->join(' '))->toContain('allergie de Zoé');

    // Pierre voit Zoé dans les participants, pas sa fiche.
    togetherAs($this->pierre);
    Livewire::test(StayShow::class, ['stay' => $this->stay->fresh()])
        ->assertSee('Zoé')->assertSee('avec « Les Martin »')->assertDontSee('Allergie : Crème liquide');
});

test('frais : chacun note ses dépenses ; un foyer retiré garde les siennes, marquées', function () {
    coorganize();
    app(\App\Services\Stays\StayCosts::class)->addExpense($this->stay, 'Pierre et Monique', 'Location', 400);
    $pierrePayment = StayPayment::query()->sole();

    togetherAs($this->leo);
    Livewire::test(StayShow::class, ['stay' => $this->stay->fresh()])
        ->set('expenseGroup', 'Les Martin')->set('expenseLabel', 'Courses Cactus')->set('expenseAmount', '90,40')->call('addExpense')->assertHasNoErrors()
        ->call('setSplitMode', 'person')
        ->call('removePayment', $pierrePayment->id);

    $leoPayment = StayPayment::query()->where('label', 'Courses Cactus')->sole();
    expect($leoPayment->household_id)->toBe($this->martins->id)
        ->and($pierrePayment->fresh())->not->toBeNull()
        ->and($this->stay->fresh()->split_mode)->toBe('appetite');

    togetherAs($this->pierre);
    Livewire::test(StayShow::class, ['stay' => $this->stay->fresh()])
        ->call('removeCoorganizer', $this->martins->id)
        ->call('selectTab', 'frais')
        ->assertSee('Courses Cactus')->assertSee('qui ne fait plus partie du séjour');

    expect(StayHousehold::query()->sole()->status)->toBe('removed')
        ->and($leoPayment->fresh())->not->toBeNull();

    togetherAs($this->leo);
    $this->get(route('stays.show', $this->stay))->assertNotFound();
});

test('courses : la liste du séjour reste chez l\'organisateur ; un foyer qui co-organise la prépare et y coche en magasin', function () {
    coorganize();
    togetherAs($this->leo);
    $crepes = recipeWith('Crêpes de Léo', 4, [[250, 'g', 'Farine', false]]);
    $this->stays->addMeal($this->stay, '2026-10-25', $this->dinner->id, $crepes->id);

    Livewire::test(StayShow::class, ['stay' => $this->stay->fresh()])->call('selectTab', 'courses')->call('createList')->assertSee('Ouvrir la liste');

    $list = ShoppingList::query()->withoutGlobalScope(HouseholdScope::class)->where('stay_id', $this->stay->id)->sole();
    $flour = ShoppingListItem::query()->withoutGlobalScope(HouseholdScope::class)->where('shopping_list_id', $list->id)
        ->where('ingredient_id', Ingredient::firstWhere('name', 'Farine')->id)->sole();
    expect($list->household_id)->toBe($this->home->id)->and($list->shared_with_links)->toBeTrue();

    $this->get(route('shopping.store', $list))->assertOk()->assertSee(route('stays.show', ['stay' => $this->stay->id, 'onglet' => 'courses']), false);
    $this->postJson(route('shopping.sync', $list), ['operations' => [['uuid' => (string) \Illuminate\Support\Str::uuid(), 'action' => 'check', 'item_id' => $flour->id, 'label' => 'Farine', 'at' => now()->toIso8601String()]]])
        ->assertOk()->assertJsonPath('report.applied', 1);
    expect($flour->fresh()->is_checked)->toBeTrue()->and($flour->fresh()->checked_by)->toBe($this->leo->id);

    // La page Livewire de la liste, et les autres listes de Pierre : non.
    $this->get(route('shopping.show', $list))->assertNotFound();
    togetherAs($this->pierre);
    $homeList = app(\App\Services\Shopping\ShoppingListManager::class)->create(Carbon::parse('2026-10-14'), Carbon::parse('2026-10-20'));
    togetherAs($this->leo);
    $this->get(route('shopping.state', $homeList))->assertNotFound();
});

test('à emporter : chaque foyer de son stock, avec son propre départ', function () {
    coorganize();
    togetherAs($this->leo);
    $location = \App\Models\StorageLocation::create(['name' => 'Placard des Martin', 'type' => \App\Enums\LocationType::cases()[0], 'sort_order' => 1]);
    $leoCheese = StockItem::create(['label' => 'Tomme des Martin', 'quantity' => 1, 'is_present' => true, 'storage_location_id' => $location->id]);

    Livewire::test(StayShow::class, ['stay' => $this->stay->fresh()])
        ->call('selectTab', 'emporter')
        ->assertSee('Tomme des Martin')
        ->call('pack', $leoCheese->id)
        ->call('depart');

    expect($leoCheese->fresh()->finished_at)->not->toBeNull()
        ->and($this->stay->fresh()->departed_at)->toBeNull()
        ->and(StayHousehold::query()->sole()->departed_at)->not->toBeNull();

    togetherAs($this->pierre);
    Livewire::test(StayShow::class, ['stay' => $this->stay->fresh()])
        ->call('selectTab', 'emporter')
        ->assertDontSee('Tomme des Martin');

    // L'organisateur ne supprime pas un séjour dont des articles ne sont pas revenus, même chez l'autre foyer.
    Livewire::test(StayShow::class, ['stay' => $this->stay->fresh()])->call('delete')->assertHasErrors('delete');
});

/* ================================================================ 42.2 Qui apporte quoi */

test('réception : le foyer qui reçoit écrit ce qu\'il faut, un foyer invité s\'inscrit, les doublons se voient', function () {
    $occasion = MealOccasion::create(['date' => '2026-10-24', 'meal_slot_id' => $this->dinner->id, 'title' => 'Anniversaire de Monique']);
    $tart = recipeWith('Tarte aux pommes', 6, [[200, 'g', 'Farine', false], [100, 'g', 'Beurre', false]]);
    $dish = app(\App\Services\Receptions\Receptions::class)->addDish($occasion, $tart->id, \App\Enums\Course::Dessert, 6);
    app(SharedMeals::class)->invite($occasion, $this->martins->id, $this->pierre);

    Livewire::test(Board::class, ['subject' => 'occasion', 'subjectId' => $occasion->id])
        ->set('label', 'Vin rouge')->call('add')
        ->set('dish', 'p'.$dish->id)->call('add')
        ->assertSee('Vin rouge')->assertSee('Tarte aux pommes')->assertSee('Personne pour l\'instant', false);

    $wine = Contribution::query()->where('label', 'Vin rouge')->sole();
    $tartRow = Contribution::query()->where('planned_meal_id', $dish->id)->sole();

    togetherAs($this->leo);
    Livewire::test(Board::class, ['subject' => 'occasion', 'subjectId' => $occasion->id])
        ->call('take', $tartRow->id)
        ->set('label', 'vin rouge')->call('add')
        ->assertSee('Les Martin')->assertSee('en double ?');

    expect($tartRow->fresh()->by_label)->toBe('Les Martin')->and($tartRow->fresh()->by_household_id)->toBe($this->martins->id);

    // Léo ne retire pas ce que le foyer qui reçoit a écrit.
    Livewire::test(Board::class, ['subject' => 'occasion', 'subjectId' => $occasion->id])->call('remove', $wine->id);
    expect($wine->fresh())->not->toBeNull();

    // Le dessert apporté ne compte plus dans les courses de Pierre.
    togetherAs($this->pierre);
    $meals = app(ShoppingListGenerator::class)->meals(Carbon::parse('2026-10-24'), Carbon::parse('2026-10-24'));
    expect($meals->pluck('id')->all())->not->toContain($dish->id);

    // Un foyer qui n'est pas invité : rien.
    $voisins = app(HouseholdManager::class)->create('Les voisins');
    $zoe = User::factory()->create();
    app(HouseholdManager::class)->attach($voisins, $zoe, UserRole::Owner);
    app(HouseholdManager::class)->detach($this->home, $zoe);
    togetherAs($zoe);
    Livewire::test(Board::class, ['subject' => 'occasion', 'subjectId' => $occasion->id])->assertStatus(404);
});

test('séjour : un ingrédient apporté sort de la liste du séjour', function () {
    $this->stays->addMeal($this->stay, '2026-10-25', $this->dinner->id, $this->gratin->id);
    $list = app(StayShopping::class)->create($this->stay->fresh());
    $cream = Ingredient::firstWhere('name', 'Crème liquide');

    Livewire::test(Board::class, ['subject' => 'stay', 'subjectId' => $this->stay->id])
        ->set('label', 'Crème liquide')->set('by', 'Clara')->call('add')->assertHasNoErrors();

    $item = $list->items()->where('ingredient_id', $cream->id)->sole();
    expect(Contribution::query()->sole()->ingredient_id)->toBe($cream->id)
        ->and($item->stock_status)->toBe('covered')
        ->and($item->stock_note)->toBe('Apporté par Clara');
});

test('lien sans compte : on voit la liste, on s\'inscrit avec son prénom, seul le même navigateur se ravise', function () {
    $occasion = MealOccasion::create(['date' => '2026-10-24', 'meal_slot_id' => $this->dinner->id, 'title' => 'Anniversaire de Monique']);
    $contributions = app(Contributions::class);
    $bread = $contributions->add($occasion, 'Pain');
    ['url' => $url] = $contributions->createLink($occasion);
    $token = basename($url);

    // Le lien se recopie (jeton chiffré) ; un nouveau lien remplace l'ancien.
    expect($contributions->url($contributions->link($occasion)))->toBe($url);

    auth()->logout();
    $this->get($url)->assertOk()->assertSee('Anniversaire de Monique')->assertSee('Pain')->assertSee('Personne pour l\'instant', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $this->post(route('bring.update', ['token' => $token]), ['action' => 'take', 'id' => $bread->id, 'name' => 'Tante Lucie'])->assertRedirect($url);
    expect($bread->fresh()->by_label)->toBe('Tante Lucie')->and($bread->fresh()->token_hash)->not->toBeNull();

    $this->post(route('bring.update', ['token' => $token]), ['action' => 'add', 'label' => 'Gâteau au chocolat', 'name' => 'Tante Lucie'])->assertRedirect($url);
    $cake = Contribution::query()->where('label', 'Gâteau au chocolat')->sole();

    // Un autre navigateur (sans la clé) ne se ravise pas à sa place.
    $this->withCookie('bouffe_apporter', 'une-autre-cle')
        ->post(route('bring.update', ['token' => $token]), ['action' => 'release', 'id' => $bread->id])
        ->assertSessionHas('bring_error');
    expect($bread->fresh()->by_label)->toBe('Tante Lucie');

    // Le même navigateur, si : la ligne demandée redevient libre, celle qu'il avait ajoutée disparaît.
    $key = 'cle-de-lucie-'.str_repeat('x', 20);
    $bread->update(['token_hash' => Contributions::hash($key)]);
    $cake->update(['token_hash' => Contributions::hash($key)]);
    $this->withCookie('bouffe_apporter', $key)->post(route('bring.update', ['token' => $token]), ['action' => 'release', 'id' => $bread->id])->assertSessionHas('bring_status');
    $this->withCookie('bouffe_apporter', $key)->post(route('bring.update', ['token' => $token]), ['action' => 'release', 'id' => $cake->id]);
    expect($bread->fresh()->by_label)->toBeNull()->and($cake->fresh())->toBeNull();

    // Retiré, puis passé : le lien ne marche plus.
    $this->actingAs($this->pierre);
    $contributions->revokeLink($occasion);
    $this->get($url)->assertNotFound();
    ['url' => $again] = $contributions->createLink($occasion);
    $this->travelTo(Carbon::parse('2026-10-28 10:00'));
    $this->get($again)->assertNotFound();
});

/* ================================================================ 42.3 Courses à deux */

test('courses à deux : chacun ses rayons, l\'autre voit ce qui est pris', function () {
    $list = app(\App\Services\Shopping\ShoppingListManager::class)->create(Carbon::parse('2026-10-14'), Carbon::parse('2026-10-20'));
    app(\App\Services\Shopping\ShoppingListManager::class)->addManual($list, 'Lait');
    $item = $list->items()->firstOrFail();
    $aisle = (int) ($item->aisle_id ?? 0);

    $this->get(route('shopping.store', $list))->assertOk()->assertSee('On se partage ?');

    $this->postJson(route('shopping.aisles', $list), ['aisle_id' => $aisle, 'user_id' => $this->monique->id])
        ->assertOk()->assertJsonPath('split.owners.'.$aisle, $this->monique->id);
    $this->postJson(route('shopping.aisles', $list), ['aisle_id' => $aisle, 'user_id' => $this->leo->id])->assertStatus(422);

    $people = collect($this->getJson(route('shopping.state', $list))->json('split.people'))->pluck('name')->all();
    expect($people)->toContain('Pierre')->toContain('Monique')->not->toContain('Léo');

    // Monique coche : Pierre voit « pris par Monique ».
    $this->actingAs($this->monique);
    $this->postJson(route('shopping.sync', $list), ['operations' => [['uuid' => (string) \Illuminate\Support\Str::uuid(), 'action' => 'check', 'item_id' => $item->id, 'label' => 'Lait', 'at' => now()->toIso8601String()]]])->assertOk();
    $this->actingAs($this->pierre);
    $state = $this->getJson(route('shopping.state', $list))->json();
    expect(collect($state['items'])->firstWhere('id', $item->id)['checked_name'])->toBe('Monique');

    $this->postJson(route('shopping.aisles', $list), ['reset' => true])->assertOk();
    expect(ShoppingListAisleOwner::query()->count())->toBe(0);
});

test('courses à deux, liste d\'un séjour co-organisé : ceux des deux foyers peuvent se partager les rayons', function () {
    coorganize();
    $this->stays->addMeal($this->stay, '2026-10-25', $this->dinner->id, $this->gratin->id);
    $list = app(StayShopping::class)->create($this->stay->fresh());

    $people = collect($this->getJson(route('shopping.state', $list))->json('split.people'))->pluck('name')->all();
    expect($people)->toContain('Léo');
    $this->postJson(route('shopping.aisles', $list), ['aisle_id' => 0, 'user_id' => $this->leo->id])->assertOk();
});

/* ================================================================ Données */

test('un foyer qui co-organise supprimé : ses plats gardent leur nom, ses dépenses restent', function () {
    coorganize();
    togetherAs($this->leo);
    $crepes = recipeWith('Crêpes de Léo', 4, [[250, 'g', 'Farine', false]]);
    $meal = $this->stays->addMeal($this->stay, '2026-10-25', $this->dinner->id, $crepes->id);
    app(\App\Services\Stays\StayCosts::class)->addExpense($this->stay, 'Les Martin', 'Bois', 30);

    togetherAs($this->pierre);
    app(\App\Services\Households\HouseholdData::class)->destroy($this->martins);

    expect($meal->fresh()->recipe_id)->toBeNull()
        ->and($meal->fresh()->free_text)->toBe('Crêpes de Léo')
        ->and(StayPayment::query()->where('label', 'Bois')->exists())->toBeTrue()
        ->and(StayHousehold::query()->count())->toBe(0);
});

test('la migration du lot 42 s\'annule et se réapplique', function () {
    Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_11_17_100000_create_lot42_together.php']);
    expect(Schema::hasTable('stay_households'))->toBeFalse()->and(Schema::hasColumn('stay_meals', 'household_id'))->toBeFalse();

    Artisan::call('migrate', ['--path' => 'database/migrations/2026_11_17_100000_create_lot42_together.php']);
    expect(Schema::hasTable('contributions'))->toBeTrue()->and(Schema::hasTable('shopping_list_aisle_owners'))->toBeTrue();
});
