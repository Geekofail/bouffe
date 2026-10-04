<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 19 — saisons, nutrition et variantes.
 *
 *  - `ingredients.season_months` : les mois où un fruit ou légume est de saison (R18) ;
 *  - `nutrition_foods` : l'extrait de la table Ciqual de l'Anses, importé une fois (R19) ;
 *  - `ingredients.ciqual_code` et `density` : de quoi convertir une quantité en grammes ;
 *  - `recipe_variants` : « version végétarienne », « sans lactose » (13.7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            // [6,7,8,9] = juin à septembre ; null ou [] = toute l'année, ou non concerné.
            $table->json('season_months')->nullable()->after('is_staple');
            // Aliment Ciqual correspondant (R19) et densité pour convertir un volume en grammes.
            $table->string('ciqual_code', 10)->nullable()->after('season_months');
            $table->decimal('density', 6, 3)->nullable()->after('ciqual_code');   // g/ml, 1 par défaut

            $table->index('ciqual_code');
        });

        /*
         * Extrait de la table Ciqual (Anses). Rien n'est livré avec Bouffe : le fichier officiel
         * est téléchargé une fois puis importé (`php artisan bouffe:ciqual`). Les valeurs sont
         * pour 100 g d'aliment.
         */
        Schema::create('nutrition_foods', function (Blueprint $table) {
            $table->id();
            $table->string('ciqual_code', 10)->unique();
            $table->string('name', 250);
            $table->string('search_name', 250)->index();     // nom normalisé, pour la correspondance
            $table->string('food_group', 150)->nullable();
            $table->decimal('energy_kcal', 8, 2)->nullable();
            $table->decimal('proteins', 8, 3)->nullable();
            $table->decimal('carbs', 8, 3)->nullable();
            $table->decimal('sugars', 8, 3)->nullable();
            $table->decimal('fat', 8, 3)->nullable();
            $table->decimal('saturated_fat', 8, 3)->nullable();
            $table->decimal('fibres', 8, 3)->nullable();
            $table->decimal('salt', 8, 3)->nullable();
            $table->string('source', 40)->default('ciqual');
            $table->timestamps();
        });

        // Variantes d'une recette (13.7) : « version végétarienne », « sans lactose »…
        Schema::create('recipe_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('note', 300)->nullable();
            // Contrainte que cette variante lève : proposée d'elle-même quand un convive l'a (13.7).
            $table->string('solves_type', 20)->nullable();        // allergy · dislike · diet
            $table->foreignId('solves_ingredient_id')->nullable()->constrained('ingredients')->nullOnDelete();
            $table->foreignId('solves_tag_id')->nullable()->constrained('tags')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Un remplacement : telle ligne de la recette devient tel ingrédient (ou disparaît).
        Schema::create('recipe_variant_swaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();  // null = ligne retirée
            $table->decimal('quantity', 12, 3)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained();
            $table->string('note', 200)->nullable();
            $table->timestamps();

            // Nom court : MariaDB limite les identifiants à 64 caractères.
            $table->unique(['recipe_variant_id', 'recipe_ingredient_id'], 'variant_swap_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_variant_swaps');
        Schema::dropIfExists('recipe_variants');
        Schema::dropIfExists('nutrition_foods');

        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropIndex(['ciqual_code']);
            $table->dropColumn(['season_months', 'ciqual_code', 'density']);
        });
    }
};
