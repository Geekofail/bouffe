<?php

namespace App\Models;

use App\Support\CurrentHousehold;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un remplacement d'ingrédient (lot 40, 40.1, R43) : « crème liquide → crème de soja, même
 * quantité ». Commun à l'installation (household_id vide, liste de départ), propre à un foyer, ou
 * propre à une recette (recipe_id). Il ne modifie jamais la recette : il est proposé.
 *
 * R29 : un foyer voit la liste commune et ses propres remplacements, jamais ceux d'un autre foyer.
 *
 * @property int $id
 * @property int|null $household_id
 * @property int $ingredient_id
 * @property int $substitute_id
 * @property string $ratio
 * @property int|null $recipe_id
 * @property string|null $note
 * @property string $source seed · manual · assistant · variant
 */
#[Fillable(['household_id', 'ingredient_id', 'substitute_id', 'ratio', 'recipe_id', 'note', 'source'])]
class IngredientSubstitution extends Model
{
    protected $attributes = ['ratio' => 1, 'source' => 'manual'];

    protected function casts(): array
    {
        return ['ratio' => 'decimal:3'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('visible', fn (Builder $query) => $query->where(fn ($q) => $q
            ->whereNull($query->qualifyColumn('household_id'))
            ->orWhere($query->qualifyColumn('household_id'), CurrentHousehold::id() ?? 0)));
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function substitute(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'substitute_id');
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function isCommon(): bool
    {
        return $this->household_id === null;
    }

    /** « même quantité », « × 0,8 » */
    public function ratioLabel(): string
    {
        $ratio = (float) $this->ratio;

        return abs($ratio - 1) < 0.001 ? 'même quantité' : '× '.rtrim(rtrim(number_format($ratio, 2, ',', ''), '0'), ',');
    }
}
