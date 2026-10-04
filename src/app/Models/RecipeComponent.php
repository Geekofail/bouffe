<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sous-recette utilisée par une recette (13.8) : « 1 × Pâte brisée », « ½ × Béchamel ».
 *
 * `quantity` est un multiple de la sous-recette telle qu'elle est écrite (pour ses propres portions).
 *
 * @property int $id
 * @property int $recipe_id
 * @property int $component_recipe_id
 * @property string $quantity
 * @property string|null $note
 * @property int $sort_order
 * @property-read Recipe $recipe
 * @property-read Recipe $component
 */
#[Fillable(['recipe_id', 'component_recipe_id', 'quantity', 'note', 'sort_order'])]
class RecipeComponent extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return BelongsTo<Recipe, $this> */
    public function component(): BelongsTo
    {
        // Même foyer que la recette qui l'utilise ; lue sans filtre pour une recette partagée (lot 26).
        return $this->belongsTo(Recipe::class, 'component_recipe_id')->withoutGlobalScope(Scopes\HouseholdScope::class);
    }

    public function factor(): float
    {
        return max(0.0, (float) $this->quantity);
    }

    /** « 1 × », « ½ × », « 2 × » */
    public function factorLabel(): string
    {
        $factor = $this->factor();

        return match (true) {
            abs($factor - 0.5) < 0.001 => '½',
            abs($factor - 0.25) < 0.001 => '¼',
            abs($factor - 0.75) < 0.001 => '¾',
            abs($factor - 1 / 3) < 0.01 => '⅓',
            abs($factor - 2 / 3) < 0.01 => '⅔',
            default => app(\App\Services\QuantityFormatter::class)->number($factor),
        };
    }
}
