<?php

use App\Models\Unit;
use Database\Seeders\UnitSeeder;

/**
 * Unité en mémoire (sans base) construite à partir de la liste du UnitSeeder.
 */
function unit(string $code): Unit
{
    static $id = 0;

    foreach (UnitSeeder::UNITS as [$c, $label, $plural, $type, $factor, $metric]) {
        if ($c === $code) {
            $unit = new Unit([
                'code' => $c, 'label' => $label, 'label_plural' => $plural,
                'type' => $type, 'factor_to_base' => $factor, 'is_metric' => $metric,
            ]);
            $unit->id = ++$id;

            return $unit;
        }
    }

    throw new InvalidArgumentException("Unité inconnue : {$code}");
}
