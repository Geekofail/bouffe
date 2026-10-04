<?php

namespace App\Models;

use App\Enums\ReminderType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Rappel de préparation anticipée calculé pour un repas planifié (14.6, règle R21).
 *
 * `key` identifie le rappel à l'intérieur du repas (« thaw:12 ») : le recalcul met à jour
 * les rappels existants au lieu d'en recréer, ce qui conserve « fait » et « ignoré ».
 *
 * @property int $id
 * @property int $planned_meal_id
 * @property string $key
 * @property ReminderType $type
 * @property string $title
 * @property string|null $detail
 * @property Carbon $due_at
 * @property string $status
 * @property Carbon|null $handled_at
 */
#[Fillable(['planned_meal_id', 'key', 'type', 'title', 'detail', 'due_at', 'status', 'handled_at'])]
class Reminder extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    public const PENDING = 'pending';

    public const DONE = 'done';

    public const IGNORED = 'ignored';

    protected function casts(): array
    {
        return [
            'type' => ReminderType::class,
            'due_at' => 'datetime',
            'handled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PlannedMeal, $this> */
    public function meal(): BelongsTo
    {
        return $this->belongsTo(PlannedMeal::class, 'planned_meal_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    /** Rappels à faire : en attente et dont l'heure est passée ou proche. */
    public function scopeDue(Builder $query, ?Carbon $until = null): Builder
    {
        return $query->pending()->where('due_at', '<=', ($until ?? now())->toDateTimeString());
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isLate(): bool
    {
        return $this->isPending() && $this->due_at->isPast();
    }
}
