<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Consommation régulière hors repas (lot 21, 22.4) : « 1 l de lait tous les 3 jours ».
 *
 * @property int $id
 * @property int $ingredient_id
 * @property string $quantity
 * @property int|null $unit_id
 * @property int $every_days
 * @property Carbon|null $last_applied_on
 * @property bool $is_active
 */
#[Fillable(['ingredient_id', 'quantity', 'unit_id', 'every_days', 'last_applied_on', 'is_active'])]
class StockUsageRule extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'every_days' => 'integer',
            'last_applied_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function setLastAppliedOnAttribute(mixed $value): void
    {
        $this->attributes['last_applied_on'] = $value ? Carbon::parse($value)->toDateString() : null;
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
