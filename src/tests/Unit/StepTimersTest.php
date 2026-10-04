<?php

use App\Services\Recipes\StepTimers;

dataset('étapes minutées', [
    'minutes' => ['Cuire 25 min à feu doux.', [25]],
    'minutes en toutes lettres' => ['Laisser mijoter 45 minutes.', [45]],
    'heures et minutes' => ['Enfourner 1 h 15.', [75]],
    'heures collées' => ['Cuisson : 1h30 au four.', [90]],
    'heures seules' => ['Laisser lever 2 heures.', [120]],
    'demi-heure' => ['Laisser reposer une demi-heure.', [30]],
    'une nuit' => ['Faire tremper les pois chiches une nuit.', [720]],
    'deux durées' => ['Cuire 10 min, puis laisser reposer 5 min.', [10, 5]],
    'secondes' => ['Blanchir 30 s dans l\'eau bouillante.', [1]],
]);

test('les durées à minuter sont détectées', function (string $text, array $expected) {
    expect(collect((new StepTimers)->extract($text))->pluck('minutes')->all())->toBe($expected);
})->with('étapes minutées');

test('un texte sans cuisson ni durée ne donne pas de minuteur', function (?string $text) {
    expect((new StepTimers)->extract($text))->toBe([]);
})->with([
    'Servir avec 20 g de parmesan râpé.',      // quantité, pas une durée
    'Mélanger la farine et les œufs.',
    'Couper 3 tomates en quartiers.',
    '',
    null,
]);

test('le libellé du minuteur est lisible', function () {
    expect((new StepTimers)->extract('Cuire 1 h 05.')[0]['label'])->toBe("1\u{00A0}h\u{00A0}05");
});
