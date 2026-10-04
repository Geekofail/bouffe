<?php

namespace App\Services\Planning;

use App\Enums\MealType;
use App\Models\Guest;
use App\Models\MealOccasion;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Convives d'une case du planning (règle R7), comptés en portions depuis le lot 32 (R33).
 *
 *   portions = parts des personnes du foyer présentes + parts des invités + invités ajoutés au nombre
 *              (adulte : 1, enfant : « petit ») + personnes des foyers reliés (1), arrondi à la demi-portion supérieure
 *
 * Sans enregistrement pour la case : les personnes à table d'habitude, aucun invité.
 * people() donne, lui, le nombre de personnes (« 4 personnes · 3,5 portions »).
 *
 * Lot 39 : une personne sans compte peut être absente (`absent_person_ids`), et une personne qui
 * mange à la cantine n'est pas comptée au déjeuner de ses jours de cantine (R41).
 */
class OccasionService
{
    public function householdSize(): int
    {
        // Par foyer (lot 24), avec la valeur de .env (BOUFFE_HOUSEHOLD_SIZE) par défaut.
        return max(1, \App\Support\Settings::int('household_size', (int) config('bouffe.household_size', 2)));
    }

    /** Part d'un enfant ajouté au nombre (« +2 enfants ») : l'appétit « petit ». */
    public function childFactor(): float
    {
        return $this->appetites()->part('petit');
    }

    private function appetites(): Appetites
    {
        return app(Appetites::class);
    }

    /* ================================================================ Lecture */

    public function find(Carbon|string $date, MealSlot|int $slot): ?MealOccasion
    {
        [$day, $slotId] = explode('|', $this->key($date, $slot));

        return MealOccasion::query()
            ->whereDate('date', $day)->where('meal_slot_id', (int) $slotId)
            ->with('guests.restrictions.ingredient', 'guests.restrictions.tag')
            ->first();
    }

    /** @return Collection<string, MealOccasion> convives de la période, par « AAAA-MM-JJ|créneau » */
    public function forRange(Carbon $from, Carbon $to): Collection
    {
        return MealOccasion::query()->between($from, $to)
            ->with('guests.restrictions.ingredient', 'guests.restrictions.tag', 'slot')
            ->orderBy('date')->orderBy('meal_slot_id')
            ->get()
            ->keyBy(fn (MealOccasion $o) => $o->cellKey());
    }

    /** Portions d'une case (0 si tout le monde est absent et sans invité). */
    public function dinersAt(Carbon|string $date, MealSlot|int $slot): float
    {
        return $this->diners($this->find($date, $slot), $date, $slot);
    }

    /**
     * Personnes du foyer absentes d'une case : [comptes, personnes] — celles notées absentes, et
     * celles qui mangent à la cantine ce midi-là (lot 39).
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    public function absences(?MealOccasion $occasion, Carbon|string|null $date = null, MealSlot|int|null $slot = null): array
    {
        $date = $occasion?->date ?? $date;
        $slot = $occasion?->meal_slot_id ?? $slot;
        $persons = array_map('intval', $occasion?->absent_person_ids ?? []);

        if ($date !== null && $slot !== null) {
            $persons = array_values(array_unique([...$persons, ...app(\App\Services\People\CanteenCalendar::class)->absentAt($date, $slot)]));
        }

        return [array_map('intval', $occasion?->absent_user_ids ?? []), $persons];
    }

    /** Portions d'une case, d'après l'appétit de chacun (R33). Sans case : la table d'habitude. */
    public function diners(?MealOccasion $occasion, Carbon|string|null $date = null, MealSlot|int|null $slot = null): float
    {
        $appetites = $this->appetites();
        [$absentUsers, $absentPersons] = $this->absences($occasion, $date, $slot);

        if (! $occasion) {
            return Appetites::roundUp($appetites->householdPortions($absentUsers, $absentPersons));
        }

        $portions = $appetites->householdPortions($absentUsers, $absentPersons)
            + $occasion->guests->sum(fn (Guest $guest) => $appetites->part($guest->appetiteLevel()))
            + $occasion->extra_adults
            + $occasion->extra_children * $appetites->part('petit')
            // Lot 26 (26.5) : personnes annoncées par les foyers reliés qui viennent.
            + app(\App\Services\Linked\SharedMeals::class)->linkedPeople($occasion);

        return Appetites::roundUp($portions);
    }

    /** Nombre de personnes à table dans une case (« 4 personnes »). */
    public function people(?MealOccasion $occasion, Carbon|string|null $date = null, MealSlot|int|null $slot = null): int
    {
        if (! $occasion) {
            return $this->householdPresent(null, $date, $slot);
        }

        return $this->householdPresent($occasion) + $occasion->guests->count() + $occasion->extra_adults + $occasion->extra_children
            + app(\App\Services\Linked\SharedMeals::class)->linkedPeople($occasion);
    }

    public function peopleAt(Carbon|string $date, MealSlot|int $slot): int
    {
        return $this->people($this->find($date, $slot), $date, $slot);
    }

    /** « 4 personnes · 3,5 portions » (ou « 2 personnes » quand les deux chiffres se confondent). */
    public function summary(?MealOccasion $occasion, Carbon|string|null $date = null, MealSlot|int|null $slot = null): string
    {
        $people = $this->people($occasion, $date, $slot);
        $portions = $this->diners($occasion, $date, $slot);
        $text = $people.' personne'.($people > 1 ? 's' : '');

        return (float) $people === $portions ? $text : $text.' · '.Appetites::label($portions);
    }

    /** Portions proposées pour un plat de la case (au moins 1). */
    public function servingsAt(Carbon|string $date, MealSlot|int $slot): float
    {
        return max(1.0, $this->dinersAt($date, $slot));
    }

    public function householdPresent(?MealOccasion $occasion, Carbon|string|null $date = null, MealSlot|int|null $slot = null): int
    {
        return $this->appetites()->householdPeople(...$this->absences($occasion, $date, $slot));
    }

    /** « Julie, Paul + 2 enfants » */
    public function guestSummary(MealOccasion $occasion): string
    {
        $parts = $occasion->guests->pluck('name')->all();

        // Foyers reliés qui viennent (26.5).
        foreach (\App\Models\MealOccasionHousehold::query()->where('meal_occasion_id', $occasion->id)->where('status', 'accepted')->with('household')->get() as $row) {
            $parts[] = $row->household?->name.($row->people ? ' ('.$row->people.')' : '');
        }

        if ($occasion->extra_adults > 0) {
            $parts[] = '+'.$occasion->extra_adults.' adulte'.($occasion->extra_adults > 1 ? 's' : '');
        }

        if ($occasion->extra_children > 0) {
            $parts[] = '+'.$occasion->extra_children.' enfant'.($occasion->extra_children > 1 ? 's' : '');
        }

        $absent = User::query()->inHousehold()->whereIn('id', $occasion->absent_user_ids ?? [])->orderBy('name')->pluck('name')
            ->merge(\App\Models\HouseholdPerson::query()->whereIn('id', $occasion->absent_person_ids ?? [])->orderBy('name')->pluck('name'))->unique()->sort()->values();

        if ($absent->isNotEmpty()) {
            $parts[] = 'sans '.$absent->join(', ', ' ni ');
        }

        return implode(', ', $parts);
    }

    /* ================================================================ Écriture */

    /**
     * Enregistre les convives d'une case. Revenir à la situation par défaut (foyer complet, aucun invité,
     * ni occasion ni note) supprime l'enregistrement.
     *
     * @param  array{title?: string|null, notes?: string|null, absent_user_ids?: list<int>, absent_person_ids?: list<int>, guest_ids?: list<int>, extra_adults?: int, extra_children?: int}  $data
     * @return array{occasion: MealOccasion|null, before: float, after: float}
     */
    public function save(Carbon|string $date, MealSlot|int $slot, array $data): array
    {
        $date = Carbon::parse($date)->toDateString();
        $slotId = $slot instanceof MealSlot ? $slot->id : $slot;
        $before = $this->dinersAt($date, $slotId);

        $extraAdults = (int) ($data['extra_adults'] ?? 0);
        $extraChildren = (int) ($data['extra_children'] ?? 0);

        if ($extraAdults < 0 || $extraAdults > 50 || $extraChildren < 0 || $extraChildren > 50) {
            throw new InvalidArgumentException('Le nombre d\'invités doit être compris entre 0 et 50.');
        }

        $guestIds = Guest::query()->whereIn('id', array_map('intval', $data['guest_ids'] ?? []))->pluck('id')->all();
        $absent = User::query()->inHousehold()->whereIn('id', array_map('intval', $data['absent_user_ids'] ?? []))->orderBy('id')->pluck('id')->all();
        // Personnes sans compte (les comptes passent par absent_user_ids).
        $absentPersons = \App\Models\HouseholdPerson::query()->whereNull('user_id')->whereIn('id', array_map('intval', $data['absent_person_ids'] ?? []))->orderBy('id')->pluck('id')->all();
        $title = trim((string) ($data['title'] ?? '')) ?: null;
        $notes = trim((string) ($data['notes'] ?? '')) ?: null;

        $isDefault = $guestIds === [] && $absent === [] && $absentPersons === [] && $extraAdults === 0 && $extraChildren === 0 && $title === null && $notes === null;

        $occasion = DB::transaction(function () use ($date, $slotId, $isDefault, $title, $notes, $absent, $absentPersons, $extraAdults, $extraChildren, $guestIds) {
            $existing = MealOccasion::query()->whereDate('date', $date)->where('meal_slot_id', $slotId)->first();

            if ($isDefault) {
                $existing?->delete();

                return null;
            }

            $occasion = $existing ?? new MealOccasion(['date' => $date, 'meal_slot_id' => $slotId, 'created_by' => auth()->id()]);
            $occasion->fill([
                'title' => mb_substr((string) $title, 0, 150) ?: null,
                'notes' => mb_substr((string) $notes, 0, 255) ?: null,
                'absent_user_ids' => $absent ?: null,
                'absent_person_ids' => $absentPersons ?: null,
                'extra_adults' => $extraAdults,
                'extra_children' => $extraChildren,
            ])->save();

            $occasion->guests()->sync($guestIds);

            return $occasion->load('guests.restrictions.ingredient', 'guests.restrictions.tag');
        });

        return ['occasion' => $occasion, 'before' => $before, 'after' => $this->dinersAt($date, $slotId)];
    }

    public function clear(Carbon|string $date, MealSlot|int $slot): void
    {
        $this->save($date, $slot, []);
    }

    /**
     * Adapte les portions des plats « recette » d'une case après un changement de convives,
     * en gardant les portions prévues en plus pour les restes (4 portions pour 2 → 8 pour 6).
     *
     * @return int nombre de plats adaptés
     */
    public function adaptServings(Carbon|string $date, MealSlot|int $slot, float $before, float $after): int
    {
        $slotId = $slot instanceof MealSlot ? $slot->id : $slot;
        $planner = app(WeekPlanner::class);
        $count = 0;

        $meals = PlannedMeal::query()->whereDate('date', Carbon::parse($date)->toDateString())
            ->where('meal_slot_id', $slotId)->where('type', MealType::Recipe->value)->get();

        foreach ($meals as $meal) {
            $servings = Appetites::clamp(max(1, $meal->servings - $before + $after));

            if ($servings === (float) $meal->servings) {
                continue;
            }

            try {
                $planner->update($meal, ['servings' => $servings]);
                $count++;
            } catch (InvalidArgumentException) {
                // restes déjà placés : portions laissées telles quelles
            }
        }

        return $count;
    }

    private function key(Carbon|string $date, MealSlot|int $slot): string
    {
        return Carbon::parse($date)->toDateString().'|'.($slot instanceof MealSlot ? $slot->id : $slot);
    }
}
