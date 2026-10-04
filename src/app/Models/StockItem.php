<?php

namespace App\Models;

use App\Enums\ExpiryType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Article en stock : un paquet, un lot de légumes, un plat préparé.
 *
 * @property int $id
 * @property int|null $ingredient_id
 * @property string|null $label
 * @property string|null $quantity
 * @property string|null $initial_quantity
 * @property int|null $unit_id
 * @property bool $is_present
 * @property int $storage_location_id
 * @property Carbon|null $expires_on
 * @property ExpiryType $expiry_type
 * @property Carbon|null $opened_on
 * @property Carbon|null $frozen_on
 * @property Carbon|null $thawed_on
 * @property string|null $note
 * @property Carbon|null $finished_at
 * @property-read Ingredient|null $ingredient
 * @property-read Unit|null $unit
 * @property-read StorageLocation $location
 */
#[Fillable([
    'ingredient_id', 'product_id', 'label', 'planned_meal_id', 'quantity', 'initial_quantity', 'servings', 'unit_id', 'is_present', 'storage_location_id',
    'expires_on', 'expiry_type', 'opened_on', 'frozen_on', 'thawed_on', 'shopping_list_item_id', 'note', 'finished_at', 'checked_at', 'created_by',
])]
class StockItem extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'initial_quantity' => 'decimal:3',
            'servings' => 'float',
            'is_present' => 'boolean',
            'expires_on' => 'date',
            'expiry_type' => ExpiryType::class,
            'opened_on' => 'date',
            'frozen_on' => 'date',
            'thawed_on' => 'date',
            'finished_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    /** Dates stockées sans heure, quel que soit le moteur de base. */
    public function setExpiresOnAttribute(mixed $value): void
    {
        $this->attributes['expires_on'] = $value ? Carbon::parse($value)->toDateString() : null;
    }

    public function setOpenedOnAttribute(mixed $value): void
    {
        $this->attributes['opened_on'] = $value ? Carbon::parse($value)->toDateString() : null;
    }

    public function setFrozenOnAttribute(mixed $value): void
    {
        $this->attributes['frozen_on'] = $value ? Carbon::parse($value)->toDateString() : null;
    }

    public function setThawedOnAttribute(mixed $value): void
    {
        $this->attributes['thawed_on'] = $value ? Carbon::parse($value)->toDateString() : null;
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** Repas cuisiné dont ce plat est issu (lot 12, batch cooking). */
    /** @return BelongsTo<PlannedMeal, $this> */
    public function plannedMeal(): BelongsTo
    {
        return $this->belongsTo(PlannedMeal::class);
    }

    /** Produit scanné d'où vient cet article (16.1). */
    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<StorageLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class, 'storage_location_id');
    }

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('finished_at');
    }

    public function isPrepared(): bool
    {
        return $this->ingredient_id === null;
    }

    public function isFrozen(): bool
    {
        return $this->frozen_on !== null && $this->thawed_on === null;
    }

    public function name(): string
    {
        return $this->ingredient?->name ?? (string) $this->label;
    }
}
