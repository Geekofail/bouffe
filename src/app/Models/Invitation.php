<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Invitation dans un foyer (25.2) : lien à usage unique, valable 7 jours.
 * Seul le condensé du jeton est enregistré : le lien ne se retrouve pas depuis la base.
 *
 * @property int $id
 * @property int $household_id
 * @property string|null $email
 * @property UserRole $role
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
#[Fillable(['household_id', 'email', 'token_hash', 'role', 'invited_by', 'expires_at', 'accepted_at', 'accepted_by'])]
class Invitation extends Model
{
    public const VALID_DAYS = 7;

    protected function casts(): array
    {
        return ['role' => UserRole::class, 'expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isUsable(): bool
    {
        return ! $this->accepted_at && $this->expires_at->isFuture() && $this->household?->isActive() && ! $this->household?->deletion_requested_at;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
