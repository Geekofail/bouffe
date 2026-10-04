<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 33 — Assistant culinaire (33.5) : chaque demande à l'assistant, pour suivre le coût et
 * appliquer le plafond mensuel. Ni la question ni la réponse ne sont gardées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);                          // transform · ideas · complete · question
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->decimal('cost_estimate', 10, 6)->default(0); // en euros
            $table->boolean('succeeded')->default(true);
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_usages');
    }
};
