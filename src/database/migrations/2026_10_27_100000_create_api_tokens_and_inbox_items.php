<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 38 — Raccourcis, voix et partage (module 38, règle R39).
 *
 * · api_tokens  : jeton personnel des raccourcis (Siri, Raccourcis d'Apple) ; seule son empreinte
 *                 est gardée, il n'est montré qu'une fois ; révocable
 * · inbox_items : « À trier » — adresses, textes et photos de recettes reçus, en attente de relecture
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();                 // SHA-256 du jeton
            $table->string('hint', 8);                                // les 4 derniers caractères, pour le reconnaître
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('inbox_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10);                               // url · text · photo
            $table->string('via', 12)->default('app');               // raccourci · partage · app
            $table->string('url', 2000)->nullable();
            $table->text('text')->nullable();                        // texte reçu, ou lu sur la photo
            $table->string('photo_path')->nullable();                // disque privé, effacée une fois lue
            $table->string('title', 200)->nullable();
            $table->string('image_url', 2000)->nullable();
            $table->json('payload')->nullable();                     // données de recette trouvées sur la page
            $table->string('status', 10)->default('pending');        // pending · ready · failed · kept · discarded
            $table->string('error', 255)->nullable();
            $table->foreignId('recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['household_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_items');
        Schema::dropIfExists('api_tokens');
    }
};
