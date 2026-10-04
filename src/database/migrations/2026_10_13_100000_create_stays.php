<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 34 — Séjours et grandes tablées (module 34, règle R37).
 *
 * · stays               : le séjour (dates, lieu, partage des frais), rangé chez le foyer qui l'organise
 * · stay_participants   : qui vient, avec son appétit, son « groupe » (qui paie ensemble) et ses jours
 * · stay_meals          : le planning du séjour, à part de celui de la maison
 * · stay_payments       : dépenses avancées et remboursements cochés
 * · stay_packed_items   : ce qui part de la maison (retiré du stock au départ, le reste remis au retour)
 * · shopping_lists.stay_id : la liste de courses du séjour, sans stock
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('place', 150)->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('split_mode', 10)->default('appetite');      // person · appetite (R37)
            $table->text('notes')->nullable();
            $table->timestamp('departed_at')->nullable();                // « à emporter » retiré du stock
            $table->timestamp('returned_at')->nullable();                // le reste remis au stock
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['household_id', 'starts_on']);
        });

        Schema::create('stay_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('appetite', 10)->default('normal');
            $table->string('group_label', 60);                           // qui paie ensemble (« Famille Martin »)
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();                    // membre du foyer
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();                   // invité du carnet
            $table->foreignId('linked_household_id')->nullable()->constrained('households')->nullOnDelete();
            $table->foreignId('linked_user_id')->nullable()->constrained('users')->nullOnDelete();      // compte d'un foyer relié
            $table->date('present_from')->nullable();                    // vide = dès le début
            $table->date('present_to')->nullable();                      // vide = jusqu'à la fin
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('stay_meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('meal_slot_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->foreignId('recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->string('free_text', 150)->nullable();
            $table->decimal('servings', 5, 1)->nullable();               // vide = calculé d'après les présents
            $table->timestamps();

            $table->index(['stay_id', 'date']);
        });

        Schema::create('stay_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10)->default('expense');              // expense · refund
            $table->string('group_label', 60);                           // qui a payé
            $table->string('to_group_label', 60)->nullable();            // remboursement : à qui
            $table->string('label', 150);
            $table->decimal('amount', 10, 2);
            $table->date('paid_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stay_packed_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 150);
            $table->decimal('quantity', 12, 3)->nullable();               // vide = tout l'article
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('storage_location_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('taken_at')->nullable();
            $table->decimal('returned_quantity', 12, 3)->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
        });

        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->foreignId('stay_id')->nullable()->after('store_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shopping_lists', fn (Blueprint $table) => $table->dropConstrainedForeignId('stay_id'));
        Schema::dropIfExists('stay_packed_items');
        Schema::dropIfExists('stay_payments');
        Schema::dropIfExists('stay_meals');
        Schema::dropIfExists('stay_participants');
        Schema::dropIfExists('stays');
    }
};
