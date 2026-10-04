<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Liste « quand je passe » (15.8) : ce qui n'est pas lié à une semaine — piles, ampoules,
 * un cadeau — et qui doit se retrouver dans la prochaine liste de courses créée.
 *
 * Une fois versé dans une liste, l'article reste ici avec la trace de la liste qui l'a pris :
 * il n'est plus proposé, mais on voit ce qui a été fait.
 *
 * @property string $label
 * @property int|null $ingredient_id
 * @property int|null $aisle_id
 * @property int|null $store_id
 * @property string|null $note
 * @property int|null $added_to_list_id
 */
#[Fillable(['label', 'ingredient_id', 'aisle_id', 'store_id', 'note', 'created_by', 'added_to_list_id', 'added_at'])]
class StandingItem extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    protected function casts(): array
    {
        return ['added_at' => 'datetime'];
    }

    /** Articles encore à prendre. */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query->whereNull('added_to_list_id');
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

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<ShoppingList, $this> */
    public function addedToList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class, 'added_to_list_id');
    }
}
