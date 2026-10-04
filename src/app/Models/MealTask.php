<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une étape d'un repas complet (lot 41, 41.3) : à qui elle revient, et si elle est faite.
 *
 * Sans ligne, ou sans personne, l'étape revient à celle qui cuisine le plat
 * (`planned_meals.cook_user_id`, 14.7).
 *
 * @property int $id
 * @property int $planned_meal_id
 * @property int $step_number
 * @property int|null $user_id
 * @property Carbon|null $done_at
 * @property int|null $done_by
 */
#[Fillable(['planned_meal_id', 'step_number', 'user_id', 'done_at', 'done_by'])]
class MealTask extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    protected function casts(): array
    {
        return ['step_number' => 'integer', 'done_at' => 'datetime'];
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
}
