<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un remplacement dans une variante (13.7) : telle ligne devient tel ingrédient, ou disparaît.
 *
 * @property int $recipe_variant_id
 * @property int $recipe_ingredient_id
 * @property int|null $ingredient_id null = la ligne est retirée
 */
#[Fillable(['recipe_variant_id', 'recipe_ingredient_id', 'ingredient_id', 'quantity', 'unit_id', 'note'])]
class RecipeVariantSwap extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    /** @return BelongsTo<RecipeVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(RecipeVariant::class, 'recipe_variant_id');
    }

    /** @return BelongsTo<RecipeIngredient, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(RecipeIngredient::class, 'recipe_ingredient_id');
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

    public function removesLine(): bool
    {
        return $this->ingredient_id === null;
    }
}
