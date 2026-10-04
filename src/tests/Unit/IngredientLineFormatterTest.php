<?php

use App\Models\Ingredient;
use App\Services\IngredientLineFormatter;
use App\Services\QuantityFormatter;
use App\Support\Duration;

beforeEach(function () {
    require_once __DIR__.'/helpers.php';
    $this->lines = new IngredientLineFormatter(new QuantityFormatter);
});

function ing(string $name, ?string $plural = null): Ingredient
{
    return new Ingredient(['name' => $name, 'name_plural' => $plural]);
}

test('phrases d\'ingrédients en français', function (?float $qty, ?string $unit, Ingredient $ingredient, string $expected) {
    expect($this->lines->format($qty, $unit ? unit($unit) : null, $ingredient)['text'])->toBe($expected);
})->with([
    'masse' => fn () => [200, 'g', ing('Farine'), '200 g de farine'],
    'élision voyelle' => fn () => [2, 'cs', ing('Huile d\'olive'), "2 c. à soupe d'huile d'olive"],
    'élision accent' => fn () => [1, 'piece', ing('Échalote', 'échalotes'), '1 échalote'],
    'h muet' => fn () => [15, 'ml', ing('Huile de tournesol'), "15 ml d'huile de tournesol"],
    'h aspiré' => fn () => [1, 'boite', ing('Haricots rouges en boîte'), '1 boîte de haricots rouges en boîte'],
    'pièces au pluriel' => fn () => [3, 'piece', ing('Oignon', 'oignons'), '3 oignons'],
    'demi-pièce au singulier' => fn () => [1.5, 'piece', ing('Oignon', 'oignons'), '1 ½ oignon'],
    'sans unité' => fn () => [2, null, ing('Avocat', 'avocats'), '2 avocats'],
    'pluriel après unité' => fn () => [250, 'g', ing('Fraise', 'fraises'), '250 g de fraises'],
    'gousses' => fn () => [2, 'gousse', ing('Ail'), "2 gousses d'ail"],
    'kg' => fn () => [1.2, 'kg', ing('Pomme de terre', 'pommes de terre'), '1,2 kg de pommes de terre'],
    'à convenance' => fn () => [null, null, ing('Sel'), 'sel'],
    'sigle conservé' => fn () => [100, 'g', ing('AOP Comté'), '100 g d\'AOP Comté'],
]);

test('les parties quantité et nom sont séparées', function () {
    expect($this->lines->format(300, unit('g'), ing('Riz basmati')))
        ->toBe(['quantity' => '300 g', 'name' => 'de riz basmati', 'text' => '300 g de riz basmati']);
});

test('durées lisibles', function (?int $minutes, string $expected) {
    expect(str_replace("\u{00A0}", ' ', Duration::format($minutes)))->toBe($expected);
})->with([[5, '5 min'], [45, '45 min'], [60, '1 h'], [75, '1 h 15'], [125, '2 h 05'], [null, '']]);
