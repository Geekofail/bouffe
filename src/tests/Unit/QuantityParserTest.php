<?php

use App\Services\InvalidQuantityException;
use App\Services\QuantityParser;

dataset('saisies valides', [
    'entier' => ['2', 2.0],
    'virgule' => ['1,5', 1.5],
    'point' => ['1.5', 1.5],
    'fraction' => ['1/2', 0.5],
    'nombre mixte' => ['1 1/2', 1.5],
    'symbole' => ['½', 0.5],
    'symbole collé' => ['1½', 1.5],
    'symbole espacé' => ['2 ¼', 2.25],
    'espaces autour' => ['  3 ', 3.0],
    'décimal sans zéro' => [',5', 0.5],
    'milliers (espace fine)' => ["1\u{202F}500", 1500.0],
    'milliers et décimales' => ['12 500,5', 12500.5],
    'nombre PHP' => [0.75, 0.75],
]);

test('une saisie valide est convertie en nombre', function (string|float $input, float $expected) {
    expect((new QuantityParser)->parse($input))->toEqualWithDelta($expected, 0.0001);
})->with('saisies valides');

test('une saisie vide signifie « à convenance »', function (?string $input) {
    expect((new QuantityParser)->parse($input))->toBeNull();
})->with([null, '', '   ']);

test('une saisie invalide est refusée', function (string $input) {
    (new QuantityParser)->parse($input);
})->with(['abc', '1/0', '-2', '2 kg', '1,2,3', '1 50'])->throws(InvalidQuantityException::class);

test('tryParse renvoie false au lieu de lever une exception', function () {
    expect((new QuantityParser)->tryParse('n\'importe quoi'))->toBeFalse()
        ->and((new QuantityParser)->tryParse('3'))->toBe(3.0);
});
