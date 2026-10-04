<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 9 — Stock relié au planning et aux courses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->boolean('deduct_stock')->default(true)->after('excluded_meal_ids');   // « Déduire ce qui est en stock »
        });

        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->string('stock_status', 10)->nullable()->after('stocked_at');         // covered | partial | out (R8)
            $table->decimal('stock_deducted', 12, 3)->nullable()->after('stock_status'); // quantité couverte par le stock (unité de l'article)
            $table->string('stock_note', 255)->nullable()->after('stock_deducted');       // « périme le 18/09, prévue le 20/09 — non comptée »
            $table->boolean('buy_anyway')->default(false)->after('stock_note');          // « Acheter quand même »
        });

        // Unité « portion » pour les restes rangés au réfrigérateur
        if (! DB::table('units')->where('code', 'portion')->exists()) {
            DB::table('units')->insert([
                'code' => 'portion', 'label' => 'portion', 'label_plural' => 'portions', 'type' => 'piece',
                'factor_to_base' => null, 'is_metric' => false, 'sort_order' => (int) DB::table('units')->max('sort_order') + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('shopping_list_items', fn (Blueprint $table) => $table->dropColumn(['stock_status', 'stock_deducted', 'stock_note', 'buy_anyway']));
        Schema::table('shopping_lists', fn (Blueprint $table) => $table->dropColumn('deduct_stock'));
    }
};
