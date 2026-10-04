<?php

namespace App\Models;

use App\Models\Concerns\HasSortOrder;
use Database\Factories\AisleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rayon du magasin.
 *
 * @property int $id
 * @property string $name
 * @property int $sort_order
 * @property string $color
 */
#[Fillable(['name', 'sort_order', 'color'])]
class Aisle extends Model
{
    /** @use HasFactory<AisleFactory> */
    use HasFactory, HasSortOrder;

    protected $attributes = [
        'color' => 'stone',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<Ingredient, $this> */
    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }
}
