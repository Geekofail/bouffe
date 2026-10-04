<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 32 — Portions justes (module 32 du document 08, règle R33).
 *
 *  - les portions d'un repas peuvent être des demi-portions (3,5) : planning, sources de la liste,
 *    plats maison du stock, notes de cuisine ;
 *  - l'appétit d'un invité (petit · moyen · normal · grand ; vide = d'après « enfant ») ;
 *  - la gamelle d'une personne (« gamelle de Pierre, mardi »).
 *
 * Les appétits des personnes à table sont des réglages du foyer (Paramètres › Foyer) : une même
 * personne peut appartenir à deux foyers, et les enfants n'ont pas forcément de compte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planned_meals', function (Blueprint $table) {
            $table->decimal('servings', 5, 1)->default(2)->change();
            $table->foreignId('for_user_id')->nullable()->after('servings')->constrained('users')->nullOnDelete();   // gamelle de…
            $table->boolean('is_lunchbox')->default(false)->after('for_user_id');
        });

        Schema::table('shopping_list_item_sources', function (Blueprint $table) {
            $table->decimal('servings', 5, 1)->nullable()->change();
        });

        Schema::table('stock_items', function (Blueprint $table) {
            $table->decimal('servings', 5, 1)->nullable()->change();
        });

        Schema::table('recipe_cook_notes', function (Blueprint $table) {
            $table->decimal('servings', 5, 1)->nullable()->change();
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->string('appetite', 8)->nullable()->after('is_child');
        });
    }

    public function down(): void
    {
        Schema::table('guests', fn (Blueprint $table) => $table->dropColumn('appetite'));

        Schema::table('planned_meals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('for_user_id');
            $table->dropColumn('is_lunchbox');
        });

        foreach (['planned_meals' => 2, 'shopping_list_item_sources' => null, 'recipe_cook_notes' => null] as $name => $default) {
            Schema::table($name, function (Blueprint $table) use ($default) {
                $column = $table->unsignedTinyInteger('servings');
                $default === null ? $column->nullable()->change() : $column->default($default)->change();
            });
        }

        Schema::table('stock_items', fn (Blueprint $table) => $table->unsignedSmallInteger('servings')->nullable()->change());
    }
};
