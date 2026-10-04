<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 11 — Confort au quotidien :
 *  - alias d'ingrédients (anciens noms après une fusion, R22) et historique des fusions (annulation) ;
 *  - quantités ajoutées à la main fusionnées dans un article généré (15.5) ;
 *  - préférences d'affichage par utilisateur (barre du bas, accueil).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('search_name', 150)->unique();
            $table->timestamps();
        });

        Schema::create('ingredient_merges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_id');                  // ingrédient supprimé (id conservé pour l'annulation)
            $table->string('source_name', 150);
            $table->foreignId('target_id')->constrained('ingredients')->cascadeOnDelete();
            $table->json('payload');                                  // attributs de la source + identifiants réaffectés
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
        });

        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->decimal('added_quantity', 12, 3)->nullable()->after('quantity_overridden');
            $table->foreignId('added_unit_id')->nullable()->after('added_quantity')->constrained('units')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('preferences')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('preferences'));
        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('added_unit_id');
            $table->dropColumn('added_quantity');
        });
        Schema::dropIfExists('ingredient_merges');
        Schema::dropIfExists('ingredient_aliases');
    }
};
