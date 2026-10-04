<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Convives particuliers d'une case du planning (date × créneau) : absents du foyer, invités, occasion.
 * Pas d'enregistrement = foyer complet sans invité.
 *
 * @property int $id
 * @property Carbon $date
 * @property int $meal_slot_id
 * @property string|null $title
 * @property list<int>|null $absent_user_ids
 * @property int $extra_adults
 * @property int $extra_children
 * @property string|null $notes
 * @property string|null $serve_time « 19:30 » (lot 20)
 * @property string|null $menu_message mot sur la carte de menu (21.3)
 * @property list<string>|null $timeline_done tâches du rétroplanning faites (21.2)
 * @property string|null $memory_note souvenir (21.4)
 * @property string|null $memory_photo
 * @property-read MealSlot $slot
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Guest> $guests
 */
#[Fillable(['date', 'meal_slot_id', 'title', 'serve_time', 'absent_user_ids', 'absent_person_ids', 'extra_adults', 'extra_children', 'notes', 'menu_message', 'timeline_done', 'memory_note', 'memory_photo', 'created_by'])]
class MealOccasion extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'absent_user_ids' => 'array',
            'absent_person_ids' => 'array',
            'timeline_done' => 'array',
            'extra_adults' => 'integer',
            'extra_children' => 'integer',
        ];
    }

    public function setDateAttribute(mixed $value): void
    {
        $this->attributes['date'] = Carbon::parse($value)->toDateString();
    }

    /** @return BelongsTo<MealSlot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(MealSlot::class, 'meal_slot_id');
    }

    /** @return BelongsToMany<Guest, $this> */
    public function guests(): BelongsToMany
    {
        return $this->belongsToMany(Guest::class, 'meal_occasion_guest')->orderBy('name');
    }

    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
    }

    public function cellKey(): string
    {
        return $this->date->toDateString().'|'.$this->meal_slot_id;
    }

    /** Repas planifiés dans la case. @return \Illuminate\Database\Eloquent\Collection<int, PlannedMeal> */
    public function meals(): \Illuminate\Database\Eloquent\Collection
    {
        return PlannedMeal::query()->whereDate('date', $this->date->toDateString())
            ->where('meal_slot_id', $this->meal_slot_id)
            ->with('recipe', 'leftoverOf.recipe')
            ->orderBy('position')->get();
    }

    /** Heure du repas : celle saisie, sinon l'heure par défaut du créneau. */
    public function serveAt(): Carbon
    {
        [$hour, $minute] = $this->serve_time && preg_match('/^(\d{1,2}):(\d{2})$/', $this->serve_time, $m)
            ? [(int) $m[1], (int) $m[2]]
            : self::defaultTime($this->slot?->name);

        return $this->date->copy()->setTime($hour, $minute);
    }

    /**
     * Heure habituelle d'un créneau d'après son nom (les créneaux n'ont pas d'heure en base).
     *
     * @return array{0: int, 1: int}
     */
    public static function defaultTime(?string $slotName): array
    {
        $name = \App\Support\NameNormalizer::normalize((string) $slotName);

        return match (true) {
            str_contains($name, 'petit') => [8, 30],
            str_contains($name, 'dejeuner'), str_contains($name, 'midi'), str_contains($name, 'brunch') => [12, 30],
            str_contains($name, 'gouter') => [16, 30],
            default => [(int) config('bouffe.planning.meal_hour', 19), 0],
        };
    }

    public function guestCount(): int
    {
        return $this->guests->count() + $this->extra_adults + $this->extra_children;
    }
}
