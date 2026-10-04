<?php

namespace App\Models;

use App\Enums\MealType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un repas d'une semaine type : jour de la semaine (1 = lundi) × créneau.
 *
 * Les restes pointent vers une autre case du modèle (`leftover_weekday`, `leftover_meal_slot_id`)
 * et non vers un repas : le modèle est réutilisable d'une semaine à l'autre.
 *
 * @property int $id
 * @property int $week_template_id
 * @property int $weekday
 * @property int $meal_slot_id
 * @property int $position
 * @property MealType $type
 * @property int|null $recipe_id
 * @property string|null $free_text
 * @property int|null $leftover_weekday
 * @property int|null $leftover_meal_slot_id
 */
#[Fillable([
    'week_template_id', 'weekday', 'meal_slot_id', 'position', 'type',
    'recipe_id', 'free_text', 'leftover_weekday', 'leftover_meal_slot_id',
])]
class WeekTemplateMeal extends Model
{
    protected function casts(): array
    {
        return [
            'type' => MealType::class,
            'weekday' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return BelongsTo<MealSlot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(MealSlot::class, 'meal_slot_id');
    }

    public function label(): string
    {
        return match ($this->type) {
            MealType::Recipe => $this->recipe?->title ?? 'Recette supprimée',
            MealType::Leftover => 'Restes',
            MealType::Free => (string) $this->free_text,
        };
    }
}
