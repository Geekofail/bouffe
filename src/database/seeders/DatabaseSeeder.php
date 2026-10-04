<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Données de démarrage de Bouffe : `php artisan db:seed`.
     *
     * Ré-exécutable sans risque : les éléments existants (même modifiés) sont conservés,
     * seuls les éléments manquants sont ajoutés.
     *
     * Comptes : Pierre et Monique (voir UserSeeder pour le mot de passe initial).
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            UnitSeeder::class,
            AisleSeeder::class,
            TagSeeder::class,
            MealSlotSeeder::class,
            StorageLocationSeeder::class,
            IngredientSeeder::class,
            SubstitutionSeeder::class,   // remplacements courants (lot 40, Q58)
            StorageLocationSeeder::class,
            SeasonSeeder::class,       // mois de saison des fruits et légumes (lot 19)
            RecipeSeeder::class,
        ]);
    }
}
