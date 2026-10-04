<?php

use App\Services\QuantityFormatter;

beforeEach(function () {
    require_once __DIR__.'/helpers.php';
    $this->formatter = new QuantityFormatter;
});

test('affichage dans une recette (arrondi au plus proche)', function (?float $qty, ?string $code, string $expected) {
    expect($this->formatter->format($qty, $code ? unit($code) : null))->toBe($expected);
})->with([
    'grammes' => [250, 'g', '250 g'],
    'petits grammes' => [12.4, 'g', '12 g'],
    'arrondi aux 5 g' => [262, 'g', '260 g'],
    'passage en kg' => [1250, 'g', '1,25 kg'],
    'kg rond' => [2, 'kg', '2 kg'],
    'kg décimal' => [0.4, 'kg', '400 g'],
    'cl en ml' => [20, 'cl', '200 ml'],
    'litres' => [1.5, 'l', '1,5 l'],
    'demi-cuillère' => [0.5, 'cs', '½ c. à soupe'],
    'cuillère et quart' => [1.25, 'cc', '1 ¼ c. à café'],
    'pièce au singulier' => [1, 'piece', '1 pièce'],
    'une pièce et demie' => [1.5, 'piece', '1 ½ pièce'],
    'pluriel' => [3, 'piece', '3 pièces'],
    'arrondi à la demi-pièce' => [2.3, 'piece', '2 ½ pièces'],
    'gousses' => [2, 'gousse', '2 gousses'],
    'pincée' => [1, 'pincee', '1 pincée'],
    'demi-boîte' => [0.5, 'boite', '½ boîte'],
    'sans unité' => [4, null, '4'],
    'à convenance' => [null, 'g', ''],
    'jamais zéro' => [0.1, 'piece', '½ pièce'],
]);

test('affichage dans la liste de courses (arrondi au supérieur)', function (float $qty, string $code, string $expected) {
    expect($this->formatter->format($qty, unit($code), QuantityFormatter::SHOPPING))->toBe($expected);
})->with([
    'pièces entières' => [2.3, 'piece', '3 pièces'],
    'demi-oignon' => [0.5, 'piece', '1 pièce'],
    'grammes au 5 supérieur' => [261, 'g', '265 g'],
    'petits grammes au supérieur' => [12.2, 'g', '13 g'],
    'kg au centième supérieur' => [1001, 'g', '1,01 kg'],
    'boîtes entières' => [1.5, 'boite', '2 boîtes'],
    'cuillères au quart' => [1.1, 'cs', '1 ¼ c. à soupe'],
    'pas d\'erreur de virgule flottante' => [0.1 + 0.2, 'kg', '300 g'],
]);

test('les nombres sont au format français', function () {
    expect($this->formatter->number(1.50))->toBe('1,5')
        ->and($this->formatter->number(2.0))->toBe('2')
        ->and($this->formatter->number(0.125, 3))->toBe('0,125');
});
