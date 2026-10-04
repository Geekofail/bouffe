<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 20 — rappels et réceptions.
 *
 *  - `recipe_components` : une recette en utilise une autre (pâte brisée, béchamel) — 13.8 ;
 *  - `planned_meals.course` : apéritif, entrée, plat, fromage, dessert dans une même case — 21.1 ;
 *  - `planned_meals.prepared_at` : plat cuisiné à l'avance (batch cooking) — 14.8 ;
 *  - `meal_occasions` : heure du repas, message de la carte, souvenirs, rétroplanning coché — 21 ;
 *  - `push_subscriptions` : téléphones et navigateurs abonnés aux notifications — 19.2 ;
 *  - `notification_deliveries` : ce qui a déjà été envoyé, pour ne jamais l'envoyer deux fois — 19.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            // Une recette utilisée comme sous-recette ne peut pas être supprimée (archivage).
            $table->foreignId('component_recipe_id')->constrained('recipes')->restrictOnDelete();
            $table->decimal('quantity', 8, 3)->default(1);        // 1 = la sous-recette telle qu'écrite, 0,5 = la moitié
            $table->string('note', 150)->nullable();               // « pour le fond de tarte »
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['recipe_id', 'component_recipe_id'], 'recipe_component_unique');
        });

        Schema::table('planned_meals', function (Blueprint $table) {
            $table->string('course', 12)->nullable()->after('position');        // App\Enums\Course
            $table->timestamp('prepared_at')->nullable()->after('cooked_at');   // cuisiné à l'avance
        });

        Schema::table('meal_occasions', function (Blueprint $table) {
            $table->string('serve_time', 5)->nullable()->after('title');        // « 19:30 »
            $table->string('menu_message', 255)->nullable()->after('notes');    // mot sur la carte de menu
            $table->json('timeline_done')->nullable()->after('menu_message');   // tâches du rétroplanning cochées
            $table->text('memory_note')->nullable()->after('timeline_done');    // souvenir (21.4)
            $table->string('memory_photo', 255)->nullable()->after('memory_note');
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key', 255);                      // clé p256dh du navigateur
            $table->string('auth_token', 255);
            $table->string('device', 120)->nullable();              // « iPhone », « Chrome sur Windows »
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedTinyInteger('failures')->default(0);
            $table->timestamps();
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 120);                             // « reminder:42 », « expiry:2026-10-15 »
            $table->string('channel', 10);                          // push · mail
            $table->timestamp('sent_at');

            $table->unique(['user_id', 'key', 'channel'], 'notification_delivery_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('push_subscriptions');

        Schema::table('meal_occasions', function (Blueprint $table) {
            $table->dropColumn(['serve_time', 'menu_message', 'timeline_done', 'memory_note', 'memory_photo']);
        });

        Schema::table('planned_meals', function (Blueprint $table) {
            $table->dropColumn(['course', 'prepared_at']);
        });

        Schema::dropIfExists('recipe_components');
    }
};
