<?php

use App\Support\StockDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 7 — Stock (garde-manger) : emplacements, réglages de conservation des ingrédients,
 * articles en stock et mouvements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('type', 10);                           // App\Enums\LocationType : fresh, freezer, ambient
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('last_inventory_at')->nullable();  // lot 9
            $table->timestamps();
        });

        Schema::table('ingredients', function (Blueprint $table) {
            $table->string('stock_mode', 10)->default('quantity')->after('is_staple');  // App\Enums\StockMode
            $table->foreignId('storage_location_id')->nullable()->after('stock_mode')->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('shelf_life_days')->nullable()->after('storage_location_id');
            $table->string('shelf_life_type', 4)->default('none')->after('shelf_life_days');  // App\Enums\ExpiryType
            $table->unsignedSmallInteger('days_after_opening')->nullable()->after('shelf_life_type');
            $table->unsignedTinyInteger('freezer_months')->nullable()->after('days_after_opening');  // null = non congelable
            $table->decimal('min_stock_quantity', 12, 3)->nullable()->after('freezer_months');       // lot 9
            $table->foreignId('min_stock_unit_id')->nullable()->after('min_stock_quantity')->constrained('units')->nullOnDelete();
        });

        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->nullable()->constrained()->restrictOnDelete();  // null = plat préparé
            $table->string('label', 150)->nullable();
            $table->foreignId('planned_meal_id')->nullable()->constrained()->nullOnDelete();    // restes d'un repas (lot 9)
            $table->decimal('quantity', 12, 3)->nullable();          // null = quantité inconnue
            $table->decimal('initial_quantity', 12, 3)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_present')->default(true);            // mode présence
            $table->foreignId('storage_location_id')->constrained()->restrictOnDelete();
            $table->date('expires_on')->nullable();
            $table->string('expiry_type', 4)->default('none');
            $table->date('opened_on')->nullable();
            $table->date('frozen_on')->nullable();
            $table->date('thawed_on')->nullable();
            $table->foreignId('shopping_list_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('finished_at')->nullable();            // consommé ou jeté : masqué, gardé pour l'annulation
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['finished_at', 'storage_location_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 150);
            $table->string('type', 10);                               // App\Enums\MovementType
            $table->decimal('quantity', 12, 3)->nullable();          // variation (négative en sortie)
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 50)->nullable();
            $table->json('snapshot')->nullable();                     // état de l'article avant l'action (annulation)
            $table->foreignId('reverts_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->foreignId('planned_meal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shopping_list_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['stock_item_id', 'created_at']);
        });

        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->timestamp('stocked_at')->nullable()->after('quantity_overridden');   // rangé dans le stock (ou ignoré)
        });

        // Installations existantes : emplacements et réglages de conservation des ingrédients déjà présents.
        StockDefaults::seedLocations();
        StockDefaults::applyToUnconfiguredIngredients();
    }

    public function down(): void
    {
        Schema::table('shopping_list_items', fn (Blueprint $table) => $table->dropColumn('stocked_at'));
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_items');

        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('storage_location_id');
            $table->dropConstrainedForeignId('min_stock_unit_id');
            $table->dropColumn(['stock_mode', 'shelf_life_days', 'shelf_life_type', 'days_after_opening', 'freezer_months', 'min_stock_quantity']);
        });

        Schema::dropIfExists('storage_locations');
    }
};
