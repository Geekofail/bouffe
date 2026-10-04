<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contribution d'un repas planifié à un article (provenance, règle R5).
 *
 * @property string $recipe_title
 * @property \Illuminate\Support\Carbon $meal_date
 * @property string|null $slot_name
 * @property string|null $quantity
 * @property int|null $unit_id
 * @property bool $is_optional
 */
#[Fillable(['planned_meal_id', 'recipe_title', 'meal_date', 'slot_name', 'servings', 'quantity', 'unit_id', 'is_optional'])]
class ShoppingListItemSource extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'meal_date' => 'date',
            'servings' => 'float',
            'quantity' => 'decimal:3',
            'is_optional' => 'boolean',
        ];
    }

    public function setMealDateAttribute(mixed $value): void
    {
        $this->attributes['meal_date'] = \Illuminate\Support\Carbon::parse($value)->toDateString();
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
