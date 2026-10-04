<?php

use App\Enums\MovementType;
use App\Enums\UserRole;
use App\Livewire\Account\Show as Account;
use App\Livewire\Linked\Accept;
use App\Livewire\Linked\GroupList;
use App\Livewire\Linked\Index as Proches;
use App\Livewire\Linked\Planning as LinkedPlanning;
use App\Livewire\Linked\SharedMeal;
use App\Livewire\Linked\SurplusPage;
use App\Livewire\Receptions\Show as ReceptionShow;
use App\Livewire\Recipes\Index as RecipesIndex;
use App\Livewire\Recipes\Show as RecipeShow;
use App\Models\Household;
use App\Models\HouseholdLink;
use App\Models\Ingredient;
use App\Models\MealOccasion;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\RecipeRating;
use App\Models\Scopes\HouseholdScope;
use App\Models\ShoppingListItem;
use App\Models\StockItem;
use App\Models\SurplusOffer;
use App\Models\Unit;
use App\Models\User;
use App\Services\Households\HouseholdData;
use App\Services\Households\HouseholdManager;
use App\Services\Linked\CalendarFeed;
use App\Services\Linked\GroupLists;
use App\Services\Linked\HouseholdLinks;
use App\Services\Linked\LinkedFeed;
use App\Services\Linked\RecipeCopier;
use App\Services\Linked\SharedMeals;
use App\Services\Linked\SharedRecipes;
use App\Services\Linked\Surplus;
use App\Services\Planning\HouseholdService;
use App\Services\Planning\OccasionService;
use App\Services\Planning\WeekPlanner;
use App\Services\Pricing\CostCalculator;
use App\Services\Shopping\ShoppingListManager;
use App\Services\Stock\PutAwayService;
use App\Services\Stock\StockManager;
use App\Support\CurrentHousehold;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\MealSlotSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\TagSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Entre foyers (lot 26 — module 26 du document 07, règle R31, idée C4).
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00'));   // un mercredi
    $this->seed([UnitSeeder::class, AisleSeeder::class, TagSeeder::class, MealSlotSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);

    $manager = app(HouseholdManager::class);
    $this->home = Household::query()->orderBy('id')->first();
    $this->home->update(['name' => 'Pierre et Monique']);
    $this->pierre = User::factory()->create(['name' => 'Pierre']);
    $this->monique = User::factory()->create(['name' => 'Monique', 'role' => 'viewer']);

    $this->other = $manager->create('Léo et Clara', $this->pierre);
    $this->leo = User::factory()->create(['name' => 'Léo']);
    $manager->attach($this->other, $this->leo, UserRole::Owner);
    $manager->detach($this->home, $this->leo);

    $this->third = $manager->create('Les voisins', $this->pierre);
    $this->links = app(HouseholdLinks::class);

    $this->actingAs($this->pierre);
});

/** Relie le foyer de Pierre à celui de Léo. */
function linkHomes(): HouseholdLink
{
    $test = test();
    $invite = $test->links->invite($test->home, $test->pierre);

    return $test->links->accept($invite['link'], $test->other, $test->leo);
}

/** Passe dans le foyer de Léo. */
function asLeo(): void
{
    test()->actingAs(test()->leo);
    CurrentHousehold::forget();
}

function asPierre(): void
{
    test()->actingAs(test()->pierre);
    CurrentHousehold::forget();
}

/** Une recette avec deux ingrédients et deux étapes, dans le foyer actif. */
function recipeWithLines(string $title, string $visibility = 'private'): Recipe
{
    $recipe = Recipe::factory()->create(['title' => $title, 'visibility' => $visibility, 'servings' => 4]);
    $recipe->ingredients()->create(['ingredient_id' => Ingredient::firstWhere('name', 'Beurre')->id, 'quantity' => 100, 'unit_id' => Unit::firstWhere('code', 'g')->id, 'sort_order' => 1]);
    $recipe->ingredients()->create(['ingredient_id' => Ingredient::firstWhere('name', 'Farine')->id, 'quantity' => 250, 'unit_id' => Unit::firstWhere('code', 'g')->id, 'sort_order' => 2]);
    $recipe->steps()->create(['position' => 1, 'instruction' => 'Mélanger.']);
    $recipe->steps()->create(['position' => 2, 'instruction' => 'Cuire 30 minutes.']);

    return $recipe;
}

/* ================================================================ Foyers reliés */

test('relier deux foyers : lien à usage unique, rien d\'ouvert par défaut, lien défait des deux côtés', function () {
    $invite = $this->links->invite($this->home, $this->pierre);

    expect($invite['link']->token_hash)->toBe(HouseholdLink::hash($invite['token']))
        ->and(fn () => $this->links->accept($invite['link'], $this->home))->toThrow(InvalidArgumentException::class, 'propre foyer');

    $this->links->accept($invite['link'], $this->other, $this->leo);

    expect($this->links->areLinked($this->home->id, $this->other->id))->toBeTrue()
        ->and($this->links->areLinked($this->other->id, $this->home->id))->toBeTrue()
        ->and($this->links->share($this->home->id, $this->other->id)->planning)->toBe('none')
        ->and($this->links->share($this->home->id, $this->other->id)->recipes_all)->toBeFalse()
        ->and($this->links->findInvitation($invite['token']))->toBeNull()                                  // usage unique
        ->and(fn () => $this->links->accept($invite['link']->fresh(), $this->third))->toThrow(InvalidArgumentException::class);

    // Lien expiré
    $late = $this->links->invite($this->home, $this->pierre);
    $this->travel(8)->days();
    expect(fn () => $this->links->accept($late['link'], $this->third))->toThrow(InvalidArgumentException::class, 'plus valable');

    $this->links->unlink($this->other->id, $this->home->id);
    expect($this->links->linkedIds($this->home->id))->toBe([])
        ->and(DB::table('household_shares')->count())->toBe(0);
});

test('page Proches : le responsable crée un lien, l\'autre responsable l\'accepte ; un compte simple ne peut pas', function () {
    $component = Livewire::test(Proches::class)->call('createLink')->assertSee('/proches/relier/');
    $url = $component->get('linkUrl');
    $token = \Illuminate\Support\Str::afterLast($url, '/');

    asLeo();
    $this->get(route('linked.accept', $token))->assertOk()->assertSee('Pierre et Monique')->assertSee('propose de relier');
    Livewire::test(Accept::class, ['token' => $token])->call('accept')->assertRedirect(route('linked.index'));

    expect($this->links->areLinked($this->home->id, $this->other->id))->toBeTrue();

    asPierre();
    Livewire::test(Proches::class)->assertSee('Léo et Clara')->assertSee('Planning fermé');

    $this->actingAs($this->monique);
    CurrentHousehold::forget();
    Livewire::test(Proches::class)->call('createLink')->assertForbidden();
    Livewire::test(Proches::class)->call('unlink', $this->other->id)->assertForbidden();
});

/* ================================================================ 26.1 — recettes partagées */

test('visibilité : privée par défaut, foyers reliés, toute l\'installation, tout le carnet ouvert', function () {
    $private = recipeWithLines('Tarte privée');
    $linked = recipeWithLines('Quiche des proches', 'linked');
    $everyone = recipeWithLines('Soupe pour tous', 'instance');
    $archived = recipeWithLines('Vieille recette', 'instance');
    $archived->update(['archived_at' => now()]);

    expect((new Recipe)->visibility)->toBe('private');

    $visible = fn (Household $h) => CurrentHousehold::run($h, fn () => app(SharedRecipes::class)->visibleQuery()->pluck('title')->sort()->values()->all());

    expect($visible($this->other))->toBe(['Soupe pour tous'])           // pas encore reliés
        ->and($visible($this->third))->toBe(['Soupe pour tous']);

    linkHomes();
    expect($visible($this->other))->toBe(['Quiche des proches', 'Soupe pour tous'])
        ->and($visible($this->third))->toBe(['Soupe pour tous']);

    $this->links->updateShare($this->home->id, $this->other->id, true, 'none');
    expect($visible($this->other))->toBe(['Quiche des proches', 'Soupe pour tous', 'Tarte privée'])
        ->and($private->fresh()->visibility)->toBe('private');

    // Foyer désactivé : plus rien de lui n'est visible.
    app(HouseholdManager::class)->setDisabled($this->home, true);
    expect($visible($this->other))->toBe([]);
});

test('onglet « Recettes des proches » : leurs recettes, avec leur foyer, filtrables', function () {
    recipeWithLines('Quiche de Pierre', 'linked');
    linkHomes();
    asLeo();
    recipeWithLines('Gratin de Léo');

    Livewire::test(RecipesIndex::class)->assertSee('Gratin de Léo')->assertDontSee('Quiche de Pierre')->assertSee('Proches (1)');

    Livewire::withQueryParams(['source' => 'proches'])->test(RecipesIndex::class)
        ->assertSee('Quiche de Pierre')->assertSee('Pierre et Monique')->assertDontSee('Gratin de Léo')
        ->set('householdFilter', $this->third->id)->assertDontSee('Quiche de Pierre');
});

test('une recette d\'un proche se lit en lecture seule ; privée ou modifiée, elle reste introuvable', function () {
    $shared = recipeWithLines('Quiche de Pierre', 'linked');
    $private = recipeWithLines('Tarte privée');
    linkHomes();
    asLeo();

    expect($shared->getRouteKey())->toBe('proche-'.$shared->id);

    $this->get(route('recipes.show', $shared))->assertOk()
        ->assertSee('Quiche de Pierre')->assertSee('Recette de')->assertSee('Copier dans notre carnet')
        ->assertDontSee('Modifier')->assertDontSee('Supprimer');
    $this->get(route('recipes.cook', $shared))->assertOk();
    $this->get(route('recipes.print', $shared))->assertOk();

    $this->get('/recettes/proche-'.$private->id)->assertNotFound();
    $this->get('/recettes/proche-'.$shared->id.'/modifier')->assertNotFound();
    $this->get('/recettes/'.$shared->slug)->assertNotFound();            // le slug est celui de l'autre foyer

    Livewire::test(RecipeShow::class, ['recipe' => $shared])->call('toggleArchive')->call('toggleFavorite');
    expect($shared->fresh()->archived_at)->toBeNull()->and($shared->fresh()->is_favorite)->toBeFalse();
    Livewire::test(RecipeShow::class, ['recipe' => $shared])->call('delete')->assertForbidden();
});

/* ================================================================ 26.2 — planifier ou copier */

test('planifier telle quelle : la recette reste chez eux, ses ingrédients vont dans notre liste', function () {
    $shared = recipeWithLines('Quiche de Pierre', 'linked');
    linkHomes();
    asLeo();

    $slot = MealSlot::query()->active()->ordered()->first();
    Livewire::test(RecipeShow::class, ['recipe' => $shared])
        ->call('openPlan')->set('planDate', '2026-10-15')->set('planSlotId', $slot->id)->set('planServings', 4)
        ->call('plan')->assertHasNoErrors();

    $meal = PlannedMeal::query()->firstOrFail();
    expect($meal->household_id)->toBe($this->other->id)
        ->and($meal->recipe->title)->toBe('Quiche de Pierre')
        ->and($meal->recipe->household_id)->toBe($this->home->id);

    $list = app(ShoppingListManager::class)->create(Carbon::parse('2026-10-15'), Carbon::parse('2026-10-16'));
    expect($list->items()->pluck('ingredient_id')->all())->toContain(Ingredient::firstWhere('name', 'Farine')->id);

    // Pierre referme la recette : le repas prévu reste lisible, depuis le planning.
    asPierre();
    $shared->update(['visibility' => 'private']);
    asLeo();
    $this->get(route('recipes.show', $meal->recipe))->assertOk();

    // Pierre la supprime : le repas de Léo garde le nom.
    asPierre();
    Livewire::test(RecipeShow::class, ['recipe' => $shared->fresh()])->call('delete');
    expect(Recipe::withoutGlobalScope(HouseholdScope::class)->find($shared->id))->toBeNull()
        ->and(PlannedMeal::withoutGlobalScope(HouseholdScope::class)->find($meal->id)->free_text)->toStartWith('Quiche de Pierre');
});

test('R31 : copie complète avec son origine ; l\'original change → différences, reprise choisie, rien d\'écrasé', function () {
    Storage::fake('local');
    Storage::disk('local')->put('recipes/abc.jpg', 'photo');
    Storage::disk('local')->put('recipes/abc-thumb.jpg', 'vignette');
    $pastry = recipeWithLines('Pâte brisée');
    $original = recipeWithLines('Quiche lorraine', 'linked');
    $original->update(['photo_path' => 'recipes/abc']);
    $original->components()->create(['component_recipe_id' => $pastry->id, 'quantity' => 1, 'sort_order' => 1]);
    linkHomes();
    asLeo();
    recipeWithLines('Quiche lorraine');   // même titre déjà dans le carnet de Léo

    Livewire::test(RecipeShow::class, ['recipe' => $original])->call('copyToMine');

    $copier = app(RecipeCopier::class);
    $copy = $copier->existingCopy($original);

    expect($copy->title)->toBe('Quiche lorraine (Pierre et Monique)')
        ->and($copy->household_id)->toBe($this->other->id)
        ->and($copy->visibility)->toBe('private')
        ->and($copy->origin_household_id)->toBe($this->home->id)
        ->and($copy->ingredients()->count())->toBe(2)
        ->and($copy->steps()->count())->toBe(2)
        ->and($copy->photo_path)->not->toBe('recipes/abc')
        ->and(Storage::disk('local')->exists($copy->photo_path.'.jpg'))->toBeTrue()
        ->and($copy->components()->first()->component->title)->toBe('Pâte brisée')
        ->and($copy->components()->first()->component->household_id)->toBe($this->other->id)   // sous-recette copiée aussi
        ->and($copier->hasUpdate($copy))->toBeFalse();

    // Léo change le titre de sa copie ; Pierre change les étapes de l'original.
    $copy->update(['title' => 'Quiche de Mamie']);
    asPierre();
    $original->steps()->where('position', 2)->update(['instruction' => 'Cuire 40 minutes à 180 °C.']);
    asLeo();

    $copy = $copy->fresh();
    expect($copier->hasUpdate($copy))->toBeTrue();

    $diff = $copier->diff($copy);
    expect($diff['steps']['changed'])->toBeTrue()
        ->and($diff['steps']['theirs'])->toContain('Cuire 40 minutes à 180 °C.')
        ->and($diff['ingredients']['changed'])->toBeFalse()
        ->and($diff['general']['changed'])->toBeTrue();                 // le titre diffère (modification locale)

    Livewire::test(RecipeShow::class, ['recipe' => $copy])
        ->assertSee('L\'original a changé', false)
        ->set('showOriginDiff', true)->assertSee('Cuire 40 minutes à 180 °C.')
        ->call('applyOrigin', 'steps');

    $copy = $copy->fresh();
    expect($copy->steps()->pluck('instruction')->all())->toContain('Cuire 40 minutes à 180 °C.')
        ->and($copy->title)->toBe('Quiche de Mamie')                   // pas écrasé
        ->and($copier->hasUpdate($copy))->toBeFalse();

    // Original supprimé : la copie reste.
    asPierre();
    $original->components()->delete();
    $original->delete();
    expect(Recipe::withoutGlobalScope(HouseholdScope::class)->find($copy->id)->origin_recipe_id)->toBeNull();
});

/* ================================================================ 26.3 — avis */

test('avis entre foyers : la note de Léo est visible chez Pierre, avec son foyer, et dans le fil', function () {
    $shared = recipeWithLines('Quiche de Pierre', 'linked');
    linkHomes();
    asLeo();

    Livewire::test(RecipeShow::class, ['recipe' => $shared])
        ->assertSee('visibles de Pierre et Monique')
        ->call('rate', 4)->set('myComment', 'Une pincée de muscade en plus')->call('saveComment');

    expect(RecipeRating::firstOrFail()->household_id)->toBe($this->other->id);

    asPierre();
    Livewire::test(RecipeShow::class, ['recipe' => $shared])
        ->assertSee('Avis des proches')->assertSee('Une pincée de muscade en plus')->assertSee('Léo et Clara');

    expect(app(LinkedFeed::class)->items()->pluck('text')->join('|'))->toContain('Léo a noté « Quiche de Pierre »');
});

/* ================================================================ 26.4 — planning partagé */

test('planning : fermé par défaut, ouvert en lecture, puis en écriture dans leur foyer', function () {
    linkHomes();
    $slot = MealSlot::query()->active()->ordered()->first();
    app(WeekPlanner::class)->addFree('2026-10-18', $slot, 'Pot-au-feu');
    asLeo();

    $this->get(route('linked.planning', $this->home))->assertNotFound();

    asPierre();
    $this->links->updateShare($this->home->id, $this->other->id, false, 'read');
    asLeo();

    $this->get(route('linked.planning', $this->home))->assertOk()->assertSee('Pot-au-feu')->assertSee('Ouvert en lecture');
    Livewire::test(LinkedPlanning::class, ['household' => $this->home])->call('openAdd', '2026-10-16', $slot->id)->assertForbidden();

    asPierre();
    $this->links->updateShare($this->home->id, $this->other->id, false, 'write');
    asLeo();

    Livewire::test(LinkedPlanning::class, ['household' => $this->home])
        ->call('openAdd', '2026-10-16', $slot->id)->set('addText', 'Pâtes au beurre')->call('add')->assertHasNoErrors();

    $added = PlannedMeal::withoutGlobalScope(HouseholdScope::class)->where('free_text', 'Pâtes au beurre')->firstOrFail();
    expect($added->household_id)->toBe($this->home->id)->and($added->comment)->toBe('Ajouté par Léo');

    Livewire::test(LinkedPlanning::class, ['household' => $this->home])->call('remove', $added->id);
    expect(PlannedMeal::withoutGlobalScope(HouseholdScope::class)->find($added->id))->toBeNull();
});

/* ================================================================ 26.5 et 26.6 — repas commun */

test('repas commun : un foyer invité répond, apporte un plat de son carnet, ses contraintes consenties comptent', function () {
    linkHomes();
    $slot = MealSlot::query()->active()->ordered()->get()->last();
    $occasion = MealOccasion::create(['date' => '2026-10-24', 'meal_slot_id' => $slot->id, 'title' => 'Anniversaire de Monique']);
    $roast = recipeWithLines('Rôti');
    app(\App\Services\Receptions\Receptions::class)->addDish($occasion, $roast->id, \App\Enums\Course::Main, 6);

    Livewire::test(ReceptionShow::class, ['occasion' => $occasion])->set('inviteHouseholdId', $this->third->id)->call('inviteHousehold')->assertHasErrors('inviteHouseholdId');
    Livewire::test(ReceptionShow::class, ['occasion' => $occasion])->set('inviteHouseholdId', $this->other->id)->call('inviteHousehold')->assertHasNoErrors();
    $dinersBefore = app(OccasionService::class)->diners($occasion->fresh());

    // Léo : allergie aux fraises, pas encore partagée.
    asLeo();
    $walnut = Ingredient::query()->where('name', 'Fraise')->firstOrFail();
    app(\App\Services\Planning\HouseholdService::class)->addRestriction($this->leo, \App\Enums\RestrictionType::Allergy, $walnut->id);
    $cake = recipeWithLines('Tarte aux fraises');
    $cake->ingredients()->create(['ingredient_id' => $walnut->id, 'quantity' => 50, 'unit_id' => Unit::firstWhere('code', 'g')->id, 'sort_order' => 3]);

    $row = app(SharedMeals::class)->invitationsFor()->firstOrFail();
    $this->get(route('receptions.index'))->assertSee('Invitations de vos proches')->assertSee('Anniversaire de Monique');

    Livewire::test(SharedMeal::class, ['row' => $row->id])
        ->assertSee('Rôti')
        ->set('people', 3)->call('respond', true)
        ->set('recipeId', $cake->id)->set('course', 'dessert')->call('bring')->assertHasNoErrors();

    $dish = PlannedMeal::query()->where('for_occasion_id', $occasion->id)->firstOrFail();
    expect($dish->household_id)->toBe($this->other->id)
        ->and($dish->date->toDateString())->toBe('2026-10-24')
        ->and(MealSlot::find($dish->meal_slot_id)->name)->toBe($slot->name)
        ->and($dish->servings)->toBe($dinersBefore + 3);

    // Leur liste de courses contient les fraises ; celle de Pierre, non.
    $list = app(ShoppingListManager::class)->create(Carbon::parse('2026-10-24'), Carbon::parse('2026-10-24'));
    expect($list->items()->pluck('ingredient_id')->all())->toContain($walnut->id);

    asPierre();
    expect(app(OccasionService::class)->diners($occasion->fresh()))->toBe($dinersBefore + 3)
        ->and(app(SharedMeals::class)->broughtDishes($occasion)->pluck('recipe.title')->all())->toBe(['Tarte aux fraises'])
        ->and(app(\App\Services\Receptions\ReceptionPlanner::class)->courses($occasion)->flatten()->pluck('recipe.title')->filter()->all())->not->toContain('Tarte aux fraises')
        ->and(app(HouseholdService::class)->eaters($occasion->fresh())->pluck('name')->all())->not->toContain('Léo (Léo et Clara)');

    $pierreList = app(ShoppingListManager::class)->create(Carbon::parse('2026-10-24'), Carbon::parse('2026-10-24'));
    expect($pierreList->items()->pluck('ingredient_id')->all())->not->toContain($walnut->id);

    // Léo partage ses contraintes : l'alerte apparaît chez Pierre.
    asLeo();
    Livewire::test(Account::class)->call('toggleShareRestrictions');
    asPierre();

    Livewire::test(ReceptionShow::class, ['occasion' => $occasion])
        ->assertSee('Léo et Clara')->assertSee('Tarte aux fraises')
        ->assertSee('allergie de Léo (Léo et Clara)');
});

/* ================================================================ 26.7 — surplus */

test('surplus : annoncé, réservé par un foyer relié, retiré du stock à la remise seulement', function () {
    linkHomes();
    $item = app(StockManager::class)->add(['ingredient_id' => Ingredient::firstWhere('name', 'Beurre')->id, 'quantity' => 250]);

    Livewire::withQueryParams(['article' => $item->id])->test(SurplusPage::class)
        ->assertSet('label', $item->name())
        ->set('quantity', '250 g')->call('offer')->assertHasNoErrors();

    $offer = SurplusOffer::firstOrFail();
    expect(StockItem::find($item->id)->finished_at)->toBeNull();

    // Les voisins (non reliés) ne voient rien.
    expect(CurrentHousehold::run($this->third, fn () => app(Surplus::class)->fromLinked()))->toHaveCount(0);

    asLeo();
    Livewire::test(SurplusPage::class)->assertSee($item->name())->call('reserve', $offer->id);
    expect($offer->fresh()->reserved_by_household_id)->toBe($this->other->id);

    asPierre();
    Livewire::test(SurplusPage::class)->assertSee('Réservé par Léo et Clara')->call('handOver', $offer->id);

    $item = StockItem::find($item->id);
    expect($item->finished_at)->not->toBeNull()
        ->and($item->movements()->latest('id')->first()->type)->toBe(MovementType::Consume)
        ->and($item->movements()->latest('id')->first()->reason)->toBe('Donné à Léo et Clara')
        ->and($offer->fresh()->status())->toBe('handed');
});

/* ================================================================ 26.8 — liste groupée */

test('liste groupée : Léo ajoute ses articles, Pierre saisit le prix, le montant à rembourser est calculé', function () {
    linkHomes();
    $list = app(ShoppingListManager::class)->create(Carbon::parse('2026-10-15'), Carbon::parse('2026-10-21'));
    app(GroupLists::class)->setShared($list, true);

    asLeo();
    Livewire::test(GroupList::class, ['list' => $list->id])->set('label', 'Lessive')->call('add')->set('label', 'Beurre')->call('add');

    $items = ShoppingListItem::withoutGlobalScope(HouseholdScope::class)->where('for_household_id', $this->other->id)->get();
    expect($items)->toHaveCount(2)->and($items->pluck('household_id')->unique()->all())->toBe([$this->home->id]);

    asPierre();
    $items->each(fn ($i) => $i->update(['is_checked' => true]));
    $items->firstWhere('label', 'Lessive')->update(['paid_price' => 7.49]);

    $balance = app(GroupLists::class)->balances($list)->first();
    expect($balance['household']->id)->toBe($this->other->id)
        ->and($balance['amount'])->toBe(7.49)
        ->and($balance['missing'])->toBe(1)
        ->and(app(PutAwayService::class)->pendingCount($list))->toBe(0)                 // pas dans notre stock
        ->and(app(CostCalculator::class)->shoppingList($list)->paid)->toBe(0.0);        // pas notre dépense

    app(ShoppingListManager::class)->regenerate($list);
    expect(ShoppingListItem::query()->where('for_household_id', $this->other->id)->count())->toBe(2);   // jamais fusionnés

    asLeo();
    Livewire::test(GroupList::class, ['list' => $list->id])->assertSee('7,49 €')->assertSee('1 article(s) acheté(s) sans prix');

    // Liste refermée : plus accessible.
    asPierre();
    app(GroupLists::class)->setShared($list, false);
    asLeo();
    $this->get(route('linked.list', $list->id))->assertNotFound();
});

/* ================================================================ 26.9 — carnet familial */

test('carnet familial : nos recettes et celles des proches visibles, jamais une recette privée d\'un autre foyer', function () {
    $shared = recipeWithLines('Quiche de Pierre', 'linked');
    $private = recipeWithLines('Tarte secrète');
    linkHomes();
    asLeo();
    $mine = recipeWithLines('Gratin de Léo');

    $this->get(route('linked.book'))->assertOk()->assertSee('Quiche de Pierre')->assertSee('Gratin de Léo')->assertDontSee('Tarte secrète');

    $this->get(route('linked.book.print', ['recettes' => implode(',', [$mine->id, $shared->id, $private->id]), 'titre' => 'Les recettes de Mamie']))
        ->assertOk()
        ->assertSeeInOrder(['Les recettes de Mamie', 'Sommaire', 'Gratin de Léo', 'Quiche de Pierre'])
        ->assertSee('Recette de')->assertSee('Pierre et Monique')
        ->assertDontSee('Tarte secrète');

    $this->get(route('linked.book.print', ['recettes' => (string) $private->id]))->assertNotFound();
});

/* ================================================================ C4 — agenda ICS */

test('agenda : adresse personnelle au format iCalendar, planning d\'un foyer relié seulement s\'il est ouvert', function () {
    $slot = MealSlot::query()->active()->ordered()->get()->last();
    app(WeekPlanner::class)->addFree('2026-10-15', $slot, 'Raclette, fromage; cornichons');
    $feed = app(CalendarFeed::class);
    $url = $feed->url($this->pierre);

    $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
    $body = $response->getContent();

    expect($body)->toStartWith("BEGIN:VCALENDAR\r\n")
        ->toContain('SUMMARY:'.$slot->name.' : Raclette\, fromage\; cornichons')
        ->toContain('X-WR-CALNAME:Repas — Pierre et Monique')
        ->toContain('DTSTART:20261015T')
        ->and(DB::table('users')->where('id', $this->pierre->id)->value('calendar_token'))->not->toContain(\Illuminate\Support\Str::between($url, '/agenda/', '.ics'));

    $this->get('/agenda/'.str_repeat('x', 40).'.ics')->assertNotFound();

    // Planning d'un foyer relié : seulement s'il est ouvert.
    linkHomes();
    $leoUrl = $feed->url($this->leo, $this->home->id);
    $this->get($leoUrl)->assertNotFound();
    $this->links->updateShare($this->home->id, $this->other->id, false, 'read');
    $this->get($leoUrl)->assertOk()->assertSee('Raclette', false);

    // Nouvelle adresse : l'ancienne ne marche plus.
    $feed->regenerate($this->pierre);
    $this->get($url)->assertNotFound();

    // Lignes longues pliées à 75 octets, sans couper un caractère.
    $folded = $feed->fold('SUMMARY:'.str_repeat('é', 60));
    foreach (explode("\r\n", $folded) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75)->and(mb_check_encoding($line, 'UTF-8'))->toBeTrue();
    }
});

/* ================================================================ 26.10 — fil des proches */

test('fil des proches sur l\'accueil : recette partagée, surplus, invitation ; masquable', function () {
    linkHomes();
    recipeWithLines('Quiche de Pierre', 'linked');
    app(Surplus::class)->offer(['label' => 'Courgettes', 'quantity' => '1 kg', 'available_until' => '2026-10-16'], $this->pierre);
    $slot = MealSlot::query()->active()->ordered()->first();
    $occasion = MealOccasion::create(['date' => '2026-10-24', 'meal_slot_id' => $slot->id, 'title' => 'Brunch']);
    app(SharedMeals::class)->invite($occasion, $this->other->id, $this->pierre);

    asLeo();
    $this->get(route('dashboard'))->assertSee('Chez les proches')
        ->assertSee('Recette partagée : Quiche de Pierre')->assertSee('À donner : Courgettes (1 kg)')->assertSee('Invitation : Brunch');

    $this->leo->setPreference('home', [['key' => 'linked', 'visible' => false]]);
    $this->get(route('dashboard'))->assertDontSee('Chez les proches');
});

/* ================================================================ Suppression d'un foyer */

test('un foyer supprimé : les repas des autres qui utilisaient ses recettes gardent leur nom, les copies restent', function () {
    $shared = recipeWithLines('Quiche de Pierre', 'linked');
    linkHomes();
    asLeo();
    $slot = MealSlot::query()->active()->ordered()->first();
    $meal = app(WeekPlanner::class)->addRecipe('2026-10-15', $slot, $shared, 2);
    $copy = app(RecipeCopier::class)->copy($shared, $this->leo);

    app(HouseholdData::class)->destroy($this->home);

    expect(PlannedMeal::find($meal->id)->free_text)->toBe('Quiche de Pierre (recette retirée par ses auteurs)')
        ->and(PlannedMeal::find($meal->id)->recipe_id)->toBeNull()
        ->and(Recipe::find($copy->id))->not->toBeNull()
        ->and(Recipe::find($copy->id)->origin_recipe_id)->toBeNull()
        ->and($this->links->linkedIds($this->other->id))->toBe([]);
});
