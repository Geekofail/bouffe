<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'ingrédient d'une recette.
 *
 * @property int $id
 * @property int $recipe_id
 * @property int $ingredient_id
 * @property string|null $quantity
 * @property int|null $unit_id
 * @property string|null $preparation
 * @property string|null $group_name
 * @property bool $is_optional
 * @property int $sort_order
 * @property-read Ingredient $ingredient
 * @property-read Unit|null $unit
 */
#[Fillable(['ingredient_id', 'quantity', 'unit_id', 'preparation', 'group_name', 'is_optional', 'sort_order'])]
class RecipeIngredient extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'is_optional' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
