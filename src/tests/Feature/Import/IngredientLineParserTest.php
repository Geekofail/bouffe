<?php

use App\Models\Ingredient;
use App\Services\Recipes\IngredientLineParser;
use Database\Seeders\AisleSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;

beforeEach(function () {
    $this->seed([UnitSeeder::class, AisleSeeder::class, IngredientSeeder::class]);
    $this->parser = app(IngredientLineParser::class);
});

dataset('lignes de recettes', [
    ['200 g de farine', 200.0, 'g', 'Farine', null, false, 'high'],
    ['1,5 kg de pommes de terre', 1.5, 'kg', 'Pomme de terre', null, false, 'high'],
    ['3 œufs', 3.0, null, 'Œuf', null, false, 'high'],
    ['- 50 cl de crème liquide', 50.0, 'cl', 'Crème liquide', null, false, 'high'],
    ['2 gousses d\'ail', 2.0, 'gousse', 'Ail', null, false, 'high'],
    ['1 c. à soupe d\'huile d\'olive', 1.0, 'cs', 'Huile d\'olive', null, false, 'high'],
    ['2 cuillères à café de sucre', 2.0, 'cc', 'Sucre', null, false, 'high'],
    ['½ oignon rouge', 0.5, null, 'Oignon rouge', null, false, 'high'],
    ['1 pincée de sel', 1.0, 'pincee', 'Sel', null, false, 'high'],
    ['4 tomates, coupées en quartiers', 4.0, null, 'Tomate', 'coupées en quartiers', false, 'high'],
    ['100 g de parmesan râpé (facultatif)', 100.0, 'g', 'Parmesan', null, true, 'high'],
    ['2 à 3 carottes', 3.0, null, 'Carotte', null, false, 'high'],
    ['une échalote', 1.0, null, 'Échalote', null, false, 'high'],
    ['Poivre du moulin', null, null, 'Poivre', 'du moulin', false, 'high'],
    ['2 gousses d\'ail rose de Lautrec', 2.0, 'gousse', 'Ail', null, false, 'medium'],
]);

test('une ligne d\'ingrédient est analysée', function (string $line, ?float $quantity, ?string $unit, string $ingredient, ?string $preparation, bool $optional, string $confidence) {
    $result = $this->parser->parse($line);

    expect($result['quantity'])->toBe($quantity)
        ->and($result['unit']?->code)->toBe($unit)
        ->and($result['ingredient']?->name)->toBe($ingredient)
        ->and($result['optional'])->toBe($optional)
        ->and($result['confidence'])->toBe($confidence);

    if ($preparation !== null) {
        expect($result['preparation'])->toBe($preparation);
    }
})->with('lignes de recettes');

test('un ingrédient inconnu est signalé sans être inventé', function () {
    $result = $this->parser->parse('3 feuilles de kaffir');

    expect($result['ingredient'])->toBeNull()
        ->and($result['confidence'])->toBe('none')
        ->and($result['quantity'])->toBe(3.0)
        ->and($result['name'])->toBe('Feuilles de kaffir');
});

test('un alias est reconnu avec une confiance haute', function () {
    Ingredient::firstWhere('name', 'Crème liquide')->aliases()->create(['name' => 'Crème fleurette']);

    expect($this->parser->parse('20 cl de crème fleurette')['ingredient']->name)->toBe('Crème liquide')
        ->and($this->parser->parse('20 cl de crème fleurette')['confidence'])->toBe('high');
});

test('les étapes ne sont pas prises pour des ingrédients', function (string $line, bool $expected) {
    expect($this->parser->looksLikeIngredient($line))->toBe($expected);
})->with([
    ['200 g de farine', true],
    ['3 œufs', true],
    ['Sel', true],
    ['Mélanger la farine et les œufs dans un saladier.', false],
    ['Préchauffer le four à 180 °C.', false],
    ['Laisser reposer la pâte pendant une heure au réfrigérateur avant de l\'étaler.', false],
]);
