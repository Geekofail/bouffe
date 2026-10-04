<?php

use App\Enums\ItemOrigin;
use App\Enums\MovementType;
use App\Enums\StockMode;
use App\Livewire\Settings\Ingredients;
use App\Livewire\Settings\StorageLocations;
use App\Livewire\Shopping\Show as ShoppingShow;
use App\Livewire\Stock\Index;
use App\Livewire\Stock\PutAway;
use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\StockItem;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Models\User;
use App\Services\Stock\StockManager;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00'));
    $this->actingAs(User::factory()->create(['name' => 'Pierre']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);
    $this->stock = app(StockManager::class);
    $this->fridge = StorageLocation::firstWhere('name', 'Réfrigérateur');
    $this->pantry = StorageLocation::firstWhere('name', 'Placard');
});

function named(string $name): Ingredient
{
    return Ingredient::firstWhere('name', $name);
}

test('la page Stock regroupe par ingrédient, trie par urgence et filtre par emplacement', function () {
    $this->stock->add(['ingredient_id' => named('Yaourt nature')->id, 'quantity' => 4, 'expires_on' => '2026-10-10', 'expiry_type' => 'dlc']);
    $this->stock->add(['ingredient_id' => named('Yaourt nature')->id, 'quantity' => 2, 'expires_on' => '2026-09-17', 'expiry_type' => 'dlc']);
    $this->stock->add(['ingredient_id' => named('Beurre')->id, 'quantity' => 250]);
    $this->stock->add(['ingredient_id' => named('Pâtes')->id]);

    $this->get(route('stock.index'))->assertOk()
        ->assertSeeInOrder(['Yaourt nature', 'Demain', 'Beurre', 'Pâtes'])
        ->assertSee('6 pièces')
        ->assertSee('En stock');

    Livewire::test(Index::class)
        ->set('location', (string) $this->pantry->id)
        ->assertSee('Pâtes')->assertDontSee('Beurre')
        ->set('location', 'tout')
        ->set('filter', 'bientot')
        ->assertSee('Yaourt nature')->assertDontSee('Beurre')
        ->set('filter', '')
        ->set('search', 'beur')
        ->assertSee('Beurre')->assertDontSee('Yaourt');
});

test('ajout rapide : fenêtre pré-remplie puis ajout, annulable', function () {
    Livewire::test(Index::class)
        ->set('quickText', '2 kg de pommes de terre')
        ->call('prepareQuickAdd')
        ->assertSet('showAdd', true)
        ->assertSet('addMode', 'ingredient')
        ->assertSet('addName', 'Pomme de terre')
        ->assertSet('addQuantity', '2')
        ->assertSet('addUnitId', Unit::firstWhere('code', 'kg')->id)
        ->assertSet('addLocationId', $this->pantry->id)
        ->assertSet('addExpiresOn', '2026-10-16')
        ->call('shiftDate', '1w')
        ->assertSet('addExpiresOn', '2026-09-23')
        ->call('saveAdd')
        ->assertSet('showAdd', false)
        ->assertSee('« Pomme de terre » ajouté (Placard).')
        ->call('undo')
        ->assertSet('undoOffer', null);

    expect(StockItem::active()->count())->toBe(0);
});

test('plat préparé et ingrédient inconnu créé à la volée', function () {
    Livewire::test(Index::class)
        ->call('newPrepared')
        ->assertSet('addMode', 'prepared')
        ->assertSet('addExpiresOn', '2026-09-19')
        ->set('addName', 'Restes de lasagnes')
        ->set('addQuantity', '2')
        ->call('saveAdd')
        ->assertHasNoErrors();

    Livewire::test(Index::class)
        ->set('quickText', '3 kiwis')
        ->call('prepareQuickAdd')
        ->assertSet('addMode', 'prepared')
        ->set('addMode', 'ingredient')
        ->call('saveAdd')
        ->assertSee('Nouvel ingrédient créé');

    expect(StockItem::whereNull('ingredient_id')->value('label'))->toBe('Restes de lasagnes')
        ->and(Ingredient::firstWhere('name', 'Kiwis')?->stockItems()->count())->toBe(1);
});

test('actions sur un article : il en reste ½, ouvert, congeler, jeté avec raison', function () {
    $item = $this->stock->add(['ingredient_id' => named('Bœuf haché')->id, 'quantity' => 500]);

    Livewire::test(Index::class)
        ->call('select', $item->id)
        ->assertSee('Bœuf haché')
        ->call('remaining', $item->id, '1/2')
        ->assertSet('selectedId', $item->id)
        ->call('freeze', $item->id)
        ->assertSet('selectedId', null)
        ->assertSee('congelé');

    expect((float) $item->fresh()->quantity)->toBe(250.0)->and($item->fresh()->isFrozen())->toBeTrue();

    $cream = $this->stock->add(['ingredient_id' => named('Crème liquide')->id, 'quantity' => 20]);
    Livewire::test(Index::class)
        ->call('select', $cream->id)
        ->call('openItem', $cream->id)
        ->call('waste', $cream->id, 'périmé')
        ->assertSee('jeté (périmé)');

    expect($cream->movements()->latest('id')->first()->type)->toBe(MovementType::Waste);
});

test('modifier date, quantité et note ; quantité invalide refusée', function () {
    $item = $this->stock->add(['ingredient_id' => named('Feta')->id, 'quantity' => 200]);

    Livewire::test(Index::class)
        ->call('select', $item->id)
        ->set('editQuantity', 'beaucoup')
        ->call('saveItem')
        ->assertHasErrors('editQuantity')
        ->set('editQuantity', '150')
        ->set('editExpiresOn', '2026-09-25')
        ->set('editNote', 'entamée')
        ->call('saveItem')
        ->assertHasNoErrors();

    $item->refresh();
    expect((float) $item->quantity)->toBe(150.0)->and($item->expires_on->toDateString())->toBe('2026-09-25')->and($item->note)->toBe('entamée');
});

test('présence : « plus rien » propose d\'ajouter à la liste en cours, puis « en stock »', function () {
    $salt = named('Sel');
    $this->stock->setPresence($salt, true);
    $list = ShoppingList::create(['name' => 'Courses', 'period_start' => '2026-09-14', 'period_end' => '2026-09-20']);

    Livewire::test(Index::class)
        ->call('togglePresence', $salt->id, false)
        ->assertSet('restockOffer.name', 'Sel')
        ->call('addToShoppingList')
        ->assertSee('Plus en stock (1)')
        ->call('togglePresence', $salt->id, true)
        ->assertDontSee('Plus en stock');

    expect($list->items()->where('label', 'Sel')->exists())->toBeTrue()
        ->and($salt->stockItems()->active()->count())->toBe(1);
});

test('ranger les courses depuis la liste : bandeau, écran, validation', function () {
    $list = ShoppingList::create(['name' => 'Courses du 14', 'period_start' => '2026-09-14', 'period_end' => '2026-09-20']);
    $list->items()->create(['label' => 'Beurre', 'ingredient_id' => named('Beurre')->id, 'quantity' => 30, 'unit_id' => Unit::firstWhere('code', 'g')->id, 'origin' => ItemOrigin::Generated, 'is_checked' => true]);
    $list->items()->create(['label' => 'Lessive', 'origin' => ItemOrigin::Manual, 'is_checked' => true]);

    Livewire::test(ShoppingShow::class, ['shoppingList' => $list])
        ->assertSee('2 articles')->assertSee('à ranger dans le stock')
        ->assertSee(route('shopping.put-away', $list), false);

    Livewire::test(PutAway::class, ['shoppingList' => $list])
        ->assertSee('Beurre')->assertSee('pas un ingrédient')
        ->assertSet('rows.0.quantity', '30')
        ->set('rows.0.quantity', '250')
        ->call('store')
        ->assertRedirect(route('stock.index'));

    expect((float) StockItem::sole()->quantity)->toBe(250.0);

    Livewire::test(PutAway::class, ['shoppingList' => $list])->assertSee('Rien à ranger');
    Livewire::test(ShoppingShow::class, ['shoppingList' => $list])->assertDontSee('à ranger dans le stock');
});

test('emplacements : ajout, renommage, ordre, suppression protégée', function () {
    $this->stock->add(['ingredient_id' => named('Beurre')->id, 'quantity' => 250]);

    Livewire::test(StorageLocations::class)
        ->set('newName', 'Congélateur du garage')->set('newType', 'freezer')->call('add')
        ->assertSee('Congélateur du garage')
        ->call('delete', $this->fridge->id)
        ->assertDispatched('notify', type: 'warning');

    $garage = StorageLocation::firstWhere('name', 'Congélateur du garage');
    Livewire::test(StorageLocations::class)
        ->call('sort', $garage->id, 0)
        ->call('edit', $garage->id)->set('editName', 'Coffre')->call('update')
        ->call('delete', $garage->id);

    expect(StorageLocation::ordered()->first()->name)->toBe('Réfrigérateur')
        ->and(StorageLocation::where('name', 'Coffre')->exists())->toBeFalse()
        ->and($this->fridge->fresh())->not->toBeNull();
});

test('fiche ingrédient : réglages de stock proposés et enregistrés ; suppression refusée si en stock', function () {
    $dairy = \App\Models\Aisle::firstWhere('name', 'Crèmerie & œufs');

    Livewire::test(Ingredients::class)
        ->call('create')
        ->set('form.name', 'Skyr')
        ->set('form.aisle_id', $dairy->id)
        ->assertSet('form.stock_mode', 'quantity')
        ->assertSet('form.storage_location_id', $this->fridge->id)
        ->assertSet('form.shelf_life_days', '14')
        ->set('form.freezer_months', '2')
        ->set('form.min_stock_quantity', '2')
        ->call('save')
        ->assertHasNoErrors();

    $skyr = Ingredient::firstWhere('name', 'Skyr');
    expect($skyr->shelf_life_type->value)->toBe('dlc')->and($skyr->freezer_months)->toBe(2)->and((float) $skyr->min_stock_quantity)->toBe(2.0);

    Livewire::test(Ingredients::class)->call('edit', $skyr->id)->assertSet('form.freezer_months', '2')->set('form.stock_mode', 'none')->call('save');
    expect($skyr->fresh()->stock_mode)->toBe(StockMode::None);

    $this->stock->add(['ingredient_id' => named('Beurre')->id, 'quantity' => 250]);
    Livewire::test(Ingredients::class)->call('delete', named('Beurre')->id)->assertDispatched('notify', type: 'warning');
    expect(named('Beurre'))->not->toBeNull();
});

test('le menu donne accès au stock ; Paramètres passe dans l\'en-tête sur téléphone', function () {
    $this->get(route('dashboard'))
        ->assertSee(route('stock.index'), false)
        ->assertSee('Stock');
    $this->get(route('settings.index'))->assertSee('Emplacements');
});
