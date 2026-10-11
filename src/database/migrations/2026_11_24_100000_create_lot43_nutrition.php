<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 43 — nutrition et catalogue Ciqual.
 *
 *  - `nutrition_foods.food_subgroup` : le sous-groupe Ciqual (« légumes », « fruits »…), qui sert
 *    au Nutri-Score estimé (part de fruits, légumes et légumineuses) ;
 *  - `ingredients.ciqual_source` : d'où vient l'aliment associé — `catalogue` (livré avec Bouffe),
 *    `auto` (proposé par Bouffe d'après le nom) ou `manuel` (choisi dans Paramètres → Nutrition,
 *    jamais remplacé ensuite) ;
 *  - `recipes.nutri_score`, `nutrition`, `nutrition_at` : le calcul mis de côté, pour filtrer les
 *    recettes et équilibrer la semaine sans tout recalculer à chaque affichage ;
 *  - `reference_versions` : la version des données de référence déjà appliquée (table Ciqual,
 *    catalogue d'ingrédients), pour ne refaire l'import qu'au changement de fichier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nutrition_foods', function (Blueprint $table) {
            $table->string('food_subgroup', 150)->nullable()->after('food_group');
        });

        Schema::table('ingredients', function (Blueprint $table) {
            $table->string('ciqual_source', 12)->nullable()->after('ciqual_code');
        });

        // Les associations faites avant ce lot venaient de la correspondance par le nom.
        DB::table('ingredients')->whereNotNull('ciqual_code')->update(['ciqual_source' => 'auto']);

        Schema::table('recipes', function (Blueprint $table) {
            $table->char('nutri_score', 1)->nullable();
            $table->json('nutrition')->nullable();
            $table->timestamp('nutrition_at')->nullable();
            $table->index('nutri_score');
        });

        Schema::create('reference_versions', function (Blueprint $table) {
            $table->string('name', 40)->primary();
            $table->string('version', 64);
            $table->json('meta')->nullable();
            $table->timestamp('applied_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_versions');

        Schema::table('recipes', function (Blueprint $table) {
            $table->dropIndex(['nutri_score']);
            $table->dropColumn(['nutri_score', 'nutrition', 'nutrition_at']);
        });

        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn('ciqual_source');
        });

        Schema::table('nutrition_foods', function (Blueprint $table) {
            $table->dropColumn('food_subgroup');
        });
    }
};
