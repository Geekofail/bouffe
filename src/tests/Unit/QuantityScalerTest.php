<?php

use App\Services\QuantityScaler;

test('mise à l\'échelle selon les portions', function (float|string|null $qty, int $base, int $target, ?float $expected) {
    $result = (new QuantityScaler)->scale($qty, $base, $target);

    $expected === null
        ? expect($result)->toBeNull()
        : expect($result)->toEqualWithDelta($expected, 0.0001);
})->with([
    'doubler' => [200, 2, 4, 400],
    'réduire' => [3, 6, 2, 1],
    'identique' => [1.5, 4, 4, 1.5],
    'décimal en chaîne (base)' => ['250.000', 4, 6, 375],
    'à convenance' => [null, 4, 2, null],
]);

test('un nombre de portions nul est refusé', function () {
    (new QuantityScaler)->scale(100, 0, 2);
})->throws(InvalidArgumentException::class);
