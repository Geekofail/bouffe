<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Article ajouté automatiquement à chaque nouvelle liste de courses (lait, pain, café…).
 *
 * @property int $id
 * @property string $label
 * @property int|null $ingredient_id
 * @property int|null $aisle_id
 * @property bool $is_active
 */
#[Fillable(['label', 'ingredient_id', 'aisle_id', 'is_active'])]
class RecurringItem extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** @return BelongsTo<Aisle, $this> */
    public function aisle(): BelongsTo
    {
        return $this->belongsTo(Aisle::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
