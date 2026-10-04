<?php

namespace Database\Seeders;

use App\Support\StockDefaults;
use Illuminate\Database\Seeder;

/**
 * Emplacements du stock (Réfrigérateur, Congélateur, Placard…) et réglages de conservation
 * des ingrédients jamais réglés. Ré-exécutable.
 */
class StorageLocationSeeder extends Seeder
{
    public function run(): void
    {
        StockDefaults::seedLocations();
        StockDefaults::applyToUnconfiguredIngredients();
    }
}
