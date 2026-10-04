<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 31 — Recettes enrichies (module 31 du document 08).
 *
 *  - collections + collection_recipe : regroupements libres de recettes (31.1) ;
 *  - recipe_photos : photos d'étapes et « notre version » (31.2) — la photo principale reste
 *    recipes.photo_path ; une photo d'étape retient le numéro de l'étape ;
 *  - recipe_share_links : liens de lecture pour quelqu'un qui n'a pas Bouffe (31.4) ;
 *  - recipe_revisions : historique des modifications d'une recette (31.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->string('visibility', 12)->default('private');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['household_id', 'name']);
        });

        Schema::create('collection_recipe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->unique(['collection_id', 'recipe_id']);
        });

        Schema::create('recipe_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            // Numéro de l'étape (1, 2…) plutôt qu'une clé : les étapes sont réécrites à chaque modification.
            $table->unsignedSmallInteger('step_number')->nullable();
            $table->string('kind', 12);                       // step · ours
            $table->string('path', 120);
            $table->string('caption', 150)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('planned_meal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['recipe_id', 'kind']);
        });

        Schema::create('recipe_share_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('recipe_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 12);                     // origin · edit · restore
            $table->string('summary', 255)->nullable();
            $table->json('snapshot');
            $table->timestamp('created_at')->nullable();

            $table->index(['recipe_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_revisions');
        Schema::dropIfExists('recipe_share_links');
        Schema::dropIfExists('recipe_photos');
        Schema::dropIfExists('collection_recipe');
        Schema::dropIfExists('collections');
    }
};
