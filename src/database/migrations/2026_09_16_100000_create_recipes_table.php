<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200)->unique();
            $table->string('slug', 220)->unique();
            $table->string('search_title', 200)->index();           // App\Support\NameNormalizer
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('servings')->default(2);     // portions de base
            $table->unsignedSmallInteger('prep_minutes')->nullable();
            $table->unsignedSmallInteger('cook_minutes')->nullable();
            $table->unsignedSmallInteger('rest_minutes')->nullable();
            $table->string('difficulty', 10)->nullable();            // App\Enums\Difficulty
            $table->string('photo_path', 255)->nullable();           // disque local, servi par RecipePhotoController
            $table->string('source', 500)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 10, 3)->nullable();            // null = « à convenance »
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('preparation', 150)->nullable();           // « émincé »
            $table->string('group_name', 80)->nullable();             // « Pâte », « Garniture »
            $table->boolean('is_optional')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('recipe_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->text('instruction');
            $table->timestamps();
        });

        Schema::create('recipe_tag', function (Blueprint $table) {
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['recipe_id', 'tag_id']);
        });

        Schema::create('recipe_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');                     // 1 à 5
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['recipe_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_ratings');
        Schema::dropIfExists('recipe_tag');
        Schema::dropIfExists('recipe_steps');
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipes');
    }
};
