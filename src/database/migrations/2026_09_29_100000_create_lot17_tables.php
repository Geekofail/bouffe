<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 17 — magasins et budget.
 *
 *  - `stores` et `store_aisles` : chaque magasin a son ordre de rayons (15.3) ;
 *  - `ingredient_prices` : l'historique des prix relevés, par magasin (15.7, règle R17) ;
 *  - `ingredients.reference_price` : le prix retenu, ramené à l'unité de base (€/kg, €/l, €/pièce) ;
 *  - `shopping_list_items.paid_price` : ce qui a été payé, saisi en cochant, pour le budget (17.3) ;
 *  - `standing_items` : la liste « quand je passe », versée dans la prochaine liste créée (15.8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('color', 20)->default('stone');
            $table->string('note', 200)->nullable();          // « le mardi matin », « fermé le lundi »
            $table->boolean('is_default')->default(false);    // magasin proposé pour une nouvelle liste
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // L'ordre des rayons **dans ce magasin** : on parcourt les rayons dans l'ordre où on les traverse.
        Schema::create('store_aisles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('aisle_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_hidden')->default(false);     // rayon qu'on ne trouve pas dans ce magasin
            $table->timestamps();

            $table->unique(['store_id', 'aisle_id']);
        });

        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('name')->constrained()->nullOnDelete();
        });

        Schema::table('shopping_list_items', function (Blueprint $table) {
            // « seulement au marché » : l'article n'est à prendre que dans ce magasin.
            $table->foreignId('store_id')->nullable()->after('aisle_id')->constrained()->nullOnDelete();
            // Prix payé, saisi facultativement en cochant (15.7) ; alimente le budget (17.3).
            $table->decimal('paid_price', 10, 2)->nullable()->after('checked_at');
        });

        Schema::create('ingredient_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('price', 10, 2);                       // ce qui a été payé
            $table->decimal('quantity', 12, 3)->nullable();        // pour cette quantité…
            $table->foreignId('unit_id')->nullable()->constrained();  // …dans cette unité
            $table->decimal('unit_price', 14, 6)->nullable();      // ramené à l'unité de base (€/g, €/ml, €/pièce)
            $table->date('observed_on');
            $table->string('source', 20)->default('manual');       // manual · shopping
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['ingredient_id', 'observed_on']);
        });

        Schema::table('ingredients', function (Blueprint $table) {
            // Prix retenu pour les calculs, exprimé **par unité de base** (g, ml ou pièce).
            $table->decimal('reference_price', 14, 6)->nullable()->after('is_staple');   // 6 décimales : 4,98 €/kg = 0,004980 €/g
            $table->foreignId('reference_price_unit_id')->nullable()->after('reference_price')->constrained('units')->nullOnDelete();
            $table->boolean('reference_price_locked')->default(false)->after('reference_price_unit_id'); // prix saisi à la main : un relevé ne l'écrase pas
            $table->date('reference_price_on')->nullable()->after('reference_price_locked');
        });

        // Liste « quand je passe » (15.8) : hors semaine, versée dans la prochaine liste créée.
        Schema::create('standing_items', function (Blueprint $table) {
            $table->id();
            $table->string('label', 200);
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('aisle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('added_to_list_id')->nullable()->constrained('shopping_lists')->nullOnDelete();
            $table->timestamp('added_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reference_price_unit_id');
            $table->dropColumn(['reference_price', 'reference_price_locked', 'reference_price_on']);
        });

        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
            $table->dropColumn('paid_price');
        });

        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::dropIfExists('standing_items');
        Schema::dropIfExists('ingredient_prices');
        Schema::dropIfExists('store_aisles');
        Schema::dropIfExists('stores');
    }
};
