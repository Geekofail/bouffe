<?php

use App\Models\Ingredient;
use App\Models\IngredientPrice;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Services\Pricing\CostCalculator;
use App\Services\Pricing\PriceBook;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Support\Carbon;

/*
 * Prix et coûts (lot 17 — 15.7, 17.2, règle R17).
 *
 * L'essentiel : un prix relevé dans n'importe quelle unité est ramené à l'unité de base,
 * et ce qu'on ne sait pas chiffrer est compté et annoncé, jamais deviné.
 */

require_once __DIR__.'/../Shopping/helpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-16 10:00'));
    $this->actingAs(User::factory()->create(['name' => 'Pierre']));
    $this->seed([UnitSeeder::class, AisleSeeder::class, IngredientSeeder::class]);

    $this->prices = app(PriceBook::class);
    $this->costs = app(CostCalculator::class);
    $this->units = Unit::pluck('id', 'code');
});

test('un prix relevé au kilo est ramené au gramme', function () {
    $beurre = Ingredient::firstWhere('name', 'Beurre');

    $this->prices->record($beurre, price: 2.49, quantity: 500, unit: Unit::firstWhere('code', 'g'));

    expect((float) $beurre->fresh()->reference_price)->toBe(0.00498)                  // 2,49 € / 500 g
        ->and($beurre->fresh()->referencePriceUnit->code)->toBe('g')
        ->and($this->prices->referenceLabel($beurre->fresh()))->toContain('4,98');    // soit 4,98 €/kg
});

test('un prix relevé en kg et un prix relevé en g donnent le même prix de référence', function () {
    $farine = Ingredient::firstWhere('name', 'Farine');
    $sucre = Ingredient::firstWhere('name', 'Sucre');

    $this->prices->record($farine, price: 1.20, quantity: 1, unit: Unit::firstWhere('code', 'kg'));
    $this->prices->record($sucre, price: 1.20, quantity: 1000, unit: Unit::firstWhere('code', 'g'));

    expect((float) $farine->fresh()->reference_price)->toBe((float) $sucre->fresh()->reference_price);
});

test('un prix à la pièce reste à la pièce', function () {
    $oeuf = Ingredient::firstWhere('name', 'Œuf');

    $this->prices->record($oeuf, price: 3.60, quantity: 12, unit: Unit::firstWhere('code', 'piece'));

    expect((float) $oeuf->fresh()->reference_price)->toBe(0.30)
        ->and($this->prices->costOf($oeuf->fresh(), 4, Unit::firstWhere('code', 'piece')))->toBe(1.2);
});

test('un prix fixé à la main n\'est pas écrasé par un relevé de caisse', function () {
    $beurre = Ingredient::firstWhere('name', 'Beurre');

    $this->prices->setReference($beurre, 10.0, Unit::firstWhere('code', 'kg'));   // 10 €/kg à la main
    $this->prices->record($beurre->fresh(), price: 2.49, quantity: 500, unit: Unit::firstWhere('code', 'g'));

    expect((float) $beurre->fresh()->reference_price)->toBe(0.01)                  // toujours 10 €/kg
        ->and($beurre->fresh()->reference_price_locked)->toBeTrue()
        ->and(IngredientPrice::count())->toBe(1);                                  // le relevé est gardé malgré tout
});

test('le coût d\'une recette additionne ce qui a un prix et compte ce qui manque', function () {
    $recipe = recipeWith('Omelette', 2, [[4, 'piece', 'Œuf', false], [20, 'g', 'Beurre', false], [null, null, 'Sel', false]]);

    $this->prices->record(Ingredient::firstWhere('name', 'Œuf'), 3.60, 12, Unit::firstWhere('code', 'piece'));

    $cost = $this->costs->recipe($recipe);

    expect(round($cost->total, 2))->toBe(1.20)
        ->and($cost->counted)->toBe(1)
        ->and($cost->missing)->toBe(1)                       // le beurre n'a pas de prix
        ->and($cost->isComplete())->toBeFalse()
        ->and($cost->label($this->prices))->toContain('≥')
        ->and($cost->missingLabel())->toBe('1 prix manquant');

    // « Sel » est un produit de base sans quantité : ignoré, pas compté comme manquant.
    expect(Ingredient::firstWhere('name', 'Sel')->is_staple)->toBeTrue();
});

test('le coût suit le nombre de portions', function () {
    $recipe = recipeWith('Omelette', 2, [[4, 'piece', 'Œuf', false]]);
    $this->prices->record(Ingredient::firstWhere('name', 'Œuf'), 3.60, 12, Unit::firstWhere('code', 'piece'));

    expect(round($this->costs->recipe($recipe, 4)->total, 2))->toBe(2.40)
        ->and(round($this->costs->recipe($recipe, 4)->perServing(), 2))->toBe(0.60);
});

test('une quantité dans une autre unité est convertie avant d\'être chiffrée', function () {
    $beurre = Ingredient::firstWhere('name', 'Beurre');
    $this->prices->record($beurre, price: 5.00, quantity: 1, unit: Unit::firstWhere('code', 'kg'));

    // 250 g à 5 €/kg = 1,25 €
    expect(round($this->prices->costOf($beurre->fresh(), 250, Unit::firstWhere('code', 'g')), 2))->toBe(1.25);
});

test('sans prix connu, le coût vaut null plutôt qu\'une estimation', function () {
    $beurre = Ingredient::firstWhere('name', 'Beurre');

    expect($this->prices->costOf($beurre, 250, Unit::firstWhere('code', 'g')))->toBeNull();

    $cost = $this->costs->recipe(recipeWith('Tartine', 2, [[20, 'g', 'Beurre', false]]));

    expect($cost->isKnown())->toBeFalse()
        ->and($cost->label($this->prices))->toBe('prix inconnu');
});

test('un prix est rattaché au magasin où il a été relevé', function () {
    $cactus = Store::create(['name' => 'Cactus']);
    $beurre = Ingredient::firstWhere('name', 'Beurre');

    $this->prices->record($beurre, 2.49, 500, Unit::firstWhere('code', 'g'), $cactus, source: IngredientPrice::SHOPPING);

    $record = IngredientPrice::first();

    expect($record->store_id)->toBe($cactus->id)
        ->and($record->source)->toBe(IngredientPrice::SHOPPING)
        ->and($record->observed_on->toDateString())->toBe('2026-09-16');
});

test('les montants sont écrits à la française', function () {
    expect($this->prices->money(4.5))->toBe("4,50\u{00A0}€")
        ->and($this->prices->money(1234.5))->toBe("1\u{202F}234,50\u{00A0}€");
});
