<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Note d'une personne sur une recette (1 à 5 étoiles + commentaire).
 *
 * @property int $id
 * @property int $recipe_id
 * @property int $user_id
 * @property int $rating
 * @property string|null $comment
 */
#[Fillable(['user_id', 'household_id', 'rating', 'comment'])]
class RecipeRating extends Model
{
    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class)->withoutGlobalScope(Scopes\HouseholdScope::class);
    }

    /** Foyer de la personne au moment de l'avis (26.3 : avis des proches). @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
