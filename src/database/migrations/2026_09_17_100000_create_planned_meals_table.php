<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planned_meals', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('meal_slot_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('position')->default(1);           // ordre dans la case (plat, dessert…)
            $table->string('type', 10);                                    // App\Enums\MealType
            $table->foreignId('recipe_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('leftover_of_id')->nullable()->constrained('planned_meals')->nullOnDelete();
            $table->string('free_text', 200)->nullable();
            $table->unsignedTinyInteger('servings')->default(2);
            $table->string('comment', 255)->nullable();
            $table->timestamp('cooked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['date', 'meal_slot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_meals');
    }
};
