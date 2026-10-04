<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne du journal du foyer (30.2) : « Monique a coché 12 articles dans « Courses de la semaine » ».
 *
 * @property int|null $user_id
 * @property string $type
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property int $count
 * @property string $summary
 */
#[Fillable(['user_id', 'type', 'subject_type', 'subject_id', 'count', 'summary', 'created_at', 'updated_at'])]
class ActivityEvent extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    protected function casts(): array
    {
        return ['count' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
