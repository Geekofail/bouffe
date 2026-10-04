<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 22 — dépenses et budget réel (module 23 du document 07).
 *
 *  - `budget_categories` : les postes (courses, restaurant…) ;
 *  - `budget_amounts` : le budget mensuel d'un poste, daté — changer de budget ne réécrit pas le passé ;
 *  - `expenses` + `expense_splits` : chaque dépense et sa répartition entre postes (ticket mixte) ;
 *  - `recurring_expenses` : cantine, panier bio, abonnement (C7).
 *
 * Le budget unique du lot 17 (réglage `budget.monthly`) devient celui du poste « Courses alimentaires ».
 */
return new class extends Migration
{
    /** Postes proposés au départ (question Q31) : [nom, genre, couleur]. */
    private const DEFAULTS = [
        ['Courses alimentaires', 'groceries', 'orange'],
        ['Droguerie et maison', 'household', 'sky'],
        ['Restaurant', 'restaurant', 'violet'],
        ['À emporter / livraison', 'takeaway', 'amber'],
        ['Midi au travail', 'work', 'green'],
        ['Boissons', 'drinks', 'pink'],
    ];

    public function up(): void
    {
        Schema::create('budget_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('kind', 20)->default('other');   // groceries · household · restaurant · takeaway · work · drinks · other
            $table->string('color', 20)->default('stone');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('budget_amounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_category_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);                // 0 = pas de budget pour ce poste
            $table->date('valid_from');                      // début de la période à partir de laquelle il s'applique

            $table->unique(['budget_category_id', 'valid_from'], 'budget_amount_unique');
        });

        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('label', 150);
            $table->decimal('amount', 10, 2);
            $table->foreignId('budget_category_id')->constrained()->restrictOnDelete();
            $table->string('frequency', 10)->default('monthly');   // monthly · weekly
            $table->unsignedTinyInteger('day')->default(1);        // jour du mois (1-28) ou de la semaine (1-7)
            $table->date('next_on');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->date('spent_on');
            $table->decimal('amount', 10, 2);
            $table->foreignId('budget_category_id')->constrained()->restrictOnDelete();   // poste principal
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('place', 150)->nullable();                // « Chez Mario », quand ce n'est pas un magasin connu
            $table->unsignedTinyInteger('persons')->nullable();      // restaurant : coût par personne
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('shopping_list_id')->nullable()->constrained()->nullOnDelete();   // R26
            $table->foreignId('planned_meal_id')->nullable()->constrained()->nullOnDelete();    // 23.4
            $table->foreignId('recurring_expense_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->string('source', 10)->default('manual');          // manual · receipt · recurring
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('spent_on');
        });

        Schema::create('expense_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_category_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 2);
        });

        $now = now();

        foreach (self::DEFAULTS as $i => [$name, $kind, $color]) {
            DB::table('budget_categories')->insert([
                'name' => $name, 'kind' => $kind, 'color' => $color, 'sort_order' => $i + 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Le budget du lot 17 devient celui des courses alimentaires, depuis toujours.
        $monthly = DB::table('settings')->where('key', 'budget.monthly')->value('value');

        if ($monthly !== null && (float) json_decode($monthly) > 0) {
            DB::table('budget_amounts')->insert([
                'budget_category_id' => DB::table('budget_categories')->where('kind', 'groceries')->value('id'),
                'amount' => (float) json_decode($monthly),
                'valid_from' => '2000-01-01',
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_splits');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('recurring_expenses');
        Schema::dropIfExists('budget_amounts');
        Schema::dropIfExists('budget_categories');
    }
};
