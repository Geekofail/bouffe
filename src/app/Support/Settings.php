<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Réglages de l'application enregistrés en base (Paramètres), avec repli sur config/bouffe.php (.env).
 *
 *   Settings::get('stock.dlc_soon_days')   → valeur enregistrée, sinon config('bouffe.stock.dlc_soon_days')
 */
final class Settings
{
    /** clé => [type, min, max] */
    public const KEYS = [
        'stock.dlc_soon_days' => ['int', 1, 14],
        'stock.ddm_soon_days' => ['int', 1, 60],
        'stock.nav_badge' => ['bool', null, null],
        'stock.planning_banner' => ['bool', null, null],
        'stock.deduction_mode' => ['enum', ['ask', 'auto', 'never'], null],
        'stock.auto_close_days' => ['int', 0, 7],                   // clôture des repas passés : 0 = demander (lot 21, R23)
        'stock.cook_mode_live' => ['bool', null, null],             // retrait au fil du mode cuisine (22.6)
        'ingredients.ignored_duplicates' => ['list', null, null],   // paires « 12-34 » écartées (lot 11)
        'planning.rules' => ['array', null, null],                  // règles de la semaine (lot 14, voir PlanningRules)
        'planning.reminder_hour' => ['int', 6, 22],                 // heure des rappels de la veille (lot 14)
        'budget.monthly' => ['float', 0, 100000],                   // lot 17 — remplacé au lot 22 par les postes (budget_amounts)
        'budget.period_start_day' => ['int', 1, 28],                // début de la période budgétaire (lot 22, 23.7)
        'budget.track_payer' => ['bool', null, null],               // « qui a payé » (23.8)
        'shopping.max_cost_per_serving' => ['float', 0, 1000],      // filtre « moins de N € par portion » (lot 17)
        'push.vapid' => ['array', null, null],                      // clés VAPID des notifications (lot 20, générées une fois)
        'notifications.daily_hour' => ['int', 6, 22],               // message quotidien « à consommer » (lot 20)
        'notifications.recap_enabled' => ['bool', null, null],      // récapitulatif par e-mail (19.3)
        'notifications.recap_day' => ['int', 0, 6],
        'notifications.recap_hour' => ['int', 6, 22],
        'notifications.last_run' => ['array', null, null],          // dernier passage de bouffe:reminders (19.4)
        'receipts.provider' => ['enum', ['mistral', 'azure', 'none'], null],   // lecture des tickets (lot 23, Q32)
        'receipts.mistral_key' => ['secret', null, null],           // clés chiffrées avec APP_KEY, jamais réaffichées
        'receipts.azure_endpoint' => ['string', null, 200],
        'receipts.azure_key' => ['secret', null, null],
        'receipts.monthly_cap' => ['int', 0, 1000],                 // 24.7
        'receipts.keep_months' => ['int', 0, 60],                   // 24.8 (Q33)
        'assistant.enabled' => ['bool', null, null],                // assistant culinaire (lot 33)
        'assistant.monthly_cap' => ['float', 0, 100],               // plafond mensuel en euros (33.5, Q46)
        'household_size' => ['int', 1, 20],                         // personnes qui mangent d'habitude (par foyer, lot 24)
        'kitchen.equipment' => ['list', null, null],                // équipement de la cuisine (lot 40, 40.3) ; absent = tout
        'substitutions.hidden' => ['list', null, null],             // remplacements communs masqués par le foyer (lot 40)
        'table.people' => ['array', null, null],                    // lot 32 — remplacé au lot 39 par household_people (repris puis effacé)
        'appetite.parts' => ['array', null, null],                  // part de chaque appétit (lot 32, R33, Q47)
        'tasks.token' => ['secret', null, null],                    // jeton de l'adresse /taches/{jeton} (lot 25, 27.3)
        'tasks.last_run' => ['array', null, null],                  // dernier passage des tâches : date, origine, durée
        'backups.last_email' => ['array', null, null],              // dernier e-mail « sauvegarde de la semaine » (27.9)
    ];

    /** Réglages communs à toute l'installation ; les autres sont propres à chaque foyer (lot 24). */
    public const INSTALLATION = ['push.vapid', 'notifications.last_run', 'receipts.provider', 'receipts.mistral_key', 'receipts.azure_endpoint', 'receipts.azure_key', 'receipts.monthly_cap', 'receipts.keep_months', 'assistant.enabled', 'assistant.monthly_cap',
        'tasks.token', 'tasks.last_run', 'backups.last_email'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $values = self::load();

        if (array_key_exists($key, $values)) {
            return $values[$key];
        }

        return config('bouffe.'.$key, $default);
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function bool(string $key, bool $default = true): bool
    {
        return (bool) self::get($key, $default);
    }

    public static function float(string $key, float $default = 0.0): float
    {
        return (float) self::get($key, $default);
    }

    public static function set(string $key, mixed $value): void
    {
        [$type, $min, $max] = self::KEYS[$key] ?? throw new \InvalidArgumentException("Réglage inconnu : {$key}");

        $value = match ($type) {
            'int' => max($min, min($max, (int) $value)),
            'bool' => (bool) $value,
            'enum' => in_array($value, $min, true) ? $value : $min[0],
            'list' => array_values(array_unique(array_map('strval', (array) $value))),
            'array' => (array) $value,   // structure validée par le service qui l'utilise
            'float' => max((float) $min, min((float) $max, round((float) $value, 2))),
            'string' => ($value = trim((string) $value)) === '' ? null : mb_substr($value, 0, $max ?? 255),
            'secret' => ($value = trim((string) $value)) === '' ? null : Crypt::encryptString($value),
        };

        // Vide : on retire la ligne, la valeur de config/bouffe.php (.env) reprend la main.
        $scope = ['household_id' => self::householdFor($key), 'key' => $key];

        if ($value === null && in_array($type, ['string', 'secret'], true)) {
            DB::table('settings')->where($scope)->delete();
            self::flush();

            return;
        }

        DB::table('settings')->updateOrInsert($scope, ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]);
        self::flush();
    }

    /**
     * Valeur secrète (clé d'API) : déchiffrée depuis la base, sinon celle de .env.
     * Une valeur illisible (sauvegarde restaurée avec une autre APP_KEY) compte comme absente.
     */
    public static function secret(string $key): ?string
    {
        $stored = self::load()[$key] ?? null;

        if (is_string($stored) && $stored !== '') {
            try {
                return Crypt::decryptString($stored);
            } catch (DecryptException) {
                return null;
            }
        }

        $fallback = config('bouffe.'.$key);

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    /** La clé vient-elle de Paramètres (et non de .env) ? */
    public static function stored(string $key): bool
    {
        return array_key_exists($key, self::load());
    }

    /** Foyer d'une clé : NULL pour un réglage de l'installation. */
    private static function householdFor(string $key): ?int
    {
        return in_array($key, self::INSTALLATION, true) ? null : CurrentHousehold::id();
    }

    /** Oublie les valeurs lues (nouvelle requête, changement de foyer, tests). */
    public static function flush(): void
    {
        app()->forgetInstance('bouffe.settings');
    }

    /** @return array<string, mixed> */
    private static function load(): array
    {
        // Mémorisé le temps de la requête, par foyer (dans le conteneur : jamais partagé entre deux requêtes ou tests).
        $household = CurrentHousehold::id();
        $cache = app()->bound('bouffe.settings') ? app('bouffe.settings') : [];

        if (array_key_exists((int) $household, $cache)) {
            return $cache[(int) $household];
        }

        try {
            $values = [];
            $rows = DB::table('settings')
                ->where(fn ($q) => $q->whereNull('household_id')->when($household, fn ($w) => $w->orWhere('household_id', $household)))
                ->get(['household_id', 'key', 'value']);

            foreach ($rows as $row) {
                // Une clé de l'installation se lit sur la ligne commune, une clé de foyer sur celle du foyer.
                if (($row->household_id === null) === in_array($row->key, self::INSTALLATION, true)) {
                    $values[$row->key] = json_decode((string) $row->value, true);
                }
            }
        } catch (\Throwable) {
            $values = [];   // table absente (migration pas encore lancée) : valeurs de config
        }

        $cache[(int) $household] = $values;
        app()->instance('bouffe.settings', $cache);

        return $values;
    }
}
