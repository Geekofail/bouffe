<?php

use App\Enums\MovementType;
use App\Enums\StockMode;
use App\Livewire\Stock\Index as StockIndex;
use App\Livewire\Stock\Review;
use App\Models\Ingredient;
use App\Models\LearnedSuggestion;
use App\Models\MealSlot;
use App\Models\StockItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\Stock\Learnings;
use App\Services\Stock\StockManager;
use App\Services\Stock\StockReview;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\StorageLocationSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Bouffe apprend (lot 30 — 30.4, 30.5, règle R36) et inventaire par ancienneté (30.8).
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-08-01 10:00'));
    $this->user = User::factory()->create(['name' => 'Pierre']);
    $this->actingAs($this->user);
    $this->seed([UnitSeeder::class, AisleSeeder::class, StorageLocationSeeder::class, IngredientSeeder::class]);
    MealSlot::factory()->create(['name' => 'Dîner']);
    $this->stock = app(StockManager::class);
    $this->learnings = app(Learnings::class);

    // Réglages posés il y a longtemps (R36 : un réglage récent n'est pas remis en question).
    $this->milk = Ingredient::firstWhere('name', 'Lait demi-écrémé');
    $this->milk->forceFill(['stock_mode' => StockMode::Quantity, 'min_stock_quantity' => null])->save();
    $this->cream = Ingredient::firstWhere('name', 'Crème liquide');
    $this->cream->forceFill(['stock_mode' => StockMode::Quantity, 'days_after_opening' => 5, 'shelf_life_days' => 20, 'shelf_life_type' => 'dlc'])->save();
    $this->liter = Unit::firstWhere('code', 'l');
});

/** Un achat rangé dans le stock, tel jour. */
function purchase(Ingredient $ingredient, string $date, float $quantity = 1, ?Unit $unit = null): StockItem
{
    test()->travelTo(Carbon::parse($date.' 18:00'));

    return app(StockManager::class)->add(['ingredient_id' => $ingredient->id, 'quantity' => $quantity, 'unit_id' => $unit?->id ?? $ingredient->default_unit_id]);
}

/** Un produit ouvert tel jour, puis fini ou jeté quelques jours plus tard. */
function openedThen(Ingredient $ingredient, string $opened, int $days, MovementType $end): StockItem
{
    $item = purchase($ingredient, $opened, 20);
    app(StockManager::class)->open($item);
    test()->travelTo(Carbon::parse($opened.' 18:00')->addDays($days));
    app(StockManager::class)->finish($item->fresh(), $end);

    return $item;
}

test('stock minimum : 4 achats en 8 semaines font une proposition, pas 3', function () {
    foreach (['2026-09-03', '2026-09-10', '2026-09-17'] as $date) {
        purchase($this->milk, $date, 1, $this->liter);
    }
    $this->travelTo(Carbon::parse('2026-09-20 10:00'));
    expect($this->learnings->pending())->toBeEmpty();

    purchase($this->milk, '2026-09-24', 1, $this->liter);
    $this->travelTo(Carbon::parse('2026-09-26 10:00'));

    $suggestion = $this->learnings->pending()->sole();
    expect($suggestion['kind'])->toBe('min_stock')
        ->and($suggestion['text'])->toBe('Vous achetez « Lait demi-écrémé » 4 fois en 8 semaines : garder au moins 1 l ?')
        ->and($suggestion['detail'])->toBe('4 achats rangés dans le stock depuis 8 semaines.');
});

test('« Appliquer » règle le minimum ; rien n\'est jamais appliqué seul', function () {
    foreach (['2026-08-06', '2026-08-13', '2026-08-20', '2026-08-27', '2026-09-03', '2026-09-10', '2026-09-17'] as $date) {
        purchase($this->milk, $date, 1, $this->liter);
    }
    $this->travelTo(Carbon::parse('2026-09-20 10:00'));

    expect($this->learnings->pending()->sole()['text'])->toContain('chaque semaine')
        ->and((float) Ingredient::find($this->milk->id)->min_stock_quantity)->toBe(0.0);   // pas appliqué seul

    Livewire::test(StockIndex::class)
        ->assertSee('Bouffe a remarqué')
        ->call('applyLearning', 'min_stock', $this->milk->id)
        ->assertDispatched('notify', message: 'Garder au moins 1 l de « Lait demi-écrémé ».');

    $milk = Ingredient::find($this->milk->id);
    expect((float) $milk->min_stock_quantity)->toBe(1.0)
        ->and($milk->min_stock_unit_id)->toBe($this->liter->id)
        ->and(LearnedSuggestion::sole()->status)->toBe('applied')
        ->and($this->learnings->pending())->toBeEmpty();
});

test('« Non merci » fait taire la proposition 90 jours pour ce produit', function () {
    foreach (['2026-09-03', '2026-09-10', '2026-09-17', '2026-09-24'] as $date) {
        purchase($this->milk, $date, 1, $this->liter);
    }
    $this->travelTo(Carbon::parse('2026-09-26 10:00'));

    Livewire::test(StockIndex::class)->call('dismissLearning', 'min_stock', $this->milk->id);
    expect($this->learnings->pending())->toBeEmpty();

    // Toujours les mêmes achats, 89 jours plus tard : silence. Au 91e jour, la proposition revient.
    foreach (['2026-12-01', '2026-12-08', '2026-12-15', '2026-12-22'] as $date) {
        purchase($this->milk, $date, 1, $this->liter);
    }
    $this->travelTo(Carbon::parse('2026-12-24 09:00'));
    expect($this->learnings->pending())->toBeEmpty();

    $this->travelTo(Carbon::parse('2026-12-26 11:00'));
    expect($this->learnings->pending()->pluck('kind')->all())->toBe(['min_stock']);
});

test('un réglage modifié à la main depuis moins de 30 jours n\'est pas remis en question', function () {
    foreach (['2026-09-03', '2026-09-10', '2026-09-17', '2026-09-24'] as $date) {
        purchase($this->milk, $date, 1, $this->liter);
    }
    $this->travelTo(Carbon::parse('2026-09-26 10:00'));
    Ingredient::find($this->milk->id)->forceFill(['freezer_months' => 3])->save();   // retouché à la main aujourd'hui

    expect($this->learnings->pending())->toBeEmpty();
});

test('durée après ouverture : jetée 3 fois sur 4 avant 5 jours → proposer 3 jours', function () {
    openedThen($this->cream, '2026-09-01', 3, MovementType::Waste);
    openedThen($this->cream, '2026-09-08', 3, MovementType::Waste);
    openedThen($this->cream, '2026-09-15', 4, MovementType::Waste);
    openedThen($this->cream, '2026-09-22', 2, MovementType::Consume);
    $this->travelTo(Carbon::parse('2026-09-28 10:00'));

    $suggestion = $this->learnings->pending()->firstWhere('kind', 'after_opening');
    expect($suggestion['value'])->toBe(3)
        ->and($suggestion['text'])->toBe('« Crème liquide » : 3 fois sur 4, à la poubelle moins de 5 jours après ouverture. Passer la durée après ouverture de 5 à 3 jours ?');

    expect($this->learnings->apply('after_opening', $this->cream->id))->toBe('Durée après ouverture de « Crème liquide » : 3 jours.')
        ->and(Ingredient::find($this->cream->id)->days_after_opening)->toBe(3);
});

test('un produit surtout consommé ne remet pas sa durée en question', function () {
    openedThen($this->cream, '2026-09-01', 3, MovementType::Waste);
    openedThen($this->cream, '2026-09-08', 2, MovementType::Consume);
    openedThen($this->cream, '2026-09-15', 3, MovementType::Consume);
    openedThen($this->cream, '2026-09-22', 4, MovementType::Consume);
    $this->travelTo(Carbon::parse('2026-09-28 10:00'));

    expect($this->learnings->pending()->where('kind', 'after_opening'))->toBeEmpty();
});

test('conservation : jeté avant sa durée, fermé, 3 fois sur 4 → proposition', function () {
    foreach (['2026-09-01', '2026-09-08', '2026-09-15'] as $date) {
        $item = purchase($this->cream, $date, 20);
        $this->travelTo(Carbon::parse($date.' 18:00')->addDays(12));
        $this->stock->finish($item->fresh(), MovementType::Waste);
    }
    $item = purchase($this->cream, '2026-09-20', 20);
    $this->stock->finish($item->fresh(), MovementType::Consume);
    $this->travelTo(Carbon::parse('2026-09-30 10:00'));

    $suggestion = $this->learnings->pending()->firstWhere('kind', 'shelf_life');
    expect($suggestion['value'])->toBe(12)
        ->and($suggestion['text'])->toContain('à la poubelle avant 20 jours. Passer la conservation de 20 à 12 jours ?');
});

test('une proposition qui n\'est plus d\'actualité est refusée proprement', function () {
    Livewire::test(StockIndex::class)->call('applyLearning', 'min_stock', $this->milk->id)
        ->assertDispatched('notify', type: 'warning', message: 'Cette proposition n\'est plus d\'actualité.');
});

/* ================================================================ Inventaire par ancienneté (30.8) */

test('les articles qui n\'ont pas bougé depuis 2 mois sont proposés en revue, du plus ancien au plus récent', function () {
    $flour = purchase(Ingredient::firstWhere('name', 'Farine'), '2026-06-01', 1000);
    $rice = purchase(Ingredient::firstWhere('name', 'Riz basmati'), '2026-06-20', 500);
    $milk = purchase($this->milk, '2026-07-15', 1, $this->liter);
    $moved = purchase(Ingredient::firstWhere('name', 'Beurre'), '2026-05-01', 250);
    $this->travelTo(Carbon::parse('2026-07-20 10:00'));
    $this->stock->open($moved->fresh());                                  // a bougé récemment

    $this->travelTo(Carbon::parse('2026-09-01 10:00'));
    $review = app(StockReview::class);

    expect($review->items()->pluck('id')->all())->toBe([$flour->id, $rice->id])
        ->and($review->countsByLocation()->sum())->toBe(2);
});

test('revue : toujours là, fini, jeté, plus tard — un geste par article', function () {
    $flour = purchase(Ingredient::firstWhere('name', 'Farine'), '2026-05-01', 1000);
    $rice = purchase(Ingredient::firstWhere('name', 'Riz basmati'), '2026-05-02', 500);
    $pasta = purchase(Ingredient::firstWhere('name', 'Pâtes'), '2026-05-03', 500);
    $oil = purchase(Ingredient::firstWhere('name', 'Semoule'), '2026-05-04', 1);
    $this->travelTo(Carbon::parse('2026-09-01 10:00'));

    $page = Livewire::test(Review::class)
        ->assertSee('4 articles n\'ont pas bougé depuis 2 mois.')
        ->assertSee('1 sur 4')
        ->call('keep', $flour->id)
        ->call('finish', $rice->id, 'fini')
        ->call('finish', $pasta->id, 'jete')
        ->call('skip', $oil->id)
        ->assertSee('Les articles restants ont été passés pour plus tard.');

    expect($flour->fresh()->checked_at)->not->toBeNull()
        ->and($rice->fresh()->finished_at)->not->toBeNull()
        ->and($pasta->fresh()->movements()->latest('id')->first()->type)->toBe(MovementType::Waste)
        ->and(app(StockReview::class)->items()->pluck('id')->all())->toBe([$oil->id]);

    // « Fini » a son propre « Annuler » (R32 : le retrait du stock garde le sien).
    $movement = $rice->fresh()->movements()->latest('id')->first();
    $page->call('undo', $movement->id);
    expect($rice->fresh()->finished_at)->toBeNull();
});

test('la page du stock annonce la revue à partir de 3 articles', function () {
    foreach (['Farine', 'Riz basmati', 'Pâtes'] as $i => $name) {
        purchase(Ingredient::firstWhere('name', $name), '2026-05-0'.($i + 1), 100);
    }
    $this->travelTo(Carbon::parse('2026-09-01 10:00'));

    Livewire::test(StockIndex::class)->assertSee('3 articles n\'ont pas bougé depuis 2 mois.', false)->assertSee(route('stock.review'), false);
    $this->get(route('stock.review'))->assertOk()->assertSee('Revue du stock');
});

test('la revue se filtre par emplacement', function () {
    $flour = purchase(Ingredient::firstWhere('name', 'Farine'), '2026-05-01', 1000);
    $milk = purchase($this->milk, '2026-05-02', 1, $this->liter);
    $this->travelTo(Carbon::parse('2026-09-01 10:00'));

    expect(app(StockReview::class)->items($flour->storage_location_id)->pluck('id')->all())->toBe([$flour->id]);

    Livewire::withQueryParams(['emplacement' => $milk->storage_location_id])->test(Review::class)
        ->assertSee('Lait demi-écrémé')->assertDontSee('Farine');
});
