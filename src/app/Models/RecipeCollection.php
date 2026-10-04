<?php

namespace App\Models;

use App\Models\Scopes\HouseholdScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Collection de recettes (lot 31, 31.1) : un regroupement libre, en plus des catégories
 * (« Noël », « Recettes de mamie », « Quand Léo vient »). Une recette peut être dans plusieurs.
 *
 * @property int $id
 * @property int $household_id
 * @property string $name
 * @property string|null $description
 * @property string $visibility private · linked
 */
#[Fillable(['name', 'description', 'visibility', 'created_by'])]
class RecipeCollection extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    protected $table = 'collections';

    public const VISIBILITIES = [
        'private' => 'Notre foyer',
        'linked' => 'Foyers reliés',
    ];

    protected $attributes = ['visibility' => 'private'];

    /** @return BelongsToMany<Recipe, $this> */
    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'collection_recipe', 'collection_id', 'recipe_id')
            ->withoutGlobalScope(HouseholdScope::class)
            ->withPivot('position', 'created_at')
            ->orderByPivot('position')
            ->orderBy('recipes.title');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isShared(): bool
    {
        return $this->visibility === 'linked';
    }

    /** Collection d'un autre foyer que le foyer actif (partagée par un foyer relié). */
    public function isForeign(): bool
    {
        $current = \App\Support\CurrentHousehold::id();

        return $current !== null && (int) $this->household_id !== $current;
    }

    /** Sa propre collection, ou celle d'un foyer relié qui l'a partagée. */
    public function resolveRouteBinding($value, $field = null)
    {
        if (! ctype_digit((string) $value)) {
            return null;
        }

        return static::query()->whereKey((int) $value)->first()
            ?? app(\App\Services\Recipes\Collections::class)->sharedWithMe()->whereKey((int) $value)->first();
    }
}
