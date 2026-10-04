<?php

use App\Enums\ExpiryLevel;
use App\Enums\ExpiryType;
use App\Enums\ItemOrigin;
use App\Enums\MovementType;
use App\Enums\StockMode;
use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Models\User;
use App\Services\Stock\ExpiryCalculator;
use App\Services\Stock\PutAwayService;
use App\Services\Stock\QuickAddParser;
use App\Services\Stock\StockManager;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00'));
    $this->actingAs(User::factory()->create());
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);
    $this->stock = app(StockManager::class);
    $this->expiry = app(ExpiryCalculator::class);
    $this->fridge = StorageLocation::firstWhere('name', 'Réfrigérateur');
    $this->freezer = StorageLocation::firstWhere('name', 'Congélateur');
    $this->pantry = StorageLocation::firstWhere('name', 'Placard');
});

function stockIng(string $name): Ingredient
{
    return Ingredient::firstWhere('name', $name);
}

/* ------------------------------------------------------------------ Valeurs de départ */

test('les ingrédients reçoivent des réglages de conservation d\'après leur rayon et leur nom', function () {
    expect(StorageLocation::count())->toBe(5)
        ->and(stockIng('Sel')->stock_mode)->toBe(StockMode::Presence)
        ->and(stockIng('Farine')->stock_mode)->toBe(StockMode::Presence)           // produit de base
        ->and(stockIng('Blanc de poulet')->stock_mode)->toBe(StockMode::Quantity)
        ->and(stockIng('Blanc de poulet')->storage_location_id)->toBe($this->fridge->id)
        ->and(stockIng('Blanc de poulet')->shelf_life_type)->toBe(ExpiryType::Dlc)
        ->and(stockIng('Blanc de poulet')->freezer_months)->toBe(6)
        ->and(stockIng('Tomate')->storageLocation->name)->toBe('Corbeille à fruits')
        ->and(stockIng('Crème fraîche épaisse')->days_after_opening)->toBe(3);

    $new = Ingredient::create(['name' => 'Kiwi', 'aisle_id' => Aisle::firstWhere('name', 'Fruits & légumes')->id]);
    expect($new->stock_mode)->toBe(StockMode::Quantity)->and($new->shelf_life_days)->toBe(7);

    $custom = Ingredient::create(['name' => 'Lessive', 'aisle_id' => Aisle::firstWhere('name', 'Divers')->id, 'stock_mode' => StockMode::None]);
    expect($custom->fresh()->stock_mode)->toBe(StockMode::None)->and($custom->fresh()->storage_location_id)->toBeNull();
});

/* ------------------------------------------------------------------ Dates */

test('niveaux d\'alerte selon DLC, DDM, ouverture et congélation', function () {
    $milk = $this->stock->add(['ingredient_id' => stockIng('Crème fraîche épaisse')->id, 'quantity' => 20, 'expires_on' => '2026-09-30', 'expiry_type' => 'dlc']);
    expect($this->expiry->level($milk))->toBe(ExpiryLevel::Ok);

    $this->stock->open($milk);   // + 3 jours après ouverture → 19/09
    $milk->refresh();
    expect($this->expiry->effective($milk)['date']->toDateString())->toBe('2026-09-19')
        ->and($this->expiry->level($milk))->toBe(ExpiryLevel::Soon)
        ->and($this->expiry->badge($milk)['text'])->toBe('J-3');

    $chicken = $this->stock->add(['ingredient_id' => stockIng('Blanc de poulet')->id, 'quantity' => 2, 'expires_on' => '2026-09-17', 'expiry_type' => 'dlc']);
    expect($this->expiry->level($chicken))->toBe(ExpiryLevel::Urgent)->and($this->expiry->badge($chicken)['text'])->toBe('Demain');
    expect($this->expiry->level($chicken, Carbon::parse('2026-09-18')))->toBe(ExpiryLevel::Expired);

    $this->stock->freeze($chicken);
    $chicken->refresh();
    expect($chicken->storage_location_id)->toBe($this->freezer->id)
        ->and($this->expiry->effective($chicken)['date']->toDateString())->toBe('2027-03-16')
        ->and($this->expiry->level($chicken, Carbon::parse('2026-10-01')))->toBe(ExpiryLevel::Ok);

    $this->travelTo(Carbon::parse('2026-10-01 09:00'));
    $this->stock->thaw($chicken);
    $chicken->refresh();
    expect($chicken->storage_location_id)->toBe($this->fridge->id)
        ->and($this->expiry->badge($chicken)['text'])->toBe('Demain');

    $pasta = $this->stock->add(['ingredient_id' => stockIng('Pâtes')->id, 'expires_on' => '2026-09-28', 'expiry_type' => 'ddm']);
    expect($this->expiry->level($pasta, Carbon::parse('2026-10-02')))->toBe(ExpiryLevel::DdmPassed)
        ->and($this->expiry->badge($pasta, Carbon::parse('2026-10-02'))['text'])->toBe('À vérifier');

    $leftovers = $this->stock->add(['label' => 'Restes de lasagnes', 'expires_on' => null]);
    expect($this->expiry->effective($leftovers)['date']->toDateString())->toBe('2026-10-04');
});

/* ------------------------------------------------------------------ Ajout rapide */

test('l\'ajout rapide reconnaît quantité, unité et ingrédient', function (string $text, ?float $qty, ?string $unit, ?string $ingredient, string $name) {
    $parsed = app(QuickAddParser::class)->parse($text);

    expect($parsed['quantity'])->toBe($qty)
        ->and($parsed['unit']?->code)->toBe($unit)
        ->and($parsed['ingredient']?->name)->toBe($ingredient)
        ->and($parsed['name'])->toBe($name);
})->with([
    ['2 kg de pommes de terre', 2.0, 'kg', 'Pomme de terre', 'Pomme de terre'],
    ['6 œufs', 6.0, 'piece', 'Œuf', 'Œuf'],
    ['1,5 l lait demi-écrémé', 1.5, 'l', 'Lait demi-écrémé', 'Lait demi-écrémé'],
    ['1/2 botte de persil', 0.5, 'botte', 'Persil', 'Persil'],
    ['2 boites de tomates concassées', 2.0, 'boite', 'Tomates concassées', 'Tomates concassées'],
    ['crème fraîche épaisse', null, null, 'Crème fraîche épaisse', 'Crème fraîche épaisse'],
    ['sauce bolognaise maison', null, null, null, 'Sauce bolognaise maison'],
    ['3 lasagnes maison', 3.0, null, null, 'Lasagnes maison'],
]);

/* ------------------------------------------------------------------ Opérations */

test('ajout avec valeurs proposées, puis « il en reste ½ », quantité exacte et terminé', function () {
    $butter = $this->stock->add(['ingredient_id' => stockIng('Beurre')->id, 'quantity' => 250]);

    expect($butter->storage_location_id)->toBe($this->fridge->id)
        ->and($butter->expires_on->toDateString())->toBe('2026-11-15')        // + 60 jours
        ->and($butter->expiry_type)->toBe(ExpiryType::Dlc)
        ->and($butter->unit->code)->toBe('g');

    $this->stock->setRemaining($butter, 0.5);
    expect((float) $butter->fresh()->quantity)->toBe(125.0);

    $this->stock->setQuantity($butter->fresh(), 40);
    $this->stock->setRemaining($butter->fresh(), 0.25);        // ¼ du paquet de départ
    expect((float) $butter->fresh()->quantity)->toBe(62.5);

    $this->stock->finish($butter->fresh());
    expect($butter->fresh()->finished_at)->not->toBeNull()
        ->and(StockItem::active()->count())->toBe(0)
        ->and(StockMovement::where('stock_item_id', $butter->id)->pluck('type')->map->value->all())
        ->toBe(['in', 'adjust', 'adjust', 'adjust', 'consume']);
});

test('jeter enregistre la raison ; une quantité inconnue « entamée » est notée', function () {
    $salad = $this->stock->add(['ingredient_id' => stockIng('Salade verte')->id]);
    $this->stock->setRemaining($salad, 0.5);
    expect($salad->fresh()->note)->toBe('reste ½')->and($salad->fresh()->opened_on)->not->toBeNull();

    $movement = $this->stock->finish($salad->fresh(), MovementType::Waste, 'abîmé');
    expect($movement->type)->toBe(MovementType::Waste)->and($movement->reason)->toBe('abîmé');
});

test('mode présence : un seul article, « plus rien » puis « en stock »', function () {
    $salt = stockIng('Sel');

    $this->stock->setPresence($salt, true);
    $this->stock->setPresence($salt, true);
    $this->stock->add(['ingredient_id' => $salt->id, 'quantity' => 500]);
    expect($salt->stockItems()->active()->count())->toBe(1)
        ->and($salt->stockItems()->first()->quantity)->toBeNull();

    $this->stock->setPresence($salt, false);
    expect($salt->stockItems()->active()->count())->toBe(0);
});

test('annuler la dernière action restaure l\'article, dans le délai seulement', function () {
    $cream = $this->stock->add(['ingredient_id' => stockIng('Crème liquide')->id, 'quantity' => 20]);
    $this->stock->move($cream, $this->pantry->id);
    $finish = $this->stock->finish($cream->fresh(), MovementType::Waste, 'périmé');

    expect($this->stock->lastUndoable()->id)->toBe($finish->id);

    $restored = $this->stock->undo($finish);
    expect($restored->finished_at)->toBeNull()
        ->and($restored->storage_location_id)->toBe($this->pantry->id)
        ->and((float) $restored->quantity)->toBe(20.0)
        ->and($this->stock->lastUndoable())->toBeNull();   // le déplacement n'est plus la dernière action

    $open = $this->stock->open($restored);
    $this->travel(16)->minutes();
    expect($this->stock->lastUndoable())->toBeNull()
        ->and(fn () => $this->stock->undo($open))->toThrow(InvalidArgumentException::class);
});

test('annuler un ajout retire l\'article', function () {
    $item = $this->stock->add(['label' => 'Soupe maison', 'quantity' => 4, 'unit_id' => Unit::firstWhere('code', 'piece')->id]);
    $this->stock->undo($this->stock->lastUndoable());

    expect($item->fresh()->finished_at)->not->toBeNull();
});

test('règles : ingrédient non suivi, non congelable, article terminé', function () {
    $lessive = Ingredient::create(['name' => 'Lessive', 'aisle_id' => Aisle::firstWhere('name', 'Hygiène & maison')->id]);
    expect(fn () => $this->stock->add(['ingredient_id' => $lessive->id]))->toThrow(InvalidArgumentException::class, 'pas suivi');

    $tomato = $this->stock->add(['ingredient_id' => stockIng('Tomate')->id, 'quantity' => 4]);
    expect(fn () => $this->stock->freeze($tomato))->toThrow(InvalidArgumentException::class, 'congelable');

    $this->stock->finish($tomato);
    expect(fn () => $this->stock->open($tomato->fresh()))->toThrow(InvalidArgumentException::class, 'plus en stock');
});

/* ------------------------------------------------------------------ Ranger les courses */

test('ranger les courses : propositions, quantités achetées, articles ignorés marqués', function () {
    $list = ShoppingList::create(['name' => 'Courses', 'period_start' => '2026-09-14', 'period_end' => '2026-09-20']);
    $g = Unit::firstWhere('code', 'g');
    $chicken = $list->items()->create(['label' => 'Blanc de poulet', 'ingredient_id' => stockIng('Blanc de poulet')->id, 'quantity' => 2.4, 'unit_id' => Unit::firstWhere('code', 'piece')->id, 'origin' => ItemOrigin::Generated, 'is_checked' => true]);
    $butter = $list->items()->create(['label' => 'Beurre', 'ingredient_id' => stockIng('Beurre')->id, 'quantity' => 30, 'unit_id' => $g->id, 'origin' => ItemOrigin::Generated, 'is_checked' => true]);
    $salt = $list->items()->create(['label' => 'Sel', 'ingredient_id' => stockIng('Sel')->id, 'origin' => ItemOrigin::Staple, 'is_checked' => true]);
    $soap = $list->items()->create(['label' => 'Lessive', 'origin' => ItemOrigin::Manual, 'is_checked' => true]);
    $list->items()->create(['label' => 'Tomate', 'ingredient_id' => stockIng('Tomate')->id, 'quantity' => 3, 'origin' => ItemOrigin::Generated]);  // pas coché

    $service = app(PutAwayService::class);
    $rows = collect($service->proposal($list))->keyBy('label');

    expect($rows)->toHaveCount(4)
        ->and($rows['Blanc de poulet']['quantity'])->toBe('3')            // arrondi à l'achat
        ->and($rows['Blanc de poulet']['expires_on'])->toBe('2026-09-19')
        ->and($rows['Beurre']['quantity'])->toBe('30')
        ->and($rows['Sel']['presence'])->toBeTrue()
        ->and($rows['Lessive']['include'])->toBeFalse();

    $data = $rows->values()->all();
    $data[array_search('Beurre', array_column($data, 'label'))]['quantity'] = '250';   // paquet entier

    expect($service->store($list, $data))->toBe(3)
        ->and($service->pendingCount($list))->toBe(0)
        ->and($soap->fresh()->stocked_at)->not->toBeNull()
        ->and((float) StockItem::firstWhere('ingredient_id', stockIng('Beurre')->id)->quantity)->toBe(250.0)
        ->and(StockMovement::where('shopping_list_id', $list->id)->count())->toBe(3);

    expect($service->store($list, $data))->toBe(0);   // pas rangé deux fois
});
