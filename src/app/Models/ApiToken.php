<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Jeton personnel des raccourcis (lot 38, 38.1, règle R39). Seule l'empreinte est gardée.
 *
 * @property int $id
 * @property int $user_id
 * @property string $hint
 * @property Carbon|null $last_used_at
 * @property int $uses
 * @property Carbon|null $revoked_at
 */
#[Fillable(['user_id', 'token_hash', 'hint', 'last_used_at', 'uses', 'revoked_at'])]
#[Hidden(['token_hash'])]
class ApiToken extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime', 'uses' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
