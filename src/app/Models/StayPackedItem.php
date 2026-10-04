<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ce qui part de la maison au séjour (34.4) : retiré du stock au départ, le reste remis au retour.
 *
 * @property int $id
 * @property int|null $stock_item_id
 * @property int|null $ingredient_id
 * @property string $label
 * @property string|null $quantity vide = tout l'article
 * @property int|null $unit_id
 * @property Carbon|null $taken_at
 * @property string|null $returned_quantity
 * @property Carbon|null $returned_at
 */
#[Fillable(['household_id', 'stock_item_id', 'ingredient_id', 'label', 'quantity', 'unit_id', 'storage_location_id', 'taken_at', 'returned_quantity', 'returned_at'])]
class StayPackedItem extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'returned_quantity' => 'decimal:3', 'taken_at' => 'datetime', 'returned_at' => 'datetime'];
    }

    /** @return BelongsTo<Stay, $this> */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    /** @return BelongsTo<StockItem, $this> */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
