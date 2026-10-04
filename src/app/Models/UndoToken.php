<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un geste annulable (30.1, R32) : l'état d'avant, gardé quelques minutes.
 *
 * @property string $token
 * @property string $action
 * @property string $label
 * @property array $payload
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
#[Fillable(['user_id', 'token', 'action', 'label', 'payload', 'expires_at', 'used_at'])]
class UndoToken extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
