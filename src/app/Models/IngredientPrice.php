<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un prix relevé pour un ingrédient (15.7, règle R17).
 *
 * `unit_price` est le prix ramené à l'unité de base (€ par g, par ml ou par pièce) : c'est lui
 * qui sert aux calculs, quelle que soit l'unité dans laquelle le prix a été saisi.
 *
 * @property int $ingredient_id
 * @property int|null $store_id
 * @property string $price
 * @property string|null $quantity
 * @property int|null $unit_id
 * @property string|null $unit_price
 * @property Carbon $observed_on
 * @property string $source
 * @property bool $is_promo prix en promotion (lot 30, R35)
 */
#[Fillable(['ingredient_id', 'store_id', 'price', 'quantity', 'unit_id', 'unit_price', 'observed_on', 'source', 'is_promo', 'created_by'])]
class IngredientPrice extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    public const MANUAL = 'manual';

    public const SHOPPING = 'shopping';   // relevé en cochant un article de la liste

    public const RECEIPT = 'receipt';     // lu sur un ticket de caisse (lot 23)

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:6',
            'observed_on' => 'date',
            'is_promo' => 'boolean',
        ];
    }

    /** Date stockée sans heure, quel que soit le moteur de base (un prix relevé aujourd'hui compte aujourd'hui). */
    public function setObservedOnAttribute(mixed $value): void
    {
        $this->attributes['observed_on'] = $value ? Carbon::parse($value)->toDateString() : null;
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

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
}
