<?php

namespace App\Services\People;

use App\Models\HouseholdPerson;
use App\Models\User;
use App\Services\Planning\Appetites;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Les personnes du foyer (lot 39, 39.1, R40) : compte ou pas, chacune a un prénom, un appétit, une
 * couleur, des goûts et, si elle y mange, une cantine.
 *
 * Tant que personne n'a été enregistré, le foyer garde sa table par défaut (lot 32 : les comptes,
 * puis « Personne 2 »… jusqu'au nombre habituel). Le premier geste qui a besoin d'une personne
 * (un goût, une gamelle, la page Foyer) enregistre cette table telle quelle : les portions ne
 * changent pas.
 */
class HouseholdPeople
{
    public const MAX_PEOPLE = Appetites::MAX_PEOPLE;

    public function configured(): bool
    {
        return HouseholdPerson::query()->exists();
    }

    /** @return Collection<int, HouseholdPerson> toutes les personnes, dans l'ordre */
    public function all(): Collection
    {
        return HouseholdPerson::query()->ordered()->with('user:id,name,share_restrictions')->get();
    }

    /** @return Collection<int, HouseholdPerson> à table d'habitude */
    public function atTable(): Collection
    {
        return $this->all()->where('at_table', true)->values();
    }

    /**
     * Enregistre la table par défaut si le foyer n'a encore personne.
     *
     * @return Collection<int, HouseholdPerson>
     */
    public function ensure(): Collection
    {
        if ($this->configured()) {
            return $this->all();
        }

        DB::transaction(function () {
            foreach (app(Appetites::class)->table() as $position => $row) {
                HouseholdPerson::create([
                    'user_id' => $row['user_id'],
                    'name' => $row['name'],
                    'appetite' => $row['appetite'],
                    'position' => $position,
                ]);
            }
        });

        return $this->all();
    }

    /**
     * La personne d'un compte du foyer : créée « pas à table » si elle n'existe pas encore (un compte
     * qui n'y mange pas d'habitude peut quand même avoir une allergie).
     */
    public function forUser(User $user): HouseholdPerson
    {
        $this->ensure();

        if (! User::query()->inHousehold()->whereKey($user->id)->exists()) {
            throw new InvalidArgumentException('Cette personne ne fait pas partie du foyer.');
        }

        return HouseholdPerson::query()->where('user_id', $user->id)->first()
            ?? HouseholdPerson::create(['user_id' => $user->id, 'name' => mb_substr($user->name, 0, 60), 'at_table' => false, 'position' => $this->nextPosition()]);
    }

    public function find(int $id): HouseholdPerson
    {
        return HouseholdPerson::query()->findOrFail($id);
    }

    /**
     * Ajoute ou modifie une personne.
     *
     * @param  array{name?: string, appetite?: string, color?: string|null, user_id?: int|string|null, at_table?: bool, canteen_days?: list<int|string>, canteen_name?: string|null, share_tastes?: bool}  $data
     */
    public function save(?HouseholdPerson $person, array $data): HouseholdPerson
    {
        $this->ensure();

        $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 60);
        $userId = isset($data['user_id']) && $data['user_id'] !== '' && $data['user_id'] !== null ? (int) $data['user_id'] : null;

        if ($userId !== null) {
            if (! User::query()->inHousehold()->whereKey($userId)->exists()) {
                throw new InvalidArgumentException('Ce compte ne fait pas partie du foyer.');
            }

            if (HouseholdPerson::query()->where('user_id', $userId)->when($person, fn ($q) => $q->whereKeyNot($person->id))->exists()) {
                throw new InvalidArgumentException('Ce compte est déjà rattaché à une autre personne.');
            }

            $name = $name !== '' ? $name : mb_substr((string) User::find($userId)?->name, 0, 60);
        }

        if ($name === '') {
            throw new InvalidArgumentException('Chaque personne a un prénom.');
        }

        $days = array_values(array_unique(array_filter(
            array_map('intval', (array) ($data['canteen_days'] ?? [])),
            fn (int $day) => isset(HouseholdPerson::SCHOOL_DAYS[$day]),
        )));
        sort($days);

        $atTable = (bool) ($data['at_table'] ?? true);

        if (! $person && HouseholdPerson::query()->count() >= self::MAX_PEOPLE) {
            throw new InvalidArgumentException('Pas plus de '.self::MAX_PEOPLE.' personnes dans un foyer.');
        }

        if ($person && $person->at_table && ! $atTable && HouseholdPerson::query()->atTable()->whereKeyNot($person->id)->doesntExist()) {
            throw new InvalidArgumentException('Il faut au moins une personne à table.');
        }

        $values = [
            'name' => $name,
            'user_id' => $userId,
            'appetite' => app(Appetites::class)->level($data['appetite'] ?? null),
            'color' => array_key_exists((string) ($data['color'] ?? ''), HouseholdPerson::COLORS) ? (string) $data['color'] : null,
            'at_table' => $atTable,
            'canteen_days' => $days ?: null,
            'canteen_name' => $days ? (mb_substr(trim((string) ($data['canteen_name'] ?? '')), 0, 80) ?: null) : null,
            'share_tastes' => (bool) ($data['share_tastes'] ?? false),
        ];

        $person = DB::transaction(function () use ($person, $values) {
            if ($person) {
                $person->update($values);
            } else {
                $person = HouseholdPerson::create([...$values, 'position' => $this->nextPosition()]);
            }

            // Plus de cantine : ses menus saisis n'ont plus de raison d'être.
            if ($values['canteen_days'] === null) {
                $person->canteenMeals()->delete();
            }

            return $person;
        });

        $this->syncSize();

        return $person->fresh();
    }

    /** Retire une personne : ses goûts et ses menus de cantine partent avec elle ; ses gamelles reviennent à la table. */
    public function delete(HouseholdPerson $person): void
    {
        if ($person->at_table && HouseholdPerson::query()->atTable()->whereKeyNot($person->id)->doesntExist()) {
            throw new InvalidArgumentException('Il faut au moins une personne à table.');
        }

        DB::transaction(function () use ($person) {
            \App\Models\PlannedMeal::query()->where('for_person_id', $person->id)->update(['for_person_id' => null, 'is_lunchbox' => false]);
            $person->delete();
        });

        $this->syncSize();
    }

    /** Monte ou descend une personne dans la liste. */
    public function move(HouseholdPerson $person, int $direction): void
    {
        $people = $this->all()->values();
        $index = $people->search(fn (HouseholdPerson $p) => $p->is($person));
        $swap = $index === false ? null : $people->get($index + ($direction < 0 ? -1 : 1));

        if (! $swap) {
            return;
        }

        $ordered = $people->all();
        [$ordered[$index], $ordered[$index + ($direction < 0 ? -1 : 1)]] = [$swap, $person];

        foreach (array_values($ordered) as $position => $row) {
            if ($row->position !== $position) {
                $row->update(['position' => $position]);
            }
        }
    }

    /**
     * Comptes du foyer qui n'ont pas encore de personne (à proposer sur la page Foyer).
     *
     * @return Collection<int, User>
     */
    public function accountsWithoutPerson(): Collection
    {
        $linked = HouseholdPerson::query()->whereNotNull('user_id')->pluck('user_id')->all();

        return User::query()->inHousehold()->whereNotIn('id', $linked ?: [0])->orderBy('name')->get(['id', 'name']);
    }

    /** « Personnes à table » suit la liste (réglage gardé pour les écrans qui l'affichent). */
    private function syncSize(): void
    {
        Settings::set('household_size', max(1, min(20, HouseholdPerson::query()->atTable()->count())));
    }

    private function nextPosition(): int
    {
        return (int) HouseholdPerson::query()->max('position') + 1;
    }
}
