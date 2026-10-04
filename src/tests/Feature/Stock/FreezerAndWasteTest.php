<?php

use App\Enums\LocationType;
use App\Enums\MovementType;
use App\Livewire\Stock\Freezer as FreezerPage;
use App\Livewire\Stock\History as HistoryPage;
use App\Models\Ingredient;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Models\User;
use App\Services\Pricing\PriceBook;
use App\Services\Stock\FreezerBoard;
use App\Services\Stock\ShelfLifeLearner;
use App\Services\Stock\StockManager;
use App\Services\Stock\WasteStats;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Congélateur (16.4), étiquettes (16.5), durées apprises (16.2) et anti-gaspillage (16.3).
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-19 10:00'));
    $this->actingAs(User::factory()->create(['name' => 'Pierre']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);

    $this->freezer = StorageLocation::firstOfType(LocationType::Freezer);
    $this->fridge = StorageLocation::firstOfType(LocationType::Fresh);
    $this->board = app(FreezerBoard::class);
    $this->learner = app(ShelfLifeLearner::class);
});

/** Article entré il y a N jours et terminé il y a `$finishedDaysAgo` jours, avec son mouvement. */
function finishedItem(int $ingredientId, int $locationId, int $lifetimeDays, string $type, int $finishedDaysAgo = 5): StockItem
{
    $item = StockItem::create([
        'ingredient_id' => $ingredientId,
        'storage_location_id' => $locationId,
        'is_present' => true,
        'quantity' => 1,
        'finished_at' => Carbon::today()->subDays($finishedDaysAgo),
    ]);

    // `created_at` n'est pas remplissable : on l'écrit après coup pour simuler un achat passé.
    $item->forceFill(['created_at' => Carbon::today()->subDays($lifetimeDays + $finishedDaysAgo)])->saveQuietly();

    StockMovement::create([
        'stock_item_id' => $item->id,
        'ingredient_id' => $ingredientId,
        'label' => (string) $item->name(),
        'type' => $type,
        'created_at' => Carbon::today()->subDays($finishedDaysAgo),
    ]);

    return $item->fresh();
}

/** Plat maison au congélateur, congelé il y a N jours. */
function frozenDish(string $label, int $daysAgo, ?int $servings = null, ?int $locationId = null): StockItem
{
    return StockItem::create([
        'label' => $label,
        'servings' => $servings,
        'storage_location_id' => $locationId ?? StorageLocation::firstOfType(LocationType::Freezer)->id,
        'is_present' => true,
        'frozen_on' => Carbon::today()->subDays($daysAgo),
        'created_at' => Carbon::today()->subDays($daysAgo),
    ]);
}

/* ================================================================ Congélateur (16.4) */

test('les plats maison sont triés du plus ancien au plus récent', function () {
    frozenDish('Chili', 200, 4);
    frozenDish('Soupe de potiron', 10, 2);
    frozenDish('Bolognaise', 100, 6);

    $rows = $this->board->homemade();

    expect($rows->pluck('item.label')->all())->toBe(['Chili', 'Bolognaise', 'Soupe de potiron'])
        ->and($rows->first()['level'])->toBe('old')          // plus de 6 mois
        ->and($rows->get(1)['level'])->toBe('ageing')        // plus de 3 mois
        ->and($rows->last()['level'])->toBe('fresh')
        ->and($this->board->servingsInStore())->toBe(12.0);
});

test('l\'âge est écrit en français lisible', function () {
    expect($this->board->ageLabel(0))->toBe("aujourd'hui")
        ->and($this->board->ageLabel(1))->toBe('hier')
        ->and($this->board->ageLabel(5))->toBe('il y a 5 jours')
        ->and($this->board->ageLabel(21))->toBe('il y a 3 semaines')
        ->and($this->board->ageLabel(120))->toBe('il y a 4 mois');
});

test('un produit du commerce n\'est pas un plat maison, mais reste dans le congélateur', function () {
    $petitsPois = Ingredient::firstWhere('name', 'Petits pois') ?? Ingredient::first();

    StockItem::create([
        'ingredient_id' => $petitsPois->id,
        'storage_location_id' => $this->freezer->id,
        'is_present' => true,
        'quantity' => 1,
    ]);
    frozenDish('Chili', 30, 4);

    expect($this->board->homemade()->pluck('item.label')->all())->toBe(['Chili'])
        ->and($this->board->all())->toHaveCount(2);
});

test('la page du congélateur affiche les plats et prépare les étiquettes', function () {
    $chili = frozenDish('Chili', 200, 4);

    Livewire::test(FreezerPage::class)
        ->assertSee('Chili')
        ->assertSee('à manger en priorité')
        ->call('toggle', $chili->id)
        ->assertSet('selected', [$chili->id])
        ->call('clearSelection')
        ->assertSet('selected', []);
});

test('les étiquettes s\'impriment avec la date, les portions et un QR code', function () {
    $chili = frozenDish('Chili', 12, 4);

    $this->get(route('stock.labels', ['ids' => $chili->id]))
        ->assertOk()
        ->assertSee('Chili')
        ->assertSee('4 portions')
        ->assertSee(route('stock.index', ['article' => $chili->id]), false);   // cible du QR code
});

test('le QR code d\'une étiquette ouvre l\'article dans le stock', function () {
    $chili = frozenDish('Chili', 12, 4);

    Livewire::withQueryParams(['article' => $chili->id])
        ->test(\App\Livewire\Stock\Index::class)
        ->assertSet('selectedId', $chili->id);
});

test('sortir un plat du congélateur le marque décongelé', function () {
    $chili = frozenDish('Chili', 30, 4);

    Livewire::test(FreezerPage::class)->call('thaw', $chili->id);

    expect($chili->fresh()->thawed_on)->not->toBeNull();
});

/* ================================================ Durées observées (16.2) */

test('la durée de conservation est apprise sur les achats précédents', function () {
    $yaourt = Ingredient::firstWhere('name', 'Yaourt nature');
    $stock = app(StockManager::class);

    // Trois pots achetés, consommés après 8, 10 et 12 jours.
    foreach ([8, 10, 12] as $days) {
        finishedItem($yaourt->id, $this->fridge->id, $days, MovementType::Consume->value);
    }

    expect($this->learner->observations($yaourt))->toHaveCount(3)
        ->and($this->learner->observedDays($yaourt))->toBe(10)        // médiane
        ->and($this->learner->usualLocation($yaourt))->toBe($this->fridge->id);

    $suggestion = $this->learner->suggest($yaourt);

    expect($suggestion['learned'])->toBeTrue()
        ->and($suggestion['expires_on'])->toBe(Carbon::today()->addDays(10)->toDateString())
        ->and($suggestion['note'])->toContain('10 jours');

    unset($stock);
});

test('un article jeté n\'apprend pas une durée de vie', function () {
    $yaourt = Ingredient::firstWhere('name', 'Yaourt nature');

    foreach ([8, 10, 12] as $days) {
        finishedItem($yaourt->id, $this->fridge->id, $days, MovementType::Waste->value);
    }

    expect($this->learner->observedDays($yaourt))->toBeNull();
});

test('deux observations ne suffisent pas à proposer une durée', function () {
    $yaourt = Ingredient::firstWhere('name', 'Yaourt nature');

    foreach ([8, 10] as $days) {
        finishedItem($yaourt->id, $this->fridge->id, $days, MovementType::Consume->value);
    }

    expect($this->learner->observedDays($yaourt))->toBeNull()
        ->and($this->learner->suggest($yaourt)['learned'])->toBeFalse();
});

/* ================================================ Anti-gaspillage (16.3) */

test('les statistiques comptent ce qui est jeté et ce que ça coûte', function () {
    $beurre = Ingredient::firstWhere('name', 'Beurre');
    app(PriceBook::class)->record($beurre, 5.00, 1, Unit::firstWhere('code', 'kg'));

    // Deux paquets jetés (200 g chacun), un consommé.
    foreach ([MovementType::Waste, MovementType::Waste, MovementType::Consume] as $i => $type) {
        StockMovement::create([
            'ingredient_id' => $beurre->id, 'label' => 'Beurre', 'type' => $type->value,
            'quantity' => 200, 'unit_id' => Unit::firstWhere('code', 'g')->id,
            'reason' => $type === MovementType::Waste ? 'périmé' : null,
            'created_at' => Carbon::today()->subDays($i),
        ]);
    }

    $stats = app(WasteStats::class);
    $summary = $stats->summary(6);

    expect($summary['wasted'])->toBe(2)
        ->and($summary['consumed'])->toBe(1)
        ->and($summary['share'])->toBe(33)
        ->and(round($summary['cost'], 2))->toBe(2.00)      // 2 × 200 g à 5 €/kg
        ->and($summary['priced'])->toBe(2);

    $top = $stats->topWasted();

    expect($top->first()['label'])->toBe('Beurre')
        ->and($top->first()['count'])->toBe(2);
});

test('sans prix connu, on compte les articles sans inventer d\'euros', function () {
    $beurre = Ingredient::firstWhere('name', 'Beurre');

    StockMovement::create([
        'ingredient_id' => $beurre->id, 'label' => 'Beurre', 'type' => MovementType::Waste->value,
        'quantity' => 200, 'unit_id' => Unit::firstWhere('code', 'g')->id, 'created_at' => Carbon::today(),
    ]);

    $summary = app(WasteStats::class)->summary(3);

    expect($summary['wasted'])->toBe(1)
        ->and($summary['cost'])->toBe(0.0)
        ->and($summary['priced'])->toBe(0);
});

test('la page Historique filtre les mouvements et propose les durées observées', function () {
    $beurre = Ingredient::firstWhere('name', 'Beurre');

    StockMovement::create(['ingredient_id' => $beurre->id, 'label' => 'Beurre', 'type' => MovementType::Waste->value, 'created_at' => now()]);
    StockMovement::create(['ingredient_id' => $beurre->id, 'label' => 'Lait', 'type' => MovementType::In->value, 'created_at' => now()]);

    Livewire::test(HistoryPage::class)
        ->assertSee('Beurre')
        ->assertSee('Lait')
        ->set('type', MovementType::Waste->value)
        ->assertSee('Beurre')
        ->assertDontSee('Lait');
});

test('une durée observée nettement différente du réglage est proposée en correction', function () {
    $yaourt = Ingredient::firstWhere('name', 'Yaourt nature');
    $yaourt->update(['shelf_life_days' => 30]);

    foreach ([8, 10, 12] as $days) {
        finishedItem($yaourt->id, $this->fridge->id, $days, MovementType::Consume->value);
    }

    Livewire::test(HistoryPage::class)
        ->assertSee('Yaourt nature')
        ->call('applyObserved', $yaourt->id);

    expect($yaourt->fresh()->shelf_life_days)->toBe(10);
});
