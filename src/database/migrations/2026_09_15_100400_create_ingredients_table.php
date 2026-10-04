<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('name_plural', 150)->nullable();
            $table->string('search_name', 150)->unique(); // App\Support\NameNormalizer : recherche + doublons
            $table->foreignId('aisle_id')->constrained()->restrictOnDelete();
            $table->foreignId('default_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->decimal('piece_weight_g', 10, 3)->nullable(); // poids moyen d'une unité de comptage
            $table->boolean('is_staple')->default(false);         // produit de base (placard)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
