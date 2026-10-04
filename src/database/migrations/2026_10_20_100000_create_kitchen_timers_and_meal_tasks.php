<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 41 — Cuisiner à plusieurs, même sans réseau (module 41).
 *
 * · kitchen_timers : minuteurs partagés entre les appareils du foyer (41.1) ; une notification part à
 *                    la fin si aucune page ouverte ne l'a fait sonner
 * · meal_tasks     : qui fait quelle étape d'un repas complet, et ce qui est fait (41.3)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_timers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();     // qui l'a lancé
            $table->string('label', 120);
            $table->unsignedInteger('duration');                                         // secondes
            $table->timestamp('ends_at');
            $table->string('source', 40)->nullable();                                    // recette:12 · repas:2026-10-20-2 · cuisine
            $table->timestamp('rang_at')->nullable();                                    // une page ouverte l'a fait sonner
            $table->timestamp('stopped_at')->nullable();                                 // arrêté, ou « OK » une fois fini
            $table->timestamp('notified_at')->nullable();                                // notification de fin envoyée
            $table->timestamps();

            $table->index(['household_id', 'stopped_at', 'ends_at']);
        });

        Schema::create('meal_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planned_meal_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('step_number');                                 // étape du plat (1 = réchauffer pour des restes)
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();     // vide : la personne qui cuisine le plat
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['planned_meal_id', 'step_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_tasks');
        Schema::dropIfExists('kitchen_timers');
    }
};
