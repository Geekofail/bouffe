<?php

use App\Enums\StockMode;
use App\Enums\UserRole;
use App\Livewire\Admin\Households as AdminHouseholds;
use App\Livewire\Households\AcceptInvitation;
use App\Livewire\Recipes\Index as RecipesIndex;
use App\Livewire\Settings\Household as HouseholdScreen;
use App\Livewire\Settings\Ingredients as IngredientsScreen;
use App\Models\BudgetCategory;
use App\Models\Expense;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\Invitation;
use App\Models\MealSlot;
use App\Models\Recipe;
use App\Models\RecurringExpense;
use App\Models\Scopes\HouseholdScope;
use App\Models\StockItem;
use App\Models\StorageLocation;
use App\Models\Store;
use App\Models\Tag;
use App\Models\User;
use App\Services\Budget\BudgetTracker;
use App\Services\Budget\RecurringExpenses;
use App\Services\Households\HouseholdManager;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Pricing\PriceBook;
use App\Services\Stock\StockManager;
use App\Services\System\DataExporter;
use App\Support\CurrentHousehold;
use App\Support\Settings;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\MealSlotSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\TagSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * Plusieurs foyers : fondations (lot 24 — module 25 du document 07, règles R29 et R30).
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00'));
    $this->seed([UnitSeeder::class, AisleSeeder::class, TagSeeder::class, MealSlotSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);

    $this->manager = app(HouseholdManager::class);
    $this->home = Household::query()->orderBy('id')->first();                 // foyer n° 1 (créé par la migration)
    $this->home->update(['name' => 'Pierre et Monique']);
    $this->pierre = User::factory()->create(['name' => 'Pierre']);            // premier compte : administrateur
    $this->monique = User::factory()->create(['name' => 'Monique']);

    $this->other = $this->manager->create('Léo et Clara', $this->pierre);
    $this->leo = User::factory()->create(['name' => 'Léo']);
    $this->manager->attach($this->other, $this->leo, UserRole::Owner);
    $this->manager->detach($this->home, $this->leo);                          // la fabrique l'avait mis au foyer n° 1

    $this->actingAs($this->pierre);
});

/** Crée quelques données du foyer n° 1 (Pierre). */
function seedHome(): array
{
    $recipe = Recipe::factory()->create(['title' => 'Gratin de Pierre']);
    $store = Store::create(['name' => 'Cactus']);
    $stock = app(StockManager::class)->add(['ingredient_id' => Ingredient::firstWhere('name', 'Beurre')->id, 'quantity' => 250]);
    $expense = app(BudgetTracker::class)->record(['amount' => 42, 'spent_on' => '2026-10-10', 'budget_category_id' => BudgetCategory::groceries()->id]);
    Settings::set('stock.dlc_soon_days', 5);

    return compact('recipe', 'store', 'stock', 'expense');
}

/* ================================================================ 25.1 — foyers */

test('les données existantes forment le foyer n° 1, le premier compte l\'administre', function () {
    expect($this->home->id)->toBe(1)
        ->and($this->pierre->isAdmin())->toBeTrue()
        ->and($this->monique->isAdmin())->toBeFalse()
        ->and($this->pierre->roleIn($this->home->id))->toBe(UserRole::Owner)
        ->and(CurrentHousehold::id())->toBe($this->home->id);

    $data = seedHome();
    expect($data['recipe']->household_id)->toBe($this->home->id)
        ->and($data['stock']->household_id)->toBe($this->home->id);
});

test('un nouveau foyer reçoit créneaux, emplacements, catégories et postes de départ', function () {
    CurrentHousehold::run($this->other, function () {
        expect(MealSlot::count())->toBe(count(MealSlotSeeder::SLOTS))
            ->and(StorageLocation::count())->toBeGreaterThanOrEqual(3)
            ->and(Tag::count())->toBe(count(TagSeeder::TAGS))
            ->and(BudgetCategory::query()->pluck('kind')->all())->toContain('groceries', 'restaurant');
    });

    // Mêmes noms dans les deux foyers : l'unicité est par foyer.
    expect(Tag::withoutGlobalScope(HouseholdScope::class)->where('name', 'Hiver')->count())->toBe(2);
});

/* ================================================================ R29 — isolation */

test('chaque modèle est classé : propre à un foyer, enfant d\'un autre, ou commun', function () {
    $household = [];
    $children = ['RecipeIngredient', 'RecipeStep', 'RecipeComponent', 'RecipeCookNote', 'RecipeRating', 'RecipeVariant', 'RecipeVariantSwap',
        'ShoppingListItemSource', 'StoreAisle', 'WeekTemplateMeal', 'GuestRestriction', 'BudgetAmount', 'ExpenseSplit', 'ReceiptLine',
        'MealReaction', 'HouseholdMember', 'PushSubscription', 'Invitation', 'LoginEvent',
        'HouseholdLink', 'HouseholdShare', 'MealOccasionHousehold', 'RecipePhoto', 'RecipeShareLink', 'RecipeRevision',
        'StayParticipant', 'StayMeal', 'StayPayment', 'StayPackedItem',
        'StayHousehold', 'ShoppingListAisleOwner'];   // lot 42 : enfants d'un séjour, d'une liste
    $common = ['Unit', 'Ingredient', 'IngredientAlias', 'IngredientMerge', 'NutritionFood', 'Aisle', 'Product', 'User', 'Household',
        'IngredientSubstitution'];   // lot 40 : liste commune (household_id vide) et remplacements du foyer, filtrés à part

    foreach (glob(app_path('Models/*.php')) as $file) {
        $name = basename($file, '.php');
        $class = 'App\\Models\\'.$name;

        if (in_array(\App\Models\Concerns\BelongsToHousehold::class, class_uses_recursive($class), true)) {
            $household[] = $name;
            expect(Schema::hasColumn((new $class)->getTable(), 'household_id'))->toBeTrue("{$name} : colonne household_id manquante");

            continue;
        }

        expect(in_array($name, [...$children, ...$common], true))->toBeTrue("{$name} n'est pas classé (R29)");
    }

    expect($household)->toHaveCount(45);   // lot 42 : + qui apporte quoi et son lien ; lot 40 : + notes d'étape ; lot 39 : + personnes, leurs goûts (au lieu des contraintes des comptes), cantine, choix des enfants
});

test('un foyer ne voit rien des autres : recettes, stock, magasins, dépenses, réglages', function () {
    $home = seedHome();

    $this->actingAs($this->leo);

    expect(CurrentHousehold::id())->toBe($this->other->id)
        ->and(Recipe::count())->toBe(0)
        ->and(StockItem::count())->toBe(0)
        ->and(Store::count())->toBe(0)
        ->and(Expense::count())->toBe(0)
        ->and(Recipe::find($home['recipe']->id))->toBeNull()
        ->and(Settings::int('stock.dlc_soon_days', 3))->toBe(3)
        ->and(app(BudgetTracker::class)->spentByCategory(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')))->not->toContain(42.0)
        ->and(User::query()->inHousehold()->pluck('name')->all())->toBe(['Léo']);

    // Par l'adresse non plus.
    $this->get(route('recipes.show', $home['recipe']))->assertNotFound();
    $this->get(route('recipes.index'))->assertOk()->assertDontSee('Gratin de Pierre');

    // Ce qu'il crée reste chez lui.
    $mine = Store::create(['name' => 'Cactus']);
    expect($mine->household_id)->toBe($this->other->id)
        ->and(Store::withoutGlobalScope(HouseholdScope::class)->where('name', 'Cactus')->count())->toBe(2);

    $this->actingAs($this->pierre);
    expect(Store::pluck('id')->all())->toBe([$home['store']->id]);
});

test('les contraintes alimentaires ne sortent pas du foyer', function () {
    app(\App\Services\Planning\HouseholdService::class)->addRestriction($this->pierre, \App\Enums\RestrictionType::Allergy, Ingredient::firstWhere('name', 'Beurre')->id);

    $this->actingAs($this->leo);

    expect(\App\Models\PersonRestriction::count())->toBe(0)
        ->and(app(\App\Services\Planning\HouseholdService::class)->hasRestrictions())->toBeFalse();

    // Même membre des deux foyers, Pierre n'emporte pas ses contraintes (leur partage viendra au lot 26).
    $this->manager->attach($this->other, $this->pierre, UserRole::Full);
    $this->pierre->forceFill(['current_household_id' => $this->other->id])->save();
    $this->actingAs($this->pierre->fresh());
    \App\Support\CurrentHousehold::forget();

    expect(CurrentHousehold::id())->toBe($this->other->id)
        ->and(\App\Models\PersonRestriction::count())->toBe(0);
});

/* ================================================================ R30 — catalogue commun, réglages par foyer */

test('les réglages d\'un ingrédient sont propres à chaque foyer', function () {
    $beurre = Ingredient::firstWhere('name', 'Beurre');
    $defaultMode = $beurre->stock_mode;

    $this->actingAs($this->leo);
    Ingredient::firstWhere('name', 'Beurre')->update(['stock_mode' => StockMode::Presence, 'is_staple' => true, 'shelf_life_days' => 99]);

    $mine = Ingredient::firstWhere('name', 'Beurre');
    expect($mine->stock_mode)->toBe(StockMode::Presence)
        ->and($mine->shelf_life_days)->toBe(99)
        ->and(Ingredient::whereSetting('stock_mode', '=', 'presence')->pluck('name')->all())->toContain('Beurre');

    // Le catalogue et le foyer de Pierre gardent leurs valeurs.
    expect(DB::table('ingredients')->where('id', $beurre->id)->value('shelf_life_days'))->not->toBe(99);

    $this->actingAs($this->pierre);
    $his = Ingredient::firstWhere('name', 'Beurre');
    expect($his->stock_mode)->toBe($defaultMode)
        ->and($his->shelf_life_days)->not->toBe(99)
        ->and(Ingredient::whereSetting('stock_mode', '=', 'presence')->pluck('name')->all())->not->toContain('Beurre');
});

test('le prix de référence et l\'emplacement sont ceux du foyer', function () {
    $beurre = Ingredient::firstWhere('name', 'Beurre');
    app(PriceBook::class)->record($beurre, 2.50, 250, \App\Models\Unit::firstWhere('code', 'g'));
    $homePrice = (float) Ingredient::find($beurre->id)->reference_price;

    $this->actingAs($this->leo);

    // Emplacement par défaut du catalogue → l'emplacement du même nom chez Léo.
    $location = Ingredient::find($beurre->id)->storage_location_id;
    expect(StorageLocation::whereKey($location)->exists())->toBeTrue();

    app(PriceBook::class)->record(Ingredient::find($beurre->id), 5.00, 250, \App\Models\Unit::firstWhere('code', 'g'));
    expect((float) Ingredient::find($beurre->id)->reference_price)->toBe(0.02)
        ->and(\App\Models\IngredientPrice::count())->toBe(1);

    $this->actingAs($this->pierre);
    expect((float) Ingredient::find($beurre->id)->reference_price)->toBe($homePrice)->toBe(0.01);
});

test('avec plusieurs foyers, seul l\'administrateur change le catalogue commun', function () {
    $this->actingAs($this->monique);   // responsable du foyer n° 1, pas administratrice
    $beurre = Ingredient::firstWhere('name', 'Beurre');

    expect(Ingredient::catalogLocked())->toBeTrue();

    Livewire::test(IngredientsScreen::class)
        ->call('edit', $beurre->id)
        ->set('form.name', 'Beurre doux')
        ->call('save')
        ->assertHasErrors('form.name');

    Livewire::test(IngredientsScreen::class)->call('delete', $beurre->id)->assertDispatched('notify');
    expect($beurre->fresh()->name)->toBe('Beurre')->and(Ingredient::find($beurre->id))->not->toBeNull();

    // Ses réglages de stock, eux, restent modifiables.
    Livewire::test(IngredientsScreen::class)->call('toggleStaple', $beurre->id);
    expect(Ingredient::find($beurre->id)->is_staple)->toBe(! $beurre->is_staple);

    $this->actingAs($this->pierre);
    expect(Ingredient::catalogLocked())->toBeFalse();
});

/* ================================================================ 25.2 — invitations */

test('une invitation crée le compte et rejoint le foyer, une seule fois', function () {
    $result = $this->manager->invite($this->home, UserRole::Viewer, $this->pierre, 'Julie@Exemple.lu');
    $token = basename($result['url']);

    expect(Invitation::sole()->token_hash)->not->toBe($token)->toBe(Invitation::hash($token));

    auth()->logout();

    Livewire::test(AcceptInvitation::class, ['token' => $token])
        ->assertSee('Pierre et Monique')
        ->assertSet('email', 'julie@exemple.lu')
        ->set('name', 'Julie')
        ->set('password', 'motdepasse')
        ->set('password_confirmation', 'motdepasse')
        ->call('register')
        ->assertRedirect(route('dashboard'));

    $julie = User::firstWhere('email', 'julie@exemple.lu');
    expect(auth()->id())->toBe($julie->id)
        ->and($julie->roleIn($this->home->id))->toBe(UserRole::Viewer)
        ->and($julie->current_household_id)->toBe($this->home->id)
        ->and(Invitation::sole()->accepted_by)->toBe($julie->id);

    // Déjà utilisée.
    auth()->logout();
    Livewire::test(AcceptInvitation::class, ['token' => $token])->assertSee('Invitation expirée');
});

test('un compte existant rejoint un second foyer et passe de l\'un à l\'autre (25.4)', function () {
    $token = basename($this->manager->invite($this->other, UserRole::Full, $this->leo)['url']);

    Livewire::test(AcceptInvitation::class, ['token' => $token])
        ->assertSee('Rejoindre le foyer')
        ->call('join')
        ->assertRedirect(route('dashboard'));

    expect($this->pierre->fresh()->households()->count())->toBe(2)
        ->and($this->pierre->fresh()->current_household_id)->toBe($this->other->id);

    $this->post(route('households.switch', $this->home->id))->assertRedirect(route('dashboard'));
    expect($this->pierre->fresh()->current_household_id)->toBe($this->home->id);

    // Un foyer dont on n'est pas membre : refusé.
    $third = $this->manager->create('Voisins');
    $this->post(route('households.switch', $third->id));
    expect($this->pierre->fresh()->current_household_id)->toBe($this->home->id);
});

test('une invitation expirée, ou pour une autre adresse, est refusée', function () {
    $expired = basename($this->manager->invite($this->home, UserRole::Full, $this->pierre)['url']);
    $this->travel(8)->days();

    auth()->logout();
    Livewire::test(AcceptInvitation::class, ['token' => $expired])->assertSee('Invitation expirée');

    $forJulie = basename($this->manager->invite($this->home, UserRole::Full, $this->pierre, 'julie@exemple.lu')['url']);
    $this->actingAs($this->leo);
    Livewire::test(AcceptInvitation::class, ['token' => $forJulie])->call('join')->assertSee('une autre adresse e-mail');

    expect($this->leo->fresh()->roleIn($this->home->id))->toBeNull();
});

test('l\'écran du foyer invite, retire un membre et garde un responsable (25.3)', function () {
    $screen = Livewire::test(HouseholdScreen::class)
        ->set('inviteRole', UserRole::Full->value)
        ->call('invite')
        ->assertSet('inviteUrl', fn ($url) => str_contains((string) $url, '/invitation/'));

    expect(Invitation::count())->toBe(1);
    $screen->call('revokeInvitation', Invitation::sole()->id);
    expect(Invitation::count())->toBe(0);

    Livewire::test(HouseholdScreen::class)->call('removeMember', $this->monique->id);
    expect($this->monique->fresh()->roleIn($this->home->id))->toBeNull();

    // Seul responsable : il ne peut pas partir.
    Livewire::test(HouseholdScreen::class)->call('leave')->assertDispatched('notify');
    expect($this->pierre->fresh()->roleIn($this->home->id))->toBe(UserRole::Owner);
});

test('un membre « complet » modifie tout sauf les membres du foyer', function () {
    $this->monique->setRoleIn(UserRole::Full);
    $this->actingAs($this->monique);

    expect($this->monique->canEdit())->toBeTrue()->and($this->monique->managesHousehold())->toBeFalse();

    Livewire::test(HouseholdScreen::class)->call('invite')->assertDispatched('notify');
    expect(Invitation::count())->toBe(0);

    $this->get(route('settings.export'))->assertRedirect(route('dashboard'));
});

/* ================================================================ 25.7 — administration */

test('l\'administration liste les foyers sans en montrer le contenu', function () {
    seedHome();

    $this->get(route('admin.households'))->assertOk()->assertSee('Léo et Clara')->assertSee('Pierre et Monique')->assertDontSee('Gratin de Pierre');

    Livewire::test(AdminHouseholds::class)
        ->set('newName', 'Grands-parents')
        ->set('ownerEmail', 'mamie@exemple.lu')
        ->call('create')
        ->assertSet('inviteFor', 'Grands-parents');

    $grands = Household::firstWhere('name', 'Grands-parents');
    expect(Invitation::withoutGlobalScopes()->where('household_id', $grands->id)->sole()->role)->toBe(UserRole::Owner);

    $this->actingAs($this->monique);
    $this->get(route('admin.households'))->assertForbidden();
});

test('un foyer désactivé n\'est plus accessible à ses membres', function () {
    $this->manager->setDisabled($this->other, true);

    $this->actingAs($this->leo);
    $this->get(route('dashboard'))->assertRedirect(route('no-household'));
    $this->get(route('no-household'))->assertOk()->assertSee('Aucun foyer');

    $this->manager->setDisabled($this->other, false);
    $this->get(route('dashboard'))->assertOk();
});

/* ================================================================ 25.8 — données du foyer */

test('l\'export d\'un foyer ne contient que ses données et ses photos', function () {
    Storage::fake('local');
    Storage::disk('local')->put('recipes/maison.jpg', 'x');
    Storage::disk('local')->put('recipes/leo.jpg', 'y');
    seedHome()['recipe']->update(['photo_path' => 'recipes/maison']);

    CurrentHousehold::run($this->other, fn () => Recipe::factory()->create(['title' => 'Pâtes de Léo', 'photo_path' => 'recipes/leo']));

    $exporter = app(DataExporter::class);
    $data = $exporter->data();
    $zip = new ZipArchive;
    $zip->open($path = $exporter->toZip());

    expect(collect($data['recettes'])->pluck('titre')->all())->toBe(['Gratin de Pierre'])
        ->and($data['foyer'])->toBe('Pierre et Monique')
        ->and(collect($data['comptes'])->pluck('nom')->sort()->values()->all())->toBe(['Monique', 'Pierre'])
        ->and($data['depenses'])->toHaveCount(1)
        ->and($zip->locateName('photos/maison.jpg'))->not->toBeFalse()
        ->and($zip->locateName('photos/leo.jpg'))->toBeFalse()
        ->and($exporter->filename())->toContain('pierre-et-monique');

    $zip->close();
    @unlink($path);
});

test('la suppression d\'un foyer attend 30 jours, puis efface toutes ses données', function () {
    $home = seedHome();
    CurrentHousehold::run($this->other, function () {
        Recipe::factory()->create(['title' => 'Pâtes de Léo']);
        app(StockManager::class)->add(['ingredient_id' => Ingredient::firstWhere('name', 'Beurre')->id, 'quantity' => 100]);
        app(BudgetTracker::class)->record(['amount' => 12, 'spent_on' => '2026-10-10', 'budget_category_id' => BudgetCategory::groceries()->id]);
    });

    $this->actingAs($this->leo);
    Livewire::test(HouseholdScreen::class)
        ->set('showDelete', true)
        ->set('deleteConfirmation', 'mauvais nom')
        ->call('requestDeletion')->assertHasErrors('deleteConfirmation')
        ->set('deleteConfirmation', 'léo et clara')
        ->call('requestDeletion')->assertHasNoErrors()
        ->assertSee('seront supprimés le');

    expect($this->manager->purgeDue())->toBe(0);

    $this->travel(31)->days();
    expect($this->manager->purgeDue())->toBe(1)
        ->and(Household::find($this->other->id))->toBeNull()
        ->and(User::find($this->leo->id))->not->toBeNull()
        ->and(DB::table('recipes')->where('household_id', $this->other->id)->count())->toBe(0)
        ->and(DB::table('stock_items')->where('household_id', $this->other->id)->count())->toBe(0)
        ->and(DB::table('expenses')->where('household_id', $this->other->id)->count())->toBe(0);

    // Le foyer n° 1 n'a rien perdu.
    $this->actingAs($this->pierre);
    expect(Recipe::find($home['recipe']->id))->not->toBeNull()->and(StockItem::count())->toBe(1)->and(Expense::count())->toBe(1);
});

/* ================================================================ Tâche planifiée et réglages */

test('la tâche planifiée travaille foyer par foyer', function () {
    CurrentHousehold::run($this->other, fn () => app(RecurringExpenses::class)->create('Cantine', 45, BudgetCategory::query()->where('kind', 'work')->value('id'), 'monthly', 5, Carbon::parse('2026-10-01')));
    app(RecurringExpenses::class)->create('Panier bio', 20, BudgetCategory::groceries()->id, 'monthly', 3, Carbon::parse('2026-10-01'));

    $result = app(NotificationDispatcher::class)->run(Carbon::parse('2026-10-14 10:00'));

    expect($result['households'])->toBe(2)
        ->and(Expense::pluck('place')->all())->toBe(['Panier bio']);

    $this->actingAs($this->leo);
    expect(Expense::pluck('place')->all())->toBe(['Cantine'])
        ->and(RecurringExpense::count())->toBe(1);
});

test('le nombre de personnes à table se règle par foyer', function () {
    Livewire::test(HouseholdScreen::class)->set('householdName', 'Tribu Tripodi')->call('saveHousehold')->assertHasNoErrors()
        ->call('openPerson')->set('person.name', 'Lina')->set('person.appetite', 'petit')->call('savePerson')->assertHasNoErrors()
        ->call('openPerson')->set('person.name', 'Hugo')->set('person.appetite', 'moyen')->call('savePerson')->assertHasNoErrors()
        ->assertSee('4 personnes · 3,5 portions');   // 1 + 1 + 0,5 + 0,75 = 3,25 → 3,5 (R33)

    expect(app(\App\Services\Planning\OccasionService::class)->householdSize())->toBe(4)
        ->and($this->home->fresh()->name)->toBe('Tribu Tripodi');

    $this->actingAs($this->leo);
    expect(app(\App\Services\Planning\OccasionService::class)->householdSize())->toBe((int) config('bouffe.household_size'));
});

test('un compte sans foyer est prévenu', function () {
    $lone = User::factory()->create(['name' => 'Seul']);
    $this->manager->detach($this->home, $lone);

    $this->actingAs($lone);
    $this->get(route('dashboard'))->assertRedirect(route('no-household'));
    Livewire::test(RecipesIndex::class)->assertDontSee('Gratin');
});
