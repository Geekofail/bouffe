<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Un séjour (lot 34, 34.1) : une semaine au chalet, un week-end chez les parents. Il a ses
 * participants, son planning et sa liste de courses, à part de ceux de la maison.
 *
 * @property int $id
 * @property string $name
 * @property string|null $place
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $split_mode person · appetite (R37)
 * @property string|null $notes
 * @property Carbon|null $departed_at
 * @property Carbon|null $returned_at
 */
#[Fillable(['name', 'place', 'starts_on', 'ends_on', 'split_mode', 'notes', 'departed_at', 'returned_at', 'created_by', 'equipment'])]
class Stay extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    public const MAX_DAYS = 31;

    public const SPLIT_MODES = [
        'appetite' => 'Par appétit',
        'person' => 'Par personne',
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'departed_at' => 'datetime', 'returned_at' => 'datetime', 'equipment' => 'array'];
    }

    /** @return HasMany<StayParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(StayParticipant::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<StayMeal, $this> */
    public function meals(): HasMany
    {
        return $this->hasMany(StayMeal::class)->orderBy('date')->orderBy('meal_slot_id')->orderBy('position');
    }

    /** @return HasMany<StayPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(StayPayment::class)->orderBy('paid_on')->orderBy('id');
    }

    /** @return HasMany<StayPackedItem, $this> */
    public function packedItems(): HasMany
    {
        return $this->hasMany(StayPackedItem::class)->orderBy('id');
    }

    /** La liste du séjour, rangée chez le foyer qui organise (lue aussi par ceux qui co-organisent, 42.1). @return HasOne<ShoppingList, $this> */
    public function shoppingList(): HasOne
    {
        return $this->hasOne(ShoppingList::class)->withoutGlobalScope(Scopes\HouseholdScope::class);
    }

    /** Foyers invités à co-organiser (lot 42, 42.1). @return HasMany<StayHousehold, $this> */
    public function households(): HasMany
    {
        return $this->hasMany(StayHousehold::class)->orderBy('id');
    }

    /**
     * Lot 42 (42.1, R45) : l'adresse d'un séjour s'ouvre aussi pour un foyer qui le co-organise.
     * Ailleurs, le filtre du foyer actif reste la règle.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $household = \App\Support\CurrentHousehold::id();

        return static::query()->withoutGlobalScope(Scopes\HouseholdScope::class)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->where(fn ($q) => $q->where('household_id', $household)
                ->orWhereIn('id', StayHousehold::query()->select('stay_id')->where('household_id', $household)->where('status', 'accepted')))
            ->first();
    }

    /** @return list<Carbon> les jours du séjour */
    public function days(): array
    {
        $days = [];

        for ($day = $this->starts_on->copy(); $day->lte($this->ends_on); $day->addDay()) {
            $days[] = $day->copy();
        }

        return $days;
    }

    public function dayCount(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    /** « du 12 au 19 octobre 2026 » */
    public function period(): string
    {
        $from = $this->starts_on->locale('fr');
        $to = $this->ends_on->locale('fr');

        if ($this->starts_on->isSameDay($this->ends_on)) {
            return 'le '.$from->isoFormat('D MMMM YYYY');
        }

        return 'du '.($this->starts_on->isSameMonth($this->ends_on) ? $from->isoFormat('D') : $from->isoFormat('D MMMM'))
            .' au '.$to->isoFormat('D MMMM YYYY');
    }

    public function isPast(): bool
    {
        return $this->ends_on->lt(Carbon::today());
    }

    public function isCurrent(): bool
    {
        return Carbon::today()->between($this->starts_on, $this->ends_on);
    }
}
