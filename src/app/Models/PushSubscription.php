<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un téléphone ou un navigateur abonné aux notifications (19.2).
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $public_key
 * @property string $auth_token
 * @property string|null $device
 * @property Carbon|null $last_success_at
 * @property int $failures
 */
#[Fillable(['user_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token', 'device', 'last_success_at', 'failures'])]
class PushSubscription extends Model
{
    protected $hidden = ['auth_token'];

    protected function casts(): array
    {
        return [
            'last_success_at' => 'datetime',
            'failures' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashOf(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
