<?php

namespace App\Models;

use Database\Factories\GuestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Invité du carnet (ami, famille…).
 *
 * @property int $id
 * @property string $name
 * @property string|null $group_name
 * @property bool $is_child
 * @property string|null $notes
 * @property Carbon|null $archived_at
 */
#[Fillable(['name', 'group_name', 'is_child', 'appetite', 'notes', 'archived_at'])]
class Guest extends Model
{
    /** @use HasFactory<GuestFactory> */
    use \App\Models\Concerns\BelongsToHousehold, HasFactory;

    protected function casts(): array
    {
        return ['is_child' => 'boolean', 'archived_at' => 'datetime'];
    }

    /** @return HasMany<GuestRestriction, $this> */
    public function restrictions(): HasMany
    {
        return $this->hasMany(GuestRestriction::class);
    }

    /** @return BelongsToMany<MealOccasion, $this> */
    public function occasions(): BelongsToMany
    {
        return $this->belongsToMany(MealOccasion::class, 'meal_occasion_guest');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** Initiales pour l'avatar : « Julie Martin » → « JM ». */
    public function initials(): string
    {
        $words = preg_split('/[\s-]+/u', trim($this->name)) ?: [];

        return mb_strtoupper(implode('', array_map(fn (string $w) => mb_substr($w, 0, 1), array_slice($words, 0, 2))));
    }

    /** Appétit (lot 32, R33) : celui choisi, sinon d'après « enfant ». */
    public function appetiteLevel(): string
    {
        return $this->appetite ?: ($this->is_child ? 'petit' : 'normal');
    }
}
