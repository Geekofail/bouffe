<?php

use App\Support\NameNormalizer;

test('le nom est normalisé (casse, accents, pluriel, ligatures)', function (string $input, string $expected) {
    expect(NameNormalizer::normalize($input))->toBe($expected);
})->with([
    ['Tomates cerises', 'tomate cerise'],
    ['Œufs', 'oeuf'],
    ['Poireaux', 'poireau'],
    ['  Crème   fraîche ', 'creme fraiche'],
    ['Huile d\'olive', 'huile d olive'],
    ['Riz', 'riz'],
    ['', ''],
]);

test('le singulier et le pluriel donnent la même clé', function (string $a, string $b) {
    expect(NameNormalizer::normalize($a))->toBe(NameNormalizer::normalize($b));
})->with([
    ['Tomate', 'tomates'],
    ['Noix', 'noix'],
    ['Échalote', 'echalotes'],
    ['Pomme de terre', 'Pommes de terre'],
]);

test('les noms proches sont détectés', function (string $a, string $b, bool $similar) {
    expect(NameNormalizer::isSimilar(NameNormalizer::normalize($a), NameNormalizer::normalize($b)))->toBe($similar);
})->with([
    'identique' => ['Tomate', 'tomates', true],
    'faute de frappe' => ['Courgette', 'Courgete', true],
    'mot en plus' => ['Tomate', 'Tomate cerise', true],
    'différents' => ['Tomate', 'Pomme', false],
    'trop court pour inclure' => ['Ail', 'Ail des ours', false],
    'vide' => ['', 'Tomate', false],
]);
