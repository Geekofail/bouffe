<?php

namespace App\Models;

use App\Enums\ItemOrigin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $shopping_list_id
 * @property int|null $ingredient_id
 * @property string $label
 * @property int|null $aisle_id
 * @property string|null $quantity
 * @property int|null $unit_id
 * @property list<array{q: float, unit_id: int|null}>|null $extra_quantities
 * @property ItemOrigin $origin
 * @property bool $is_optional
 * @property bool $is_checked
 * @property bool $is_removed
 * @property bool $quantity_overridden
 * @property-read Ingredient|null $ingredient
 * @property-read Aisle|null $aisle
 * @property-read Unit|null $unit
 */
#[Fillable([
    'for_household_id', 'ingredient_id', 'label', 'aisle_id', 'store_id', 'quantity', 'unit_id', 'extra_quantities', 'origin', 'is_optional',
    'is_checked', 'checked_by', 'checked_at', 'paid_price', 'is_removed', 'quantity_overridden', 'stocked_at', 'stock_status', 'stock_deducted', 'stock_note', 'buy_anyway', 'added_quantity', 'added_unit_id',
])]
class ShoppingListItem extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    protected $attributes = [
        'is_optional' => false,
        'is_checked' => false,
        'is_removed' => false,
        'quantity_overridden' => false,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'extra_quantities' => 'array',
            'origin' => ItemOrigin::class,
            'is_optional' => 'boolean',
            'is_checked' => 'boolean',
            'is_removed' => 'boolean',
            'quantity_overridden' => 'boolean',
            'checked_at' => 'datetime',
            'paid_price' => 'decimal:2',
            'for_household_id' => 'integer',     // article pour un foyer relié (26.8)
            'stocked_at' => 'datetime',
            'stock_deducted' => 'decimal:3',
            'added_quantity' => 'decimal:3',
            'buy_anyway' => 'boolean',
        ];
    }

    /** @return BelongsTo<ShoppingList, $this> */
    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
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

    /** Magasin imposé pour cet article : « seulement au marché » (15.3). */
    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    /** @return HasMany<ShoppingListItemSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(ShoppingListItemSource::class)->orderBy('meal_date');
    }

    /** Foyer relié pour qui l'article est acheté (26.8). @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Household, $this> */
    public function forHousehold(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Household::class, 'for_household_id');
    }
}
