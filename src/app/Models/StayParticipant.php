<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une personne d'un séjour (34.1) : un membre du foyer, un invité du carnet, le compte d'un foyer
 * relié, ou simplement un prénom. Son groupe dit qui paie avec qui (R37).
 *
 * @property int $id
 * @property int $stay_id
 * @property string $name
 * @property string $appetite
 * @property string $group_label
 * @property int|null $user_id
 * @property int|null $guest_id
 * @property int|null $linked_household_id
 * @property int|null $linked_user_id
 * @property Carbon|null $present_from
 * @property Carbon|null $present_to
 */
#[Fillable(['name', 'appetite', 'group_label', 'user_id', 'person_id', 'guest_id', 'linked_household_id', 'linked_user_id', 'present_from', 'present_to', 'position'])]
class StayParticipant extends Model
{
    protected function casts(): array
    {
        return ['present_from' => 'date', 'present_to' => 'date', 'position' => 'integer'];
    }

    /** @return BelongsTo<Stay, $this> */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    /** @return BelongsTo<Guest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /** @return BelongsTo<Household, $this> */
    public function linkedHousehold(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'linked_household_id');
    }

    /** Présent ce jour-là (bornes comprises). */
    public function presentOn(Carbon $date, Stay $stay): bool
    {
        $from = $this->present_from ?? $stay->starts_on;
        $to = $this->present_to ?? $stay->ends_on;

        return $date->between($from->copy()->startOfDay(), $to->copy()->endOfDay());
    }

    /** Nombre de jours de présence pendant le séjour. */
    public function daysPresent(Stay $stay): int
    {
        $from = ($this->present_from ?? $stay->starts_on)->copy()->max($stay->starts_on);
        $to = ($this->present_to ?? $stay->ends_on)->copy()->min($stay->ends_on);

        return $to->lt($from) ? 0 : (int) $from->diffInDays($to) + 1;
    }

    /** « Membre », « Invité », « Martin (foyer relié) », « » */
    public function origin(): string
    {
        return match (true) {
            $this->user_id !== null => 'du foyer',
            $this->guest_id !== null => 'invité',
            $this->linked_household_id !== null => 'foyer relié',
            default => '',
        };
    }
}
