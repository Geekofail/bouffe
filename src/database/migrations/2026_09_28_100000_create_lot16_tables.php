<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 16 — en ligne et hors-ligne.
 *
 * `offline_operations` garde la trace des actions faites sans réseau et rejouées au retour
 * (règle R20). L'identifiant `uuid` vient du téléphone : rejouer deux fois la même action
 * (réseau instable, onglet rouvert) ne la compte qu'une fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();                       // généré sur le téléphone
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopping_list_item_id')->nullable();   // null pour un ajout
            $table->string('action', 20);                         // check · uncheck · add · quantity
            $table->string('value', 200)->nullable();             // libellé ajouté, quantité saisie
            $table->timestamp('happened_at');                     // heure du geste, pas de l'envoi
            $table->timestamp('applied_at')->nullable();
            $table->string('result', 20)->nullable();             // applied · ignored · moved
            $table->string('detail', 200)->nullable();
            $table->timestamps();

            $table->index(['shopping_list_id', 'happened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_operations');
    }
};
