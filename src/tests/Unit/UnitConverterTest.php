<?php

use App\Models\Ingredient;
use App\Services\IncompatibleUnitsException;
use App\Services\UnitConverter;

beforeEach(function () {
    require_once __DIR__.'/helpers.php';
    $this->converter = new UnitConverter;
});

function ingredientWithPieceWeight(?float $weight, ?App\Models\Unit $defaultUnit = null): Ingredient
{
    $ingredient = new Ingredient(['name' => 'Test', 'piece_weight_g' => $weight]);
    $ingredient->default_unit_id = $defaultUnit?->id;
    $ingredient->setRelation('defaultUnit', $defaultUnit);

    return $ingredient;
}

test('conversions de masse et de volume', function (float $qty, string $from, string $to, float $expected) {
    expect($this->converter->convert($qty, unit($from), unit($to)))->toEqualWithDelta($expected, 0.0001);
})->with([
    [1.5, 'kg', 'g', 1500],
    [250, 'g', 'kg', 0.25],
    [25, 'cl', 'ml', 250],
    [1, 'l', 'cl', 100],
    [2, 'cs', 'cc', 6],
    [3, 'cs', 'ml', 45],
    [7, 'piece', 'piece', 7],
]);

test('masse et volume ne sont pas convertibles entre eux', function () {
    expect($this->converter->canConvert(unit('g'), unit('ml')))->toBeFalse();

    $this->converter->convert(100, unit('g'), unit('ml'));
})->throws(IncompatibleUnitsException::class);

test('les unités « autre » ne sont pas convertibles', function () {
    expect($this->converter->canConvert(unit('boite'), unit('sachet')))->toBeFalse()
        ->and($this->converter->canConvert(unit('boite'), unit('g')))->toBeFalse();
});

test('pièces ↔ grammes grâce au poids moyen de l\'ingrédient', function () {
    $piece = unit('piece');
    $onion = ingredientWithPieceWeight(150, $piece);

    expect($this->converter->convert(2, $piece, unit('g'), $onion))->toEqualWithDelta(300, 0.001)
        ->and($this->converter->convert(2, $piece, unit('kg'), $onion))->toEqualWithDelta(0.3, 0.001)
        ->and($this->converter->convert(450, unit('g'), $piece, $onion))->toEqualWithDelta(3, 0.001);
});

test('pièces ↔ grammes aussi quand l\'unité par défaut est une masse', function () {
    $potato = ingredientWithPieceWeight(150, unit('g'));

    expect($this->converter->convert(4, unit('piece'), unit('g'), $potato))->toEqualWithDelta(600, 0.001);
});

test('pas de conversion pièces ↔ grammes sans poids moyen', function () {
    expect($this->converter->canConvert(unit('piece'), unit('g'), ingredientWithPieceWeight(null)))->toBeFalse()
        ->and($this->converter->canConvert(unit('piece'), unit('g')))->toBeFalse();
});

test('pas de conversion si l\'unité par défaut est une autre unité de comptage', function () {
    $bread = ingredientWithPieceWeight(25, unit('tranche'));

    expect($this->converter->canConvert(unit('tranche'), unit('g'), $bread))->toBeTrue()
        ->and($this->converter->canConvert(unit('piece'), unit('g'), $bread))->toBeFalse();
});

test('toBase exprime la quantité en g ou ml', function () {
    expect($this->converter->toBase(1.2, unit('kg')))->toEqualWithDelta(1200, 0.001)
        ->and($this->converter->toBase(2, unit('cs')))->toEqualWithDelta(30, 0.001)
        ->and($this->converter->toBase(3, unit('piece')))->toBeNull();
});
