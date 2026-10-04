<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Envie « à planifier bientôt » (14.5) : une recette du carnet ou une simple idée écrite
 * (« raclette », « tester le curry de Julie »). Le remplissage automatique les place en priorité.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $recipe_id
 * @property string|null $text
 * @property int|null $planned_meal_id
 * @property Carbon|null $planned_at
 */
#[Fillable(['user_id', 'recipe_id', 'text', 'planned_meal_id', 'planned_at'])]
class Wish extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    protected function casts(): array
    {
        return ['planned_at' => 'datetime'];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PlannedMeal, $this> */
    public function plannedMeal(): BelongsTo
    {
        return $this->belongsTo(PlannedMeal::class, 'planned_meal_id');
    }

    /** Envies encore à placer. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('planned_at');
    }

    public function label(): string
    {
        return $this->recipe?->title ?? (string) $this->text;
    }

    public function isRecipe(): bool
    {
        return $this->recipe_id !== null && $this->recipe !== null;
    }
}
