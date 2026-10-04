<?php

namespace App\Services\Planning;

use App\Models\User;
use App\Support\Settings;
use InvalidArgumentException;

/**
 * Appétit de chacun (lot 32 — 32.1, règle R33).
 *
 * Chaque personne à table compte pour une **part** : petit 0,5 · moyen 0,75 · normal 1 · grand 1,5
 * (valeurs par défaut, réglables dans Paramètres › Foyer). Les portions d'un repas sont la somme des
 * parts des présents, arrondie à la demi-portion supérieure.
 *
 * Les personnes à table d'habitude sont les personnes du foyer (lot 39, `household_people`) : les
 * comptes, et d'autres personnes sans compte (un enfant). Tant qu'aucune n'a été enregistrée, le
 * foyer compte « Personnes à table » portions normales, comme avant.
 */
class Appetites
{
    /** niveau => [libellé, précision, part par défaut] */
    public const LEVELS = [
        'petit' => ['Petit', 'enfant jusqu\'à 6 ans', 0.5],
        'moyen' => ['Moyen', '6 à 12 ans', 0.75],
        'normal' => ['Normal', 'adulte', 1.0],
        'grand' => ['Grand', 'gros appétit', 1.5],
    ];

    public const MAX_PEOPLE = 20;

    /** @return array<string, float> parts de chaque niveau */
    public function parts(): array
    {
        $stored = (array) Settings::get('appetite.parts', []);
        $parts = [];

        foreach (self::LEVELS as $level => [, , $default]) {
            // « Petit » reprend l'ancien réglage des enfants (BOUFFE_CHILD_PORTION) tant qu'il n'a pas été changé.
            $fallback = $level === 'petit' ? (float) config('bouffe.child_portion', $default) : $default;
            $value = isset($stored[$level]) ? (float) $stored[$level] : $fallback;
            $parts[$level] = max(0.25, min(3.0, $value));
        }

        return $parts;
    }

    public function part(?string $level): float
    {
        return $this->parts()[$this->level($level)];
    }

    public function level(?string $level): string
    {
        return array_key_exists((string) $level, self::LEVELS) ? (string) $level : 'normal';
    }

    public function levelLabel(?string $level): string
    {
        return self::LEVELS[$this->level($level)][0];
    }

    /** @param  array<string, float|string>  $parts */
    public function saveParts(array $parts): void
    {
        $clean = [];

        foreach (self::LEVELS as $level => $definition) {
            $value = (float) str_replace(',', '.', (string) ($parts[$level] ?? $definition[2]));

            if ($value < 0.25 || $value > 3) {
                throw new InvalidArgumentException('Une part va de 0,25 à 3.');
            }

            $clean[$level] = round($value, 2);
        }

        Settings::set('appetite.parts', $clean);
    }

    /* ================================================================ Personnes à table */

    /** Le foyer a-t-il enregistré ses personnes (lot 39, `household_people`) ? */
    public function isConfigured(): bool
    {
        return \App\Models\HouseholdPerson::query()->exists();
    }

    /** @return list<array{id: int|null, name: string, appetite: string, user_id: int|null}> */
    private function storedTable(): array
    {
        return \App\Models\HouseholdPerson::query()->atTable()->ordered()->get()
            ->map(fn (\App\Models\HouseholdPerson $person) => [
                'id' => $person->id,
                'name' => $person->name,
                'appetite' => $this->level($person->appetite),
                'user_id' => $person->user_id,
            ])->all();
    }

    /**
     * Les personnes à table d'habitude.
     *
     * @return list<array{id: int|null, name: string, appetite: string, user_id: int|null}>
     */
    public function table(): array
    {
        if ($this->isConfigured()) {
            return $this->storedTable();
        }

        // Pas encore réglé : les comptes du foyer, puis des personnes sans nom, jusqu'au nombre habituel.
        $size = max(1, Settings::int('household_size', (int) config('bouffe.household_size', 2)));
        $rows = User::query()->inHousehold()->orderBy('id')->limit($size)->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => null, 'name' => $user->name, 'appetite' => 'normal', 'user_id' => $user->id])
            ->all();

        for ($i = count($rows); $i < $size; $i++) {
            $rows[] = ['id' => null, 'name' => 'Personne '.($i + 1), 'appetite' => 'normal', 'user_id' => null];
        }

        return $rows;
    }

    /**
     * Remplace les personnes à table (lot 32) : celles qui ne sont plus dans la liste quittent la
     * table (leurs goûts sont gardés), les autres sont mises à jour ou ajoutées.
     *
     * @param  list<array{id?: int|string|null, name?: string, appetite?: string, user_id?: int|string|null}>  $rows
     */
    public function saveTable(array $rows): void
    {
        $members = User::query()->inHousehold()->pluck('id')->all();
        $clean = [];
        $seen = [];

        foreach ($rows as $row) {
            $userId = isset($row['user_id']) && $row['user_id'] !== '' && $row['user_id'] !== null ? (int) $row['user_id'] : null;
            $name = mb_substr(trim((string) ($row['name'] ?? '')), 0, 60);

            if ($userId !== null && (! in_array($userId, $members, true) || isset($seen[$userId]))) {
                $userId = null;
            }

            if ($userId !== null) {
                $seen[$userId] = true;
                $name = $name !== '' ? $name : (string) User::find($userId)?->name;
            }

            if ($name === '') {
                throw new InvalidArgumentException('Chaque personne à table a un prénom.');
            }

            $clean[] = ['id' => isset($row['id']) && $row['id'] ? (int) $row['id'] : null, 'name' => $name, 'appetite' => $this->level($row['appetite'] ?? null), 'user_id' => $userId];
        }

        if ($clean === [] || count($clean) > self::MAX_PEOPLE) {
            throw new InvalidArgumentException('Il faut entre 1 et '.self::MAX_PEOPLE.' personnes à table.');
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($clean) {
            $existing = \App\Models\HouseholdPerson::query()->get();
            $kept = [];

            foreach ($clean as $position => $row) {
                $person = ($row['id'] ? $existing->firstWhere('id', $row['id']) : null)
                    ?? ($row['user_id'] !== null ? $existing->firstWhere('user_id', $row['user_id']) : null)
                    ?? $existing->first(fn ($p) => $p->user_id === null && $p->name === $row['name'] && ! in_array($p->id, $kept, true));

                // Un compte déjà rattaché à une autre personne s'en détache.
                if ($row['user_id'] !== null) {
                    \App\Models\HouseholdPerson::query()->where('user_id', $row['user_id'])->when($person, fn ($q) => $q->whereKeyNot($person->id))->update(['user_id' => null]);
                }

                $values = ['name' => $row['name'], 'appetite' => $row['appetite'], 'user_id' => $row['user_id'], 'at_table' => true, 'position' => $position];
                $person ? $person->update($values) : $person = \App\Models\HouseholdPerson::create($values);
                $kept[] = $person->id;
            }

            \App\Models\HouseholdPerson::query()->whereNotIn('id', $kept)->update(['at_table' => false]);
        });

        Settings::set('household_size', count($clean));
    }

    /* ================================================================ Portions */

    /**
     * Portions des personnes du foyer présentes.
     *
     * @param  list<int>  $absentUserIds  comptes absents
     * @param  list<int>  $absentPersonIds  personnes absentes (sans compte, ou à la cantine)
     */
    public function householdPortions(array $absentUserIds = [], array $absentPersonIds = []): float
    {
        if (! $this->isConfigured()) {
            $size = max(1, Settings::int('household_size', (int) config('bouffe.household_size', 2)));

            return (float) max(0, $size - count($absentUserIds));
        }

        return array_sum(array_map(
            fn (array $row) => $this->isAbsent($row, $absentUserIds, $absentPersonIds) ? 0.0 : $this->part($row['appetite']),
            $this->table(),
        ));
    }

    /**
     * Portions d'une personne du foyer (sa gamelle, 32.3) : sa part arrondie à la demi-portion
     * supérieure, une portion « normale » si elle n'est pas connue.
     */
    public function portionFor(?int $personId): float
    {
        $person = $personId !== null ? \App\Models\HouseholdPerson::query()->find($personId) : null;

        return $person ? max(0.5, self::roundUp($this->part($person->appetite))) : 1.0;
    }

    /** Personnes du foyer présentes. @param  list<int>  $absentUserIds  @param  list<int>  $absentPersonIds */
    public function householdPeople(array $absentUserIds = [], array $absentPersonIds = []): int
    {
        if (! $this->isConfigured()) {
            $size = max(1, Settings::int('household_size', (int) config('bouffe.household_size', 2)));

            return max(0, $size - count($absentUserIds));
        }

        return count(array_filter($this->table(), fn (array $row) => ! $this->isAbsent($row, $absentUserIds, $absentPersonIds)));
    }

    /** @param  array{id: int|null, user_id: int|null}  $row */
    private function isAbsent(array $row, array $absentUserIds, array $absentPersonIds): bool
    {
        return ($row['user_id'] !== null && in_array($row['user_id'], $absentUserIds, true))
            || ($row['id'] !== null && in_array($row['id'], $absentPersonIds, true));
    }

    /** R33 : arrondi à la demi-portion supérieure (3,2 → 3,5 ; 3,5 → 3,5). */
    public static function roundUp(float $portions): float
    {
        return ceil(round($portions * 2, 4)) / 2;
    }

    /** Demi-portion la plus proche, entre 0,5 et 50 (saisie à la main). */
    public static function clamp(float|int|string|null $servings): float
    {
        $value = (float) str_replace(',', '.', (string) $servings);

        return max(0.5, min(50.0, round($value * 2) / 2));
    }

    /** « 3,5 » ou « 4 ». */
    public static function format(float|int|string|null $portions): string
    {
        $value = round((float) $portions * 2) / 2;

        return floor($value) == $value ? (string) (int) $value : number_format($value, 1, ',', '');
    }

    /** Une part : « 0,75 », « 1 », « 1,5 » (au centième, sans zéros inutiles). */
    public static function formatPart(float|int|string|null $part): string
    {
        return rtrim(rtrim(number_format((float) $part, 2, ',', ''), '0'), ',');
    }

    /** « 3,5 portions », « 1 portion » (au pluriel à partir de 2, comme en français). */
    public static function label(float|int|string|null $portions, string $word = 'portion'): string
    {
        return self::format($portions).' '.$word.((float) $portions >= 2 ? 's' : '');
    }
}
