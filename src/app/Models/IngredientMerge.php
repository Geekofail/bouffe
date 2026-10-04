<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fusion d'un ingrédient dans un autre (R22), conservée pour pouvoir l'annuler.
 *
 * @property int $id
 * @property int $source_id
 * @property string $source_name
 * @property int $target_id
 * @property array $payload
 * @property \Illuminate\Support\Carbon|null $undone_at
 */
#[Fillable(['source_id', 'source_name', 'target_id', 'payload', 'user_id', 'undone_at'])]
class IngredientMerge extends Model
{
    protected function casts(): array
    {
        return ['payload' => 'array', 'undone_at' => 'datetime'];
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'target_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
