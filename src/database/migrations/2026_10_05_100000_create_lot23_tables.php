<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 23 — tickets de caisse lus automatiquement (module 24 du document 07, règles R27 et R28).
 *
 *  - `receipts` : un ticket (photos, lecture, relecture, validation) ;
 *  - `receipt_lines` : ses lignes, et où chacune est allée (stock, prix, liste) ;
 *  - `receipt_label_mappings` : « LAIT DEMI ECR UHT » chez Cactus = Lait demi-écrémé, 1 l (R28) ;
 *  - `ocr_readings` : chaque appel au service de lecture, pour le suivi des coûts (24.7) ;
 *  - `expenses.receipt_id` : la dépense créée par un ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->string('status', 12)->default('draft');          // draft · review · validated
            $table->string('provider', 10)->nullable();              // mistral · azure · null = saisie manuelle
            $table->json('photo_paths')->nullable();                 // disque privé « local », jamais public
            $table->unsignedTinyInteger('pages')->default(0);
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('store_name', 150)->nullable();           // tel que lu sur le ticket
            $table->date('purchased_on')->nullable();
            $table->decimal('total', 10, 2)->nullable();             // total retenu (corrigeable)
            $table->decimal('read_total', 10, 2)->nullable();        // total lu par le service
            $table->json('raw_result')->nullable();                  // réponse nettoyée (sans numéro de carte)
            $table->string('error', 255)->nullable();
            $table->foreignId('shopping_list_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('photos_deleted_at')->nullable();
            $table->json('outcome')->nullable();                     // compte rendu de la validation (24.6)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('label', 200);                            // libellé du ticket
            $table->string('suggested_name', 150)->nullable();       // nom lisible proposé par le service
            $table->string('kind', 12)->default('article');          // article · non_food · discount · deposit · voucher · bag · tax · ignored
            $table->decimal('count', 10, 3)->nullable();             // « 2 × 1,29 » → 2
            $table->decimal('weight', 10, 3)->nullable();            // « 0,532 kg » → 532 (g)
            $table->decimal('unit_price', 10, 3)->nullable();
            $table->decimal('amount', 10, 2);                        // négatif pour une remise, un bon
            $table->decimal('discount', 10, 2)->default(0);          // remise rattachée à la ligne (R27)
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('pack_quantity', 10, 3)->nullable();     // contenu d'un paquet : 1 (l), 6 (œufs)
            $table->foreignId('pack_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('confidence', 10)->default('none');       // learned · name · product · suggested · none
            $table->boolean('doubtful')->default(false);             // quantité × prix ≠ montant, montant absent…
            $table->boolean('to_stock')->default(true);
            $table->foreignId('budget_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shopping_list_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ingredient_price_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('receipt_label_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();   // null = tous magasins
            $table->string('normalized_label', 150);
            $table->string('kind', 12)->default('article');          // article · non_food · ignored
            $table->foreignId('ingredient_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('pack_quantity', 10, 3)->nullable();
            $table->foreignId('pack_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('budget_category_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('confirmations')->default(1);
            $table->timestamps();

            $table->index(['normalized_label', 'store_id']);
        });

        Schema::create('ocr_readings', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 10);                           // receipt · recipe
            $table->string('provider', 10);
            $table->unsignedSmallInteger('pages')->default(1);
            $table->decimal('cost_estimate', 8, 4)->default(0);      // en dollars, indicatif
            $table->boolean('succeeded')->default(true);
            $table->foreignId('receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('receipt_id')->nullable()->after('recurring_expense_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('receipt_id');
        });

        Schema::dropIfExists('ocr_readings');
        Schema::dropIfExists('receipt_label_mappings');
        Schema::dropIfExists('receipt_lines');
        Schema::dropIfExists('receipts');
    }
};
