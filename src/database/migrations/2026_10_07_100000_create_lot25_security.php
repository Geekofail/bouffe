<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 25 — mise en ligne (module 27 du document 07).
 *
 *  - double authentification (27.4) : secret chiffré, codes de secours (condensés), date de confirmation ;
 *  - journal des connexions (27.5) : réussites, échecs, codes refusés, réinitialisations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('remember_token');          // chiffré avec APP_KEY
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret'); // condensés, jamais en clair
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });

        Schema::create('login_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                     // login · failed · two_factor_failed · recovery_code · password_reset · locked · revoked
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('device', 16)->nullable();          // empreinte courte du navigateur (nouvel appareil ?)
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
