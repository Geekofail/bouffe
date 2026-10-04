<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 30 — Bouffe apprend et prévient (module 30 du document 08, règles R32, R35, R36).
 *
 *  - gestes annulables pendant 10 secondes (R32) ;
 *  - journal du foyer, gardé 30 jours ;
 *  - propositions apprises (stock minimum, durées), avec « Non merci » pendant 90 jours (R36) ;
 *  - prix en promotion (R35).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Annuler (30.1, R32) : l'état d'avant d'un geste, et celui d'après pour vérifier que rien n'a bougé entre-temps.
        Schema::create('undo_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->char('token', 32)->unique();
            $table->string('action', 40);                       // planning.clear, shopping.remove, prices.delete…
            $table->string('label', 150);                       // « Semaine vidée (5 repas) »
            $table->json('payload');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('expires_at');
        });

        // Journal du foyer (30.2) : quelques lignes par jour, regroupées (« a coché 12 articles »).
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);                         // shopping.checked, planning.planned, wish.added…
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedInteger('count')->default(1);
            $table->string('summary', 255);
            $table->timestamps();

            $table->index(['household_id', 'created_at']);
            $table->index(['household_id', 'user_id', 'type', 'updated_at'], 'activity_group_index');
        });

        // Apprentissages (30.4, 30.5, R36) : ce qui a été appliqué ou refusé, par foyer et par produit.
        Schema::create('learned_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);                         // min_stock · after_opening · shelf_life
            $table->json('proposed');                           // la valeur proposée et ce qui l'a motivée
            $table->string('status', 10);                       // applied · dismissed
            $table->timestamp('silenced_until')->nullable();    // « Non merci » : 90 jours
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['household_id', 'kind', 'ingredient_id']);
        });

        // Promotions (30.7, R35).
        Schema::table('ingredient_prices', function (Blueprint $table) {
            $table->boolean('is_promo')->default(false)->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('ingredient_prices', fn (Blueprint $table) => $table->dropColumn('is_promo'));
        Schema::dropIfExists('learned_suggestions');
        Schema::dropIfExists('activity_events');
        Schema::dropIfExists('undo_tokens');
    }
};
