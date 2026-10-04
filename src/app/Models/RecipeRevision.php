<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une version d'une recette (lot 31, 31.5) : son contenu complet après une modification.
 *
 * @property int $id
 * @property int $recipe_id
 * @property int|null $user_id
 * @property string $action origin · create · edit · restore
 * @property string|null $summary
 * @property array<string, mixed> $snapshot
 * @property \Illuminate\Support\Carbon|null $created_at
 */
#[Fillable(['recipe_id', 'user_id', 'action', 'summary', 'snapshot', 'created_at'])]
class RecipeRevision extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'datetime'];
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
