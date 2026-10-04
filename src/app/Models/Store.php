<?php

namespace App\Models;

use App\Models\Concerns\HasSortOrder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un magasin (15.3) : Cactus, Delhaize, le marché…
 *
 * L'intérêt n'est pas de savoir où on achète, mais de ranger la liste **dans l'ordre où on
 * traverse ce magasin-là** : les rayons ne sont pas dans le même ordre chez Cactus et au marché.
 *
 * @property int $id
 * @property string $name
 * @property string $color
 * @property string|null $note
 * @property bool $is_default
 * @property int $sort_order
 */
#[Fillable(['name', 'color', 'note', 'is_default', 'sort_order'])]
class Store extends Model
{
    use \App\Models\Concerns\BelongsToHousehold, HasSortOrder;

    protected $attributes = [
        'color' => 'stone',
        'is_default' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<StoreAisle, $this> */
    public function storeAisles(): HasMany
    {
        return $this->hasMany(StoreAisle::class);
    }

    /** @return BelongsToMany<Aisle, $this> */
    public function aisles(): BelongsToMany
    {
        return $this->belongsToMany(Aisle::class, 'store_aisles')
            ->withPivot(['position', 'is_hidden'])
            ->orderBy('store_aisles.position');
    }

    /** @return HasMany<ShoppingList, $this> */
    public function shoppingLists(): HasMany
    {
        return $this->hasMany(ShoppingList::class);
    }

    /** Magasin proposé par défaut : celui marqué comme tel, sinon le premier. */
    public static function preferred(): ?self
    {
        return static::query()->orderByDesc('is_default')->ordered()->first();
    }
}
