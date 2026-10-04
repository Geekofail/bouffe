<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 21 — stock toujours juste (module 22 du document 07).
 *
 *  - `planned_meals.skipped_at` : repas prévu mais pas fait (R23) ;
 *  - `planned_meals.closed_automatically` : marqué mangé par la clôture automatique (R23) ;
 *  - `planned_meals.stock_state` : retrait du stock en attente, fait ou ignoré (22.2) ;
 *  - `stock_items.checked_at` : dernière vérification ciblée (22.5) ;
 *  - `stock_usage_rules` : consommations régulières hors repas (22.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planned_meals', function (Blueprint $table) {
            $table->timestamp('skipped_at')->nullable()->after('prepared_at');
            $table->boolean('closed_automatically')->default(false)->after('skipped_at');
            $table->string('stock_state', 10)->nullable()->after('closed_automatically');   // pending · done · ignored
        });

        Schema::table('stock_items', function (Blueprint $table) {
            $table->timestamp('checked_at')->nullable()->after('finished_at');
        });

        Schema::create('stock_usage_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 10, 3);                          // retiré à chaque fois
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('every_days')->default(1);      // tous les N jours
            $table->date('last_applied_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Q30 : une installation existante passe au retrait automatique (avec « Annuler »),
        // sauf si un choix a déjà été fait. Une installation neuve garde « Demander ».
        if (DB::table('users')->exists() && ! DB::table('settings')->where('key', 'stock.deduction_mode')->exists()) {
            DB::table('settings')->insert(['key' => 'stock.deduction_mode', 'value' => json_encode('auto'), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_usage_rules');

        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropColumn('checked_at');
        });

        Schema::table('planned_meals', function (Blueprint $table) {
            $table->dropColumn(['skipped_at', 'closed_automatically', 'stock_state']);
        });
    }
};
