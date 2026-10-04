<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 24 — plusieurs foyers : fondations (module 25 du document 07, règles R29 et R30).
 *
 *  - `households`, `household_user` (rôle par foyer), `invitations` ;
 *  - `users.current_household_id` (foyer actif), `users.is_admin` (administrateur de l'installation) ;
 *  - `household_id` sur chaque table propre à un foyer, rempli avec le foyer n° 1 ;
 *  - `household_ingredient_settings` : réglages d'un ingrédient du catalogue commun propres à un foyer ;
 *  - `settings` : clé par foyer (household_id NULL = réglage de l'installation).
 *
 * Les données existantes deviennent le foyer n° 1, sans perte ; le premier compte en est
 * responsable et administre l'installation.
 */
return new class extends Migration
{
    /** Tables propres à un foyer (les tables « enfants » suivent leur parent : lignes de recette…). */
    public const TABLES = [
        'recipes', 'tags', 'meal_slots', 'planned_meals', 'meal_occasions', 'guests', 'wishes', 'reminders',
        'week_templates', 'storage_locations', 'stock_items', 'stock_movements', 'stock_usage_rules',
        'shopping_lists', 'shopping_list_items', 'standing_items', 'recurring_items', 'offline_operations',
        'stores', 'ingredient_prices', 'budget_categories', 'expenses', 'recurring_expenses',
        'receipts', 'receipt_label_mappings', 'ocr_readings',
        'household_restrictions',   // contraintes d'un membre, propres à chaque foyer (R29 ; partage au lot 26)
    ];

    /** Réglages de l'installation, communs à tous les foyers. */
    public const INSTALLATION_SETTINGS = ['push.vapid', 'notifications.last_run', 'receipts.provider', 'receipts.mistral_key', 'receipts.azure_endpoint', 'receipts.azure_key', 'receipts.monthly_cap', 'receipts.keep_months'];

    /** Unicités qui deviennent « par foyer » : table => [ancien index, colonnes]. */
    private const UNIQUES = [
        ['tags', 'tags_name_unique', ['name']],
        ['tags', 'tags_slug_unique', ['slug']],
        ['meal_slots', 'meal_slots_name_unique', ['name']],
        ['recipes', 'recipes_title_unique', ['title']],
        ['recipes', 'recipes_slug_unique', ['slug']],
        ['storage_locations', 'storage_locations_name_unique', ['name']],
        ['week_templates', 'week_templates_name_unique', ['name']],
        ['stores', 'stores_name_unique', ['name']],
        ['stock_usage_rules', 'stock_usage_rules_ingredient_id_unique', ['ingredient_id']],
        ['household_restrictions', 'household_restrictions_user_id_type_ingredient_id_tag_id_unique', ['user_id', 'type', 'ingredient_id', 'tag_id']],
    ];

    /** MariaDB : une clé étrangère doit garder un index qui commence par sa colonne. table => [colonne, index d'appoint] */
    private const FK_HELPERS = [
        'stock_usage_rules' => ['ingredient_id', 'stock_usage_rules_ingredient_index'],
        'household_restrictions' => ['user_id', 'household_restrictions_user_index'],
    ];

    /** Nom de l'index unique « par foyer » (64 caractères au plus pour MySQL). */
    private static function uniqueName(string $table, array $columns): string
    {
        $name = $table.'_household_'.implode('_', $columns).'_unique';

        return strlen($name) <= 64 ? $name : $table.'_household_unique';
    }

    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disabled_at')->nullable();              // désactivé par l'administrateur (25.7)
            $table->timestamp('deletion_requested_at')->nullable();    // suppression dans 30 jours (25.8)
            $table->timestamps();
        });

        Schema::create('household_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10)->default('full');               // owner · full · viewer (App\Enums\UserRole)
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->unique(['household_id', 'user_id']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('email', 191)->nullable();
            $table->char('token_hash', 64)->unique();                 // seul le condensé est gardé
            $table->string('role', 10)->default('full');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_household_id')->nullable()->constrained('households')->nullOnDelete();
            $table->boolean('is_admin')->default(false);
        });

        // --- Foyer n° 1 : les données actuelles
        $now = now();
        $names = DB::table('users')->orderBy('id')->limit(2)->pluck('name')->all();
        $householdId = DB::table('households')->insertGetId([
            'name' => $names ? implode(' et ', $names) : 'Mon foyer',
            'created_by' => DB::table('users')->orderBy('id')->value('id'),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach (DB::table('users')->orderBy('id')->get(['id', 'role']) as $i => $user) {
            DB::table('household_user')->insert([
                'household_id' => $householdId, 'user_id' => $user->id,
                'role' => ($user->role ?? 'full') === 'viewer' ? 'viewer' : 'owner',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('users')->where('id', $user->id)->update(['current_household_id' => $householdId, 'is_admin' => $i === 0]);
        }

        // --- household_id partout
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('household_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
                $table->index('household_id');
            });

            DB::table($name)->update(['household_id' => $householdId]);
        }

        foreach (self::UNIQUES as [$name, $index, $columns]) {
            if (isset(self::FK_HELPERS[$name])) {
                Schema::table($name, fn (Blueprint $table) => $table->index(self::FK_HELPERS[$name][0], self::FK_HELPERS[$name][1]));
            }

            Schema::table($name, function (Blueprint $table) use ($index, $columns, $name) {
                $table->dropUnique($index);
                $table->unique(['household_id', ...$columns], self::uniqueName($name, $columns));
            });
        }

        // --- Réglages : une clé par foyer, NULL pour l'installation
        Schema::create('settings_households', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['household_id', 'key']);
        });

        foreach (DB::table('settings')->get() as $row) {
            DB::table('settings_households')->insert([
                'household_id' => in_array($row->key, self::INSTALLATION_SETTINGS, true) ? null : $householdId,
                'key' => $row->key, 'value' => $row->value,
                'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
            ]);
        }

        Schema::drop('settings');
        Schema::rename('settings_households', 'settings');

        // --- R30 : réglages d'un ingrédient propres à un foyer (instantané complet dès la 1re modification)
        Schema::create('household_ingredient_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_staple')->default(false);
            $table->foreignId('aisle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stock_mode', 10)->default('quantity');
            $table->foreignId('storage_location_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('shelf_life_days')->nullable();
            $table->string('shelf_life_type', 4)->default('none');
            $table->unsignedSmallInteger('days_after_opening')->nullable();
            $table->unsignedTinyInteger('freezer_months')->nullable();
            $table->decimal('min_stock_quantity', 12, 3)->nullable();
            $table->foreignId('min_stock_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->decimal('reference_price', 12, 6)->nullable();
            $table->foreignId('reference_price_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->boolean('reference_price_locked')->default(false);
            $table->date('reference_price_on')->nullable();
            $table->timestamps();

            $table->unique(['household_id', 'ingredient_id'], 'household_ingredient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('household_ingredient_settings');

        Schema::create('settings_single', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        $first = DB::table('households')->orderBy('id')->value('id');

        foreach (DB::table('settings')->where(fn ($q) => $q->whereNull('household_id')->orWhere('household_id', $first))->get() as $row) {
            DB::table('settings_single')->insertOrIgnore(['key' => $row->key, 'value' => $row->value, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at]);
        }

        Schema::drop('settings');
        Schema::rename('settings_single', 'settings');

        foreach (array_reverse(self::UNIQUES) as [$name, $index, $columns]) {
            Schema::table($name, function (Blueprint $table) use ($index, $columns, $name) {
                $table->unique($columns, $index);
                $table->dropUnique(self::uniqueName($name, $columns));
            });

            if (isset(self::FK_HELPERS[$name])) {
                Schema::table($name, fn (Blueprint $table) => $table->dropIndex(self::FK_HELPERS[$name][1]));
            }
        }

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['household_id']);
                $table->dropIndex(['household_id']);
                $table->dropColumn('household_id');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_household_id');
            $table->dropColumn('is_admin');
        });

        Schema::dropIfExists('invitations');
        Schema::dropIfExists('household_user');
        Schema::dropIfExists('households');
    }
};
