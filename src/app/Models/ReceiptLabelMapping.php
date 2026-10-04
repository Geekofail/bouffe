<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Correspondance apprise entre un libellé de ticket et un ingrédient (R28, 24.5).
 *
 * @property int $id
 * @property int|null $store_id
 * @property string $normalized_label
 * @property string $kind
 * @property int|null $ingredient_id
 * @property string|null $pack_quantity
 * @property int|null $pack_unit_id
 * @property int|null $budget_category_id
 * @property int $confirmations
 */
#[Fillable(['store_id', 'normalized_label', 'kind', 'ingredient_id', 'pack_quantity', 'pack_unit_id', 'budget_category_id', 'confirmations'])]
class ReceiptLabelMapping extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    protected function casts(): array
    {
        return ['pack_quantity' => 'decimal:3', 'confirmations' => 'integer'];
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
}
