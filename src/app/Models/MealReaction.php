<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réaction à un repas mangé (18.4) : 👍 ou 👎, une par personne et par repas.
 *
 * @property int $id
 * @property int $planned_meal_id
 * @property int $user_id
 * @property int $value 1 = aimé · -1 = pas aimé
 * @property string|null $comment
 */
#[Fillable(['planned_meal_id', 'user_id', 'value', 'comment'])]
class MealReaction extends Model
{
    public const LIKE = 1;

    public const DISLIKE = -1;

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }

    /** @return BelongsTo<PlannedMeal, $this> */
    public function meal(): BelongsTo
    {
        return $this->belongsTo(PlannedMeal::class, 'planned_meal_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isLike(): bool
    {
        return $this->value > 0;
    }
}
