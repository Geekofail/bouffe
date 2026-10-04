<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 14 — planning intelligent.
 *
 * · week_templates + week_template_meals : semaines types (14.3, règle R16)
 * · wishes                               : envies « à planifier bientôt » (14.5)
 * · reminders                            : rappels de préparation anticipée (14.6, règle R21)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('week_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('week_template_meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('week_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');                       // 1 = lundi … 7 = dimanche
            $table->foreignId('meal_slot_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('type', 10);                                   // App\Enums\MealType
            $table->foreignId('recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->string('free_text', 200)->nullable();
            $table->unsignedTinyInteger('leftover_weekday')->nullable();  // restes : case d'origine dans le modèle
            $table->unsignedBigInteger('leftover_meal_slot_id')->nullable();
            $table->timestamps();

            $table->index(['week_template_id', 'weekday']);
        });

        Schema::create('wishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recipe_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('text', 200)->nullable();                      // envie libre : « raclette »
            $table->foreignId('planned_meal_id')->nullable()->constrained('planned_meals')->nullOnDelete();
            $table->timestamp('planned_at')->nullable();                  // placée au planning
            $table->timestamps();

            $table->index(['planned_at']);
        });

        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planned_meal_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);                                    // identifie le rappel dans le repas (recalcul)
            $table->string('type', 20);                                   // App\Enums\ReminderType
            $table->string('title', 200);
            $table->string('detail', 300)->nullable();
            $table->timestamp('due_at');
            $table->string('status', 10)->default('pending');             // pending · done · ignored
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->unique(['planned_meal_id', 'key']);
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('wishes');
        Schema::dropIfExists('week_template_meals');
        Schema::dropIfExists('week_templates');
    }
};
