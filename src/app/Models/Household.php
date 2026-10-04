<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Foyer (lot 24, 25.1) : ses membres, et toutes ses données (recettes, planning, stock, budget…).
 *
 * @property int $id
 * @property string $name
 * @property int|null $created_by
 * @property Carbon|null $disabled_at
 * @property Carbon|null $deletion_requested_at
 */
#[Fillable(['name', 'created_by', 'disabled_at', 'deletion_requested_at'])]
class Household extends Model
{
    /** Délai avant la suppression effective d'un foyer (25.8). */
    public const DELETION_DELAY_DAYS = 30;

    protected function casts(): array
    {
        return ['disabled_at' => 'datetime', 'deletion_requested_at' => 'datetime'];
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(HouseholdMember::class)->withPivot('id', 'role', 'last_active_at')->withTimestamps()->orderBy('name');
    }

    /** @return HasMany<Invitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function isActive(): bool
    {
        return ! $this->disabled_at;
    }

    public function deletionDate(): ?Carbon
    {
        return $this->deletion_requested_at?->copy()->addDays(self::DELETION_DELAY_DAYS);
    }

    public function owners(): int
    {
        return $this->members()->wherePivot('role', UserRole::Owner->value)->count();
    }
}
