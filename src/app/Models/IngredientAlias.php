<?php

namespace App\Models;

use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Autre nom d'un ingrédient (ancien nom après une fusion, variante saisie) — règle R22.
 *
 * @property int $id
 * @property int $ingredient_id
 * @property string $name
 * @property string $search_name
 */
#[Fillable(['ingredient_id', 'name'])]
class IngredientAlias extends Model
{
    protected static function booted(): void
    {
        static::saving(fn (IngredientAlias $alias) => $alias->search_name = NameNormalizer::normalize($alias->name));
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
