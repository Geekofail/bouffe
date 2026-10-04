<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Minuteur partagé (lot 41, 41.1) : lancé sur un appareil, visible sur tous ceux du foyer.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $label
 * @property int $duration secondes
 * @property Carbon $ends_at
 * @property string|null $source
 * @property Carbon|null $rang_at
 * @property Carbon|null $stopped_at
 * @property Carbon|null $notified_at
 */
#[Fillable(['user_id', 'label', 'duration', 'ends_at', 'source', 'rang_at', 'stopped_at', 'notified_at'])]
class KitchenTimer extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    protected function casts(): array
    {
        return [
            'duration' => 'integer',
            'ends_at' => 'datetime',
            'rang_at' => 'datetime',
            'stopped_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** En cours ou fini depuis peu, et pas encore arrêté. */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereNull('stopped_at');
    }

    public function isFinished(?Carbon $now = null): bool
    {
        return $this->ends_at->lte($now ?? now());
    }
}
