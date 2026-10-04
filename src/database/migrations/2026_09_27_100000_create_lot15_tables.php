<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 15 — foyer.
 *
 * · household_restrictions        : allergies, « n'aime pas » et régimes des membres du foyer (18.1)
 * · planned_meals.cook_user_id    : qui cuisine ce repas, ou « ensemble » (14.7 / 18.3)
 * · meal_reactions                : 👍 / 👎 après un repas mangé (18.4)
 * · users.role                    : compte complet ou consultation + courses (18.5)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('household_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);                                                        // App\Enums\RestrictionType
            $table->foreignId('ingredient_id')->nullable()->constrained()->restrictOnDelete(); // allergie / n'aime pas
            $table->foreignId('tag_id')->nullable()->constrained()->restrictOnDelete();        // régime : catégorie requise
            $table->string('note', 150)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'type', 'ingredient_id', 'tag_id']);
        });

        Schema::create('meal_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planned_meal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('value');                                                      // 1 = 👍 · -1 = 👎
            $table->string('comment', 200)->nullable();
            $table->timestamps();

            $table->unique(['planned_meal_id', 'user_id']);
        });

        Schema::table('planned_meals', function (Blueprint $table) {
            $table->foreignId('cook_user_id')->nullable()->after('servings')->constrained('users')->nullOnDelete();
            $table->boolean('cook_together')->default(false)->after('cook_user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 10)->default('full')->after('email');                       // App\Enums\UserRole
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));

        Schema::table('planned_meals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cook_user_id');
            $table->dropColumn('cook_together');
        });

        Schema::dropIfExists('meal_reactions');
        Schema::dropIfExists('household_restrictions');
    }
};
