<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lien de lecture d'une recette (lot 31, 31.4) pour quelqu'un qui n'a pas Bouffe : 30 jours,
 * révocable. Seule l'empreinte du jeton est gardée : l'adresse complète n'est montrée qu'une fois.
 *
 * @property int $id
 * @property int $recipe_id
 * @property string $token_hash
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property int $views
 */
#[Fillable(['recipe_id', 'token_hash', 'expires_at', 'revoked_at', 'views', 'last_viewed_at', 'created_by'])]
class RecipeShareLink extends Model
{
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_viewed_at' => 'datetime', 'views' => 'integer'];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class)->withoutGlobalScope(\App\Models\Scopes\HouseholdScope::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
