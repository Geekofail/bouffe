<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 12 — notes de cuisine datées (13.6) : « trop salé », « +10 min de cuisson »,
 * écrites après avoir cuisiné et affichées en haut de la fiche la fois suivante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_cook_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('planned_meal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 500);
            $table->unsignedTinyInteger('servings')->nullable();   // portions réellement cuisinées
            $table->timestamps();

            $table->index(['recipe_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_cook_notes');
    }
};
