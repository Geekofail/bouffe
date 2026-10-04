<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 18 — le stock sans saisie.
 *
 *  - `products` : les produits scannés (16.1). Un code-barres n'est demandé qu'une fois à
 *    Open Food Facts : ensuite il est connu de la maison, avec l'ingrédient qu'on lui a associé ;
 *  - `stock_items.servings` : les portions d'un plat maison au congélateur (16.4) ;
 *  - `stock_items.product_id` : d'où vient l'article quand il a été scanné.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('barcode', 20)->unique();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();  // association faite une fois
            $table->string('label', 200);                       // « Lait demi-écrémé UHT »
            $table->string('brand', 100)->nullable();            // « Luxlait »
            $table->decimal('quantity', 12, 3)->nullable();      // contenu du paquet : 1, 500…
            $table->foreignId('unit_id')->nullable()->constrained();  // …l, g
            $table->string('image_url', 300)->nullable();
            $table->json('payload')->nullable();                 // réponse brute utile (marque, catégories)
            $table->string('source', 20)->default('off');        // off · manual
            $table->unsignedInteger('times_scanned')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('ingredient_id');
        });

        Schema::table('stock_items', function (Blueprint $table) {
            // Plats maison au congélateur (16.4) : « 4 portions de chili ».
            $table->unsignedSmallInteger('servings')->nullable()->after('initial_quantity');
            $table->foreignId('product_id')->nullable()->after('ingredient_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn('servings');
        });

        Schema::dropIfExists('products');
    }
};
