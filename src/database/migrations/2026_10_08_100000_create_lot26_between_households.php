<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 26 — entre foyers (module 26 du document 07, règle R31, idée C4).
 *
 *  - foyers reliés (l'un invite, l'autre accepte) et ce que chacun ouvre à l'autre ;
 *  - recettes : visibilité, origine d'une copie (R31) ; avis des proches ;
 *  - repas commun : foyers invités à une réception, plats apportés ;
 *  - contraintes partagées avec consentement ; agenda du planning (ICS) ;
 *  - surplus à donner ; listes de courses groupées.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Lien entre deux foyers : créé par l'un (jeton d'invitation), accepté par l'autre.
        Schema::create('household_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();               // foyer qui invite
            $table->foreignId('linked_household_id')->nullable()->constrained('households')->cascadeOnDelete();
            $table->char('token_hash', 64)->nullable()->unique();                              // seul le condensé est gardé
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['linked_household_id', 'accepted_at']);
        });

        // Ce qu'un foyer ouvre à un foyer relié (chacun règle son côté).
        Schema::create('household_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();               // qui partage
            $table->foreignId('target_household_id')->constrained('households')->cascadeOnDelete();
            $table->boolean('recipes_all')->default(false);                                    // « tout mon carnet » (26.1)
            $table->string('planning', 5)->default('none');                                   // none · read · write (26.4)
            $table->timestamps();

            $table->unique(['household_id', 'target_household_id']);
        });

        Schema::table('recipes', function (Blueprint $table) {
            $table->string('visibility', 10)->default('private')->after('archived_at');        // private · linked · instance (26.1, Q36)
            $table->timestamp('shared_at')->nullable()->after('visibility');                    // ouverte aux proches le… (fil, 26.10)
            $table->foreignId('origin_recipe_id')->nullable()->after('visibility')->constrained('recipes')->nullOnDelete();
            $table->foreignId('origin_household_id')->nullable()->after('origin_recipe_id')->constrained('households')->nullOnDelete();
            $table->timestamp('origin_synced_at')->nullable()->after('origin_household_id');
            $table->char('origin_hash', 40)->nullable()->after('origin_synced_at');           // contenu de l'original à la copie (R31)
            $table->index('visibility');
        });

        // Avis des proches (26.3) : le foyer de la personne qui note.
        Schema::table('recipe_ratings', function (Blueprint $table) {
            $table->foreignId('household_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        // Repas commun (26.5) : foyers reliés invités à une réception, et ce qu'ils apportent.
        Schema::create('meal_occasion_households', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_occasion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();               // foyer invité
            $table->string('status', 10)->default('invited');                                 // invited · accepted · declined
            $table->unsignedTinyInteger('people')->nullable();                                 // personnes qui viennent
            $table->string('message', 255)->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['meal_occasion_id', 'household_id'], 'occasion_household_unique');
        });

        Schema::table('planned_meals', function (Blueprint $table) {
            $table->foreignId('for_occasion_id')->nullable()->after('course')->constrained('meal_occasions')->nullOnDelete();
        });

        // Contraintes tenues par la personne (26.6) ; agenda du planning (C4).
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('share_restrictions')->default(false)->after('is_admin');
            $table->text('calendar_token')->nullable()->after('share_restrictions');          // chiffré (réaffichage)
            $table->char('calendar_token_hash', 64)->nullable()->unique()->after('calendar_token');
        });

        // Surplus à donner (26.7).
        Schema::create('surplus_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();               // qui donne
            $table->foreignId('stock_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 150);
            $table->string('quantity', 60)->nullable();
            $table->date('available_until');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reserved_by_household_id')->nullable()->constrained('households')->nullOnDelete();
            $table->foreignId('reserved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('handed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['household_id', 'available_until']);
        });

        // Liste de courses groupée (26.8).
        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->boolean('shared_with_links')->default(false)->after('status');
        });

        Schema::table('shopping_list_items', function (Blueprint $table) {
            // Article ajouté pour un foyer relié ; son prix payé (paid_price) est à rembourser.
            $table->foreignId('for_household_id')->nullable()->after('shopping_list_id')->constrained('households')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('for_household_id');
        });

        Schema::table('shopping_lists', fn (Blueprint $table) => $table->dropColumn('shared_with_links'));
        Schema::dropIfExists('surplus_offers');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['calendar_token_hash']);
            $table->dropColumn(['share_restrictions', 'calendar_token', 'calendar_token_hash']);
        });

        Schema::table('planned_meals', fn (Blueprint $table) => $table->dropConstrainedForeignId('for_occasion_id'));
        Schema::dropIfExists('meal_occasion_households');
        Schema::table('recipe_ratings', fn (Blueprint $table) => $table->dropConstrainedForeignId('household_id'));

        Schema::table('recipes', function (Blueprint $table) {
            $table->dropIndex(['visibility']);
            $table->dropConstrainedForeignId('origin_recipe_id');
            $table->dropConstrainedForeignId('origin_household_id');
            $table->dropColumn(['visibility', 'shared_at', 'origin_synced_at', 'origin_hash']);
        });

        Schema::dropIfExists('household_shares');
        Schema::dropIfExists('household_links');
    }
};
