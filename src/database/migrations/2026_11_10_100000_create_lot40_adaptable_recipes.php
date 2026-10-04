<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lot 40 — Recettes qui s'adaptent (module 40, règles R43 et R44).
 *
 * · ingredient_substitutions : remplacements, communs (household_id vide), du foyer, ou propres à
 *                              une recette (recipe_id)
 * · recipe_steps.uid         : identifiant stable d'une étape (les étapes sont réécrites à chaque
 *                              modification de la recette : notes et photos le suivent)
 * · recipe_step_notes        : « notre four chauffe fort : 170 °C »
 * · recipe_photos.step_uid   : la photo suit son étape, plus son numéro
 * · recipes.equipment, stays.equipment : ce que demande une recette, ce qu'offre un séjour
 * · products.allergens, products.traces, products.allergens_checked_at : Open Food Facts (R44)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_substitutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->nullable()->constrained()->cascadeOnDelete();   // vide : liste commune
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('substitute_id')->constrained('ingredients')->cascadeOnDelete();
            $table->decimal('ratio', 6, 3)->default(1);                                       // quantité × ratio (R43)
            $table->foreignId('recipe_id')->nullable()->constrained()->cascadeOnDelete();     // vide : pour toutes les recettes
            $table->string('note', 150)->nullable();
            $table->string('source', 10)->default('manual');                                  // seed · manual · assistant · variant
            $table->timestamps();

            $table->index(['ingredient_id', 'household_id']);
        });

        Schema::table('recipe_steps', function (Blueprint $table) {
            $table->string('uid', 12)->nullable()->after('recipe_id');
            $table->index(['recipe_id', 'uid']);
        });

        foreach (DB::table('recipe_steps')->whereNull('uid')->orderBy('id')->pluck('id') as $id) {
            DB::table('recipe_steps')->where('id', $id)->update(['uid' => Str::lower(Str::random(10))]);
        }

        Schema::create('recipe_step_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->string('step_uid', 12);
            $table->string('note', 500);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['recipe_id', 'step_uid']);
        });

        Schema::table('recipe_photos', function (Blueprint $table) {
            $table->string('step_uid', 12)->nullable()->after('step_number');
        });

        // Une photo d'étape suit désormais l'étape où elle est aujourd'hui.
        foreach (DB::table('recipe_photos')->where('kind', 'step')->whereNotNull('step_number')->get(['id', 'recipe_id', 'step_number']) as $photo) {
            $uid = DB::table('recipe_steps')->where('recipe_id', $photo->recipe_id)->orderBy('position')->orderBy('id')
                ->offset(max(0, (int) $photo->step_number - 1))->limit(1)->value('uid');
            DB::table('recipe_photos')->where('id', $photo->id)->update(['step_uid' => $uid]);
        }

        Schema::table('recipes', function (Blueprint $table) {
            $table->json('equipment')->nullable();      // vide : d'après le texte des étapes
        });

        Schema::table('stays', function (Blueprint $table) {
            $table->json('equipment')->nullable();      // vide : comme à la maison
        });

        Schema::table('products', function (Blueprint $table) {
            $table->json('allergens')->nullable();      // étiquettes Open Food Facts : en:milk, en:gluten…
            $table->json('traces')->nullable();
            $table->timestamp('allergens_checked_at')->nullable();
        });

        // Q58 : une courte liste commune de remplacements (seulement avec les ingrédients du catalogue).
        (new \Database\Seeders\SubstitutionSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['allergens', 'traces', 'allergens_checked_at']));
        Schema::table('stays', fn (Blueprint $table) => $table->dropColumn('equipment'));
        Schema::table('recipes', fn (Blueprint $table) => $table->dropColumn('equipment'));
        Schema::table('recipe_photos', fn (Blueprint $table) => $table->dropColumn('step_uid'));
        Schema::dropIfExists('recipe_step_notes');
        // MySQL/MariaDB servent la clé étrangère recipe_id par l'index composé : on lui en redonne un.
        Schema::table('recipe_steps', fn (Blueprint $table) => $table->index('recipe_id'));
        Schema::table('recipe_steps', function (Blueprint $table) {
            $table->dropIndex(['recipe_id', 'uid']);
            $table->dropColumn('uid');
        });
        Schema::dropIfExists('ingredient_substitutions');
    }
};
