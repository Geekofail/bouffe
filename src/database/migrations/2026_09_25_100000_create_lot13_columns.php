<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 13 — remplir le carnet.
 *
 * · recipes.is_to_test  : recette « à tester », jamais encore cuisinée (13.13)
 * · recipe_steps.group_name : sections d'étapes (« La pâte », « La garniture ») ramenées par l'import
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->boolean('is_to_test')->default(false)->after('is_favorite')->index();
        });

        Schema::table('recipe_steps', function (Blueprint $table) {
            $table->string('group_name', 80)->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropIndex(['is_to_test']);       // SQLite refuse de supprimer une colonne encore indexée
            $table->dropColumn('is_to_test');
        });
        Schema::table('recipe_steps', fn (Blueprint $table) => $table->dropColumn('group_name'));
    }
};
