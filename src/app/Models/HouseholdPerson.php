<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une personne du foyer (lot 39, 39.1, R40) : avec ou sans compte. Un enfant a un prénom, un
 * appétit, des goûts et, s'il y mange, une cantine ; un compte peut lui être rattaché.
 *
 * Même forme qu'un invité pour `GuestCompatibility` : `name` et `restrictions`.
 *
 * @property int $id
 * @property int $household_id
 * @property int|null $user_id
 * @property string $name
 * @property string $appetite
 * @property string|null $color
 * @property bool $at_table
 * @property list<int>|null $canteen_days
 * @property string|null $canteen_name
 * @property bool $share_tastes
 * @property int $position
 */
#[Fillable(['user_id', 'name', 'appetite', 'color', 'at_table', 'canteen_days', 'canteen_name', 'share_tastes', 'position'])]
class HouseholdPerson extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    protected $table = 'household_people';

    /** Couleurs proposées : clé => [nom, teinte]. Des teintes moyennes, lisibles en clair comme en sombre. */
    public const COLORS = [
        'tomate' => ['Tomate', '#cf5530'],
        'basilic' => ['Basilic', '#4f8a5b'],
        'ciel' => ['Ciel', '#3b82c4'],
        'prune' => ['Prune', '#7c5cc4'],
        'safran' => ['Safran', '#c98a12'],
        'framboise' => ['Framboise', '#c9507a'],
        'menthe' => ['Menthe', '#2a9d8f'],
        'ardoise' => ['Ardoise', '#6b7280'],
    ];

    /** Jours possibles de cantine (ISO : 1 = lundi). */
    public const SCHOOL_DAYS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi'];

    protected $attributes = [
        'appetite' => 'normal',
        'at_table' => true,
        'share_tastes' => false,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'at_table' => 'boolean',
            'share_tastes' => 'boolean',
            'canteen_days' => 'array',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PersonRestriction, $this> */
    public function restrictions(): HasMany
    {
        return $this->hasMany(PersonRestriction::class, 'person_id');
    }

    /** @return HasMany<CanteenMeal, $this> */
    public function canteenMeals(): HasMany
    {
        return $this->hasMany(CanteenMeal::class, 'person_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    public function scopeAtTable(Builder $query): Builder
    {
        return $query->where('at_table', true);
    }

    public function scopeWithCanteen(Builder $query): Builder
    {
        return $query->whereNotNull('canteen_days');
    }

    /** Teinte de la personne (sa couleur, sinon une couleur stable d'après son numéro). */
    public function hex(): string
    {
        $keys = array_keys(self::COLORS);

        return self::COLORS[$this->color ?? ''][1] ?? self::COLORS[$keys[$this->id % count($keys)]][1];
    }

    public function initial(): string
    {
        return mb_strtoupper(mb_substr($this->name, 0, 1));
    }

    /** @return list<int> */
    public function canteenDays(): array
    {
        return array_values(array_map('intval', (array) $this->canteen_days));
    }

    public function hasCanteen(): bool
    {
        return $this->canteenDays() !== [];
    }

    /** Ses goûts sont-ils montrés aux foyers reliés ? Le compte en décide (Mon compte), sinon un adulte du foyer (R40). */
    public function sharesTastes(): bool
    {
        return $this->user_id !== null ? (bool) $this->user?->share_restrictions : $this->share_tastes;
    }

    /** Compatibilité avec GuestCompatibility (même forme qu'un invité). */
    public function appetiteLevel(): string
    {
        return $this->appetite ?: 'normal';
    }
}
