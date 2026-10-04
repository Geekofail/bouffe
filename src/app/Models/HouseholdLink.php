<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Deux foyers reliés (lot 26, module 26) : l'un invite (lien à usage unique, 7 jours), l'autre accepte.
 * Tant qu'elle n'est pas acceptée, la ligne n'est qu'une invitation (linked_household_id vide).
 *
 * @property int $id
 * @property int $household_id
 * @property int|null $linked_household_id
 * @property Carbon|null $expires_at
 * @property Carbon|null $accepted_at
 */
#[Fillable(['household_id', 'linked_household_id', 'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'accepted_by'])]
class HouseholdLink extends Model
{
    public const VALID_DAYS = 7;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<Household, $this> */
    public function linkedHousehold(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'linked_household_id');
    }

    /** Liens acceptés d'un foyer, dans un sens ou dans l'autre. */
    public function scopeActiveFor(Builder $query, int $householdId): Builder
    {
        return $query->whereNotNull('accepted_at')
            ->where(fn (Builder $q) => $q->where('household_id', $householdId)->orWhere('linked_household_id', $householdId));
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null;
    }

    public function isUsable(): bool
    {
        return $this->isPending() && $this->expires_at?->isFuture();
    }

    /** L'autre foyer, vu depuis $householdId. */
    public function otherId(int $householdId): ?int
    {
        return $this->household_id === $householdId ? $this->linked_household_id : $this->household_id;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
