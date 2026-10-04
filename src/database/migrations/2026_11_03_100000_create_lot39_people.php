<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 39 — Enfants, école et cantine (module 39, règles R40 à R42).
 *
 * · household_people          : les personnes du foyer, compte ou pas (remplace le réglage
 *                               « table.people » du lot 32, repris à l'identique)
 * · person_restrictions       : goûts et allergies d'une personne (reprend household_restrictions,
 *                               qui disparaît : chaque contrainte suit la personne du compte)
 * · meal_occasions.absent_person_ids : absents sans compte (un enfant chez ses grands-parents)
 * · planned_meals.for_person_id : gamelle de n'importe qui (remplace for_user_id)
 * · stay_participants.person_id : la personne du foyer partie en séjour (ses allergies la suivent)
 * · canteen_meals             : menu de la cantine, jour par jour, ou « pas de cantine ce jour-là »
 * · child_choices             : « Choisis ton dîner de mercredi » (39.3)
 * · recipes.kid_friendly, recipe_steps.adult_help : « facile avec un enfant » (39.4)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('household_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // le compte rattaché, s'il y en a un
            $table->string('name', 60);
            $table->string('appetite', 10)->default('normal');                        // petit · moyen · normal · grand (R33)
            $table->string('color', 12)->nullable();                                  // clé de HouseholdPerson::COLORS
            $table->boolean('at_table')->default(true);                               // compte dans les portions d'habitude
            $table->json('canteen_days')->nullable();                                 // jours de cantine : 1 (lundi) à 5
            $table->string('canteen_name', 80)->nullable();                           // « Lycée », « Maison relais »
            $table->boolean('share_tastes')->default(false);                          // goûts montrés aux foyers reliés (sans compte, R40)
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['household_id', 'user_id']);
        });

        Schema::create('person_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('household_people')->cascadeOnDelete();
            $table->string('type', 10);                                                        // App\Enums\RestrictionType
            $table->foreignId('ingredient_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('tag_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('note', 150)->nullable();
            $table->timestamps();

            $table->unique(['person_id', 'type', 'ingredient_id', 'tag_id']);
        });

        Schema::table('meal_occasions', function (Blueprint $table) {
            $table->json('absent_person_ids')->nullable()->after('absent_user_ids');
        });

        Schema::table('planned_meals', function (Blueprint $table) {
            $table->foreignId('for_person_id')->nullable()->after('servings')->constrained('household_people')->nullOnDelete();
        });

        Schema::table('stay_participants', function (Blueprint $table) {
            $table->foreignId('person_id')->nullable()->after('user_id')->constrained('household_people')->nullOnDelete();
        });

        Schema::create('canteen_meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('household_people')->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 8)->default('canteen');                 // canteen · home (vacances, malade, sortie)
            $table->string('label', 200)->nullable();                        // « Poisson pané, purée, yaourt »
            $table->json('families')->nullable();                            // familles de WeekBalance, d'après le libellé
            $table->string('source', 8)->default('manual');                  // manual · paste · photo
            $table->timestamps();

            $table->unique(['person_id', 'date']);
            $table->index(['household_id', 'date']);
        });

        Schema::create('child_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('household_people')->nullOnDelete();   // qui choisit (vide : les enfants)
            $table->date('date');
            $table->foreignId('meal_slot_id')->constrained()->cascadeOnDelete();
            $table->json('recipe_ids');                                       // trois recettes proposées par un adulte
            $table->boolean('allow_plan')->default(false);                   // le choix s'inscrit au planning (R42)
            $table->foreignId('chosen_recipe_id')->nullable()->constrained('recipes')->nullOnDelete();
            $table->timestamp('chosen_at')->nullable();
            $table->foreignId('wish_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('planned_meal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['household_id', 'date']);
        });

        Schema::table('recipes', function (Blueprint $table) {
            $table->boolean('kid_friendly')->default(false);
        });

        Schema::table('recipe_steps', function (Blueprint $table) {
            $table->boolean('adult_help')->nullable();                       // vide : d'après les mots de l'étape
        });

        $this->moveData();

        Schema::dropIfExists('household_restrictions');

        Schema::table('planned_meals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('for_user_id');
        });
    }

    /** Reprend les personnes à table (lot 32), les contraintes (lot 15) et les gamelles (lot 32). */
    private function moveData(): void
    {
        $now = now();
        $households = DB::table('households')->pluck('id');

        foreach ($households as $householdId) {
            $people = [];   // user_id => person_id
            $count = 0;

            // 1. Les personnes à table d'habitude, telles que réglées.
            $setting = DB::table('settings')->where('household_id', $householdId)->where('key', 'table.people')->value('value');
            $rows = array_values(array_filter((array) json_decode((string) $setting, true), fn ($row) => is_array($row) && trim((string) ($row['name'] ?? '')) !== ''));

            // 2. Pas réglé, mais une contrainte ou une gamelle à reprendre : la table par défaut (comptes du foyer, puis « Personne N »).
            $needed = DB::table('household_restrictions')->where('household_id', $householdId)->pluck('user_id')
                ->merge(DB::table('planned_meals')->where('household_id', $householdId)->whereNotNull('for_user_id')->pluck('for_user_id'))
                ->map(fn ($id) => (int) $id)->unique()->values()->all();

            if ($rows === [] && $needed !== []) {
                $size = max(1, (int) (json_decode((string) DB::table('settings')->where('household_id', $householdId)->where('key', 'household_size')->value('value')) ?? config('bouffe.household_size', 2)));
                $members = DB::table('users')->whereIn('id', DB::table('household_user')->where('household_id', $householdId)->select('user_id'))
                    ->orderBy('id')->limit($size)->get(['id', 'name']);

                foreach ($members as $member) {
                    $rows[] = ['name' => $member->name, 'appetite' => 'normal', 'user_id' => $member->id];
                }

                for ($i = count($rows); $i < $size; $i++) {
                    $rows[] = ['name' => 'Personne '.($i + 1), 'appetite' => 'normal', 'user_id' => null];
                }
            }

            foreach ($rows as $row) {
                $userId = isset($row['user_id']) && $row['user_id'] !== null && $row['user_id'] !== '' ? (int) $row['user_id'] : null;

                if ($userId !== null && (isset($people[$userId]) || ! DB::table('users')->where('id', $userId)->exists())) {
                    $userId = null;
                }

                $id = DB::table('household_people')->insertGetId([
                    'household_id' => $householdId,
                    'user_id' => $userId,
                    'name' => mb_substr(trim((string) $row['name']), 0, 60),
                    'appetite' => in_array($row['appetite'] ?? null, ['petit', 'moyen', 'normal', 'grand'], true) ? $row['appetite'] : 'normal',
                    'at_table' => true,
                    'position' => $count++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($userId !== null) {
                    $people[$userId] = $id;
                }
            }

            // 3. Un compte qui a une contrainte ou une gamelle sans être à table d'habitude : une personne « pas à table ».
            foreach ($needed as $userId) {
                if (isset($people[$userId]) || ! ($name = DB::table('users')->where('id', $userId)->value('name'))) {
                    continue;
                }

                $people[$userId] = DB::table('household_people')->insertGetId([
                    'household_id' => $householdId, 'user_id' => $userId, 'name' => mb_substr((string) $name, 0, 60), 'appetite' => 'normal',
                    'at_table' => false, 'position' => $count++, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            foreach (DB::table('household_restrictions')->where('household_id', $householdId)->orderBy('id')->get() as $restriction) {
                if (! isset($people[(int) $restriction->user_id])) {
                    continue;
                }

                DB::table('person_restrictions')->insert([
                    'household_id' => $householdId,
                    'person_id' => $people[(int) $restriction->user_id],
                    'type' => $restriction->type,
                    'ingredient_id' => $restriction->ingredient_id,
                    'tag_id' => $restriction->tag_id,
                    'note' => $restriction->note,
                    'created_at' => $restriction->created_at,
                    'updated_at' => $restriction->updated_at,
                ]);
            }

            foreach ($people as $userId => $personId) {
                DB::table('planned_meals')->where('household_id', $householdId)->where('for_user_id', $userId)->update(['for_person_id' => $personId]);
                DB::table('stay_participants')->where('user_id', $userId)
                    ->whereIn('stay_id', DB::table('stays')->where('household_id', $householdId)->select('id'))
                    ->update(['person_id' => $personId]);
            }

            DB::table('settings')->where('household_id', $householdId)->where('key', 'table.people')->delete();
        }
    }

    public function down(): void
    {
        Schema::create('household_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);
            $table->index('household_id');   // comme au lot 24 (son retour en arrière l'attend)
            $table->foreignId('ingredient_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('tag_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('note', 150)->nullable();
            $table->timestamps();

            $table->unique(['household_id', 'user_id', 'type', 'ingredient_id', 'tag_id'], 'household_restrictions_household_unique');
            $table->index('user_id', 'household_restrictions_user_index');
        });

        Schema::table('planned_meals', function (Blueprint $table) {
            $table->foreignId('for_user_id')->nullable()->after('servings')->constrained('users')->nullOnDelete();
        });

        // Le réglage « table.people » et les contraintes des comptes reviennent ; celles des personnes sans compte sont perdues.
        foreach (DB::table('household_people')->orderBy('position')->orderBy('id')->get()->groupBy('household_id') as $householdId => $people) {
            $table = $people->where('at_table', true)->map(fn ($p) => ['name' => $p->name, 'appetite' => $p->appetite, 'user_id' => $p->user_id])->values()->all();
            DB::table('settings')->updateOrInsert(['household_id' => $householdId, 'key' => 'table.people'], ['value' => json_encode($table), 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach (DB::table('person_restrictions')->join('household_people', 'household_people.id', '=', 'person_restrictions.person_id')
            ->whereNotNull('household_people.user_id')->get(['person_restrictions.*', 'household_people.user_id']) as $row) {
            DB::table('household_restrictions')->insert([
                'household_id' => $row->household_id, 'user_id' => $row->user_id, 'type' => $row->type, 'ingredient_id' => $row->ingredient_id,
                'tag_id' => $row->tag_id, 'note' => $row->note, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
            ]);
        }

        foreach (DB::table('household_people')->whereNotNull('user_id')->get() as $person) {
            DB::table('planned_meals')->where('for_person_id', $person->id)->update(['for_user_id' => $person->user_id]);
        }

        Schema::table('recipe_steps', fn (Blueprint $table) => $table->dropColumn('adult_help'));
        Schema::table('recipes', fn (Blueprint $table) => $table->dropColumn('kid_friendly'));
        Schema::dropIfExists('child_choices');
        Schema::dropIfExists('canteen_meals');
        Schema::table('stay_participants', fn (Blueprint $table) => $table->dropConstrainedForeignId('person_id'));
        Schema::table('planned_meals', fn (Blueprint $table) => $table->dropConstrainedForeignId('for_person_id'));
        Schema::table('meal_occasions', fn (Blueprint $table) => $table->dropColumn('absent_person_ids'));
        Schema::dropIfExists('person_restrictions');
        Schema::dropIfExists('household_people');
    }
};
