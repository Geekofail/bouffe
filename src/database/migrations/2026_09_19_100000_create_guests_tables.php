<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 6 — Convives & invités.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('group_name', 50)->nullable();   // Famille, Amis… : ajout d'un groupe d'un coup
            $table->boolean('is_child')->default(false);    // compte pour une fraction de portion
            $table->text('notes')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('group_name');
        });

        Schema::create('guest_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);                                             // App\Enums\RestrictionType
            $table->foreignId('ingredient_id')->nullable()->constrained()->restrictOnDelete();  // allergie / n'aime pas
            $table->foreignId('tag_id')->nullable()->constrained()->restrictOnDelete();         // régime : catégorie requise
            $table->string('note', 150)->nullable();
            $table->timestamps();

            $table->unique(['guest_id', 'type', 'ingredient_id', 'tag_id']);
        });

        // Convives particuliers d'une case du planning (date × créneau). Absent = foyer complet, sans invité.
        Schema::create('meal_occasions', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('meal_slot_id')->constrained()->restrictOnDelete();
            $table->string('title', 150)->nullable();                // « Anniversaire de Julie »
            $table->json('absent_user_ids')->nullable();             // membres du foyer absents
            $table->unsignedTinyInteger('extra_adults')->default(0); // invités sans nom
            $table->unsignedTinyInteger('extra_children')->default(0);
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['date', 'meal_slot_id']);
        });

        Schema::create('meal_occasion_guest', function (Blueprint $table) {
            $table->foreignId('meal_occasion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->constrained()->restrictOnDelete();

            $table->primary(['meal_occasion_id', 'guest_id']);
        });

        // Provenance de la liste de courses : « Lasagnes — dim. midi, 8 portions »
        Schema::table('shopping_list_item_sources', function (Blueprint $table) {
            $table->unsignedTinyInteger('servings')->nullable()->after('slot_name');
        });
    }

    public function down(): void
    {
        Schema::table('shopping_list_item_sources', function (Blueprint $table) {
            $table->dropColumn('servings');
        });

        Schema::dropIfExists('meal_occasion_guest');
        Schema::dropIfExists('meal_occasions');
        Schema::dropIfExists('guest_restrictions');
        Schema::dropIfExists('guests');
    }
};
