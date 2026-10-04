<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('label', 50);
            $table->string('label_plural', 50)->nullable();
            $table->string('type', 10);                          // App\Enums\UnitType
            $table->decimal('factor_to_base', 12, 4)->nullable(); // vers g (masse) ou ml (volume)
            $table->boolean('is_metric')->default(false);         // g, kg, ml, cl, l : affichage normalisé
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
