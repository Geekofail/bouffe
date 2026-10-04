<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Note du foyer sur une étape (lot 40, 40.2) : « notre four chauffe fort : 170 °C ». Rattachée à
 * l'étape par son identifiant stable : elle reste quand la recette est modifiée.
 *
 * @property int $id
 * @property int $recipe_id
 * @property string $step_uid
 * @property string $note
 * @property int|null $user_id
 */
#[Fillable(['recipe_id', 'step_uid', 'note', 'user_id'])]
class RecipeStepNote extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

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
