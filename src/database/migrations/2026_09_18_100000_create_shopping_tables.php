<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->date('period_start');
            $table->date('period_end');
            $table->boolean('include_past')->default(false);          // inclure les repas déjà passés
            $table->json('excluded_meal_ids')->nullable();            // repas décochés à la génération
            $table->string('status', 10)->default('active');           // App\Enums\ListStatus
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('shopping_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 200);                              // copié : lisible même si l'ingrédient disparaît
            $table->foreignId('aisle_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 12, 3)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->json('extra_quantities')->nullable();             // unités incompatibles : [{"q":1,"unit_id":12}]
            $table->string('origin', 10);                              // App\Enums\ItemOrigin
            $table->boolean('is_optional')->default(false);
            $table->boolean('is_checked')->default(false);
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->boolean('is_removed')->default(false);            // retiré volontairement (« j'en ai déjà »)
            $table->boolean('quantity_overridden')->default(false);   // quantité modifiée à la main
            $table->timestamps();

            $table->index(['shopping_list_id', 'aisle_id']);
        });

        Schema::create('shopping_list_item_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopping_list_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planned_meal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipe_title', 200);
            $table->date('meal_date');
            $table->string('slot_name', 50)->nullable();
            $table->decimal('quantity', 12, 3)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_optional')->default(false);
        });

        Schema::create('recurring_items', function (Blueprint $table) {
            $table->id();
            $table->string('label', 200);
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('aisle_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_items');
        Schema::dropIfExists('shopping_list_item_sources');
        Schema::dropIfExists('shopping_list_items');
        Schema::dropIfExists('shopping_lists');
    }
};
