<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Note de cuisine datée (13.6) : ce qu'on a appris en cuisinant la recette.
 *
 * @property int $id
 * @property int $recipe_id
 * @property int|null $user_id
 * @property int|null $planned_meal_id
 * @property string $note
 * @property int|null $servings
 */
#[Fillable(['recipe_id', 'user_id', 'planned_meal_id', 'note', 'servings'])]
class RecipeCookNote extends Model
{
    protected function casts(): array
    {
        return ['servings' => 'float'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
