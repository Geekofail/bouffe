<?php

namespace App\Models;

use App\Models\Concerns\HasSortOrder;
use Database\Factories\MealSlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Créneau de repas (Petit-déjeuner, Déjeuner, Goûter, Dîner).
 *
 * @property int $id
 * @property string $name
 * @property int $sort_order
 * @property bool $is_active
 */
#[Fillable(['name', 'sort_order', 'is_active'])]
class MealSlot extends Model
{
    /** @use HasFactory<MealSlotFactory> */
    use \App\Models\Concerns\BelongsToHousehold, HasFactory, HasSortOrder;

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<PlannedMeal, $this> */
    public function plannedMeals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PlannedMeal::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
