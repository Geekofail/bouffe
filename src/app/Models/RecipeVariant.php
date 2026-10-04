<?php

namespace App\Models;

use App\Enums\RestrictionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Variante d'une recette (13.7) : « version végétarienne », « sans lactose ».
 *
 * Une variante ne duplique pas la recette : elle décrit seulement ce qui change. La recette
 * reste unique, ses étapes aussi ; seuls quelques ingrédients sont remplacés ou retirés.
 *
 * @property int $recipe_id
 * @property string $name
 * @property string|null $note
 * @property RestrictionType|null $solves_type
 */
#[Fillable(['recipe_id', 'name', 'note', 'solves_type', 'solves_ingredient_id', 'solves_tag_id', 'sort_order'])]
class RecipeVariant extends Model
{
    protected function casts(): array
    {
        return ['solves_type' => RestrictionType::class, 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return HasMany<RecipeVariantSwap, $this> */
    public function swaps(): HasMany
    {
        return $this->hasMany(RecipeVariantSwap::class);
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function solvesIngredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'solves_ingredient_id');
    }

    /** @return BelongsTo<Tag, $this> */
    public function solvesTag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'solves_tag_id');
    }

    /** « sans noix », « végétarien » — ce que cette variante permet d'éviter. */
    public function solvesLabel(): ?string
    {
        return $this->solvesIngredient?->name ?? $this->solvesTag?->name;
    }
}
