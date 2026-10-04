<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Catégorie de recette (Plat, Dessert, Végétarien, Rapide…).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $color
 */
#[Fillable(['name', 'slug', 'color'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use \App\Models\Concerns\BelongsToHousehold, HasFactory;

    protected $attributes = [
        'color' => 'stone',
    ];

    protected static function booted(): void
    {
        static::saving(function (Tag $tag) {
            $tag->slug = Str::slug($tag->name);
        });
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<Recipe, $this> */
    public function recipes(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Recipe::class);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('name');
    }
}
