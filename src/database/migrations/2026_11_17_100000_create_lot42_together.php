<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 42 — Ensemble (module 42, règle R45).
 *
 * · stay_households          : un foyer relié invité à co-organiser un séjour (42.1) ; son départ
 *                              et son retour pour « à emporter » (son stock reste le sien)
 * · stay_meals.household_id, stay_payments.household_id, stay_packed_items.household_id :
 *                              qui a prévu le plat, noté la dépense, emporté l'article (vide :
 *                              le foyer qui organise)
 * · contributions            : « qui apporte quoi » d'une réception ou d'un séjour (42.2)
 * · contribution_links       : le lien à envoyer à ceux qui n'ont pas Bouffe (42.2)
 * · shopping_list_aisle_owners : courses à deux, « chacun ses rayons » (42.3)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_households', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10)->default('invited');          // invited · accepted · declined · removed · left
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('departed_at')->nullable();               // « C'est parti » de ce foyer (34.4)
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();

            $table->unique(['stay_id', 'household_id']);
            $table->index(['household_id', 'status']);
        });

        Schema::table('stay_meals', function (Blueprint $table) {
            $table->foreignId('household_id')->nullable()->after('stay_id')->constrained()->nullOnDelete();
        });

        Schema::table('stay_payments', function (Blueprint $table) {
            $table->foreignId('household_id')->nullable()->after('stay_id')->constrained()->nullOnDelete();
        });

        Schema::table('stay_packed_items', function (Blueprint $table) {
            $table->foreignId('household_id')->nullable()->after('stay_id')->constrained()->cascadeOnDelete();
        });

        Schema::create('contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();                 // le foyer qui reçoit
            $table->foreignId('meal_occasion_id')->nullable()->constrained()->cascadeOnDelete(); // une réception…
            $table->foreignId('stay_id')->nullable()->constrained()->cascadeOnDelete();          // … ou un séjour
            $table->string('label', 100);
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();       // « pain » : sort de la liste du séjour
            $table->foreignId('planned_meal_id')->nullable()->constrained()->nullOnDelete();     // le plat prévu qu'on apporte
            $table->foreignId('stay_meal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('by_label', 60)->nullable();                                          // vide : personne encore
            $table->foreignId('by_household_id')->nullable()->constrained('households')->nullOnDelete();
            $table->foreignId('by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token_hash', 64)->nullable();                                        // inscrit par le lien : clé du navigateur
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['meal_occasion_id']);
            $table->index(['stay_id']);
        });

        Schema::create('contribution_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_occasion_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('stay_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->text('token');                                      // chiffré : l'adresse se recopie
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('shopping_list_aisle_owners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->integer('aisle_id');                                // rayon du mode magasin : 0 autres, -1 placard, -2 ajoutés
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['shopping_list_id', 'aisle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_list_aisle_owners');
        Schema::dropIfExists('contribution_links');
        Schema::dropIfExists('contributions');
        Schema::table('stay_packed_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('household_id'));
        Schema::table('stay_payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('household_id'));
        Schema::table('stay_meals', fn (Blueprint $table) => $table->dropConstrainedForeignId('household_id'));
        Schema::dropIfExists('stay_households');
    }
};
