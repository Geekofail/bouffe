<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aisles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0); // ordre de parcours du magasin
            $table->string('color', 20)->default('stone');           // clé App\Support\Palette
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aisles');
    }
};
