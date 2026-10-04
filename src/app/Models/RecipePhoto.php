<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Photo supplémentaire d'une recette (lot 31, 31.2) : une étape (`step`, avec son numéro) ou
 * « notre version » (`ours`), prise après le repas. La photo principale reste recipes.photo_path.
 *
 * @property int $id
 * @property int $recipe_id
 * @property int|null $step_number
 * @property string $kind step · ours
 * @property string $path base du nom de fichier (RecipePhotoService)
 * @property string|null $caption
 */
#[Fillable(['recipe_id', 'step_number', 'step_uid', 'kind', 'path', 'caption', 'position', 'planned_meal_id', 'user_id'])]
class RecipePhoto extends Model
{
    public const KINDS = ['step' => 'Étape', 'ours' => 'Notre version'];

    protected function casts(): array
    {
        return ['step_number' => 'integer', 'position' => 'integer'];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class)->withoutGlobalScope(\App\Models\Scopes\HouseholdScope::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PlannedMeal, $this> */
    public function meal(): BelongsTo
    {
        return $this->belongsTo(PlannedMeal::class, 'planned_meal_id');
    }

    public function url(string $size = 'thumb'): string
    {
        return route('recipes.photos.show', ['recipe' => $this->recipe_id, 'photo' => $this->id, 'size' => $size, 'v' => $this->updated_at?->timestamp]);
    }
}
