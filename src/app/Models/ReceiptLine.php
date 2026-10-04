<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'un ticket (24.3) et ce qu'elle est devenue à la validation (24.4).
 *
 * @property int $id
 * @property int $receipt_id
 * @property int $position
 * @property string $label
 * @property string|null $suggested_name
 * @property string $kind
 * @property string|null $count
 * @property string|null $weight
 * @property string|null $unit_price
 * @property string $amount
 * @property string $discount
 * @property int|null $ingredient_id
 * @property string|null $pack_quantity
 * @property int|null $pack_unit_id
 * @property string $confidence
 * @property bool $doubtful
 * @property bool $to_stock
 * @property int|null $budget_category_id
 * @property int|null $stock_item_id
 * @property int|null $shopping_list_item_id
 * @property int|null $ingredient_price_id
 */
#[Fillable(['receipt_id', 'position', 'label', 'suggested_name', 'kind', 'count', 'weight', 'unit_price', 'amount', 'discount', 'ingredient_id', 'pack_quantity', 'pack_unit_id', 'confidence', 'doubtful', 'to_stock', 'budget_category_id', 'stock_item_id', 'shopping_list_item_id', 'ingredient_price_id'])]
class ReceiptLine extends Model
{
    /** Genres de ligne : les articles achetés, puis ce qui ne va jamais au stock (R27). */
    public const KINDS = [
        'article' => 'Article',
        'non_food' => 'Hors stock',
        'discount' => 'Remise',
        'deposit' => 'Consigne',
        'voucher' => 'Bon d\'achat',
        'bag' => 'Sac',
        'tax' => 'TVA',
        'ignored' => 'Ignorer',
    ];

    /** Genres comptés dans le total du ticket (la TVA y est déjà, « ignorer » n'y est pas). */
    public const COUNTED = ['article', 'non_food', 'discount', 'deposit', 'voucher', 'bag'];

    protected function casts(): array
    {
        return [
            'count' => 'decimal:3',
            'weight' => 'decimal:3',
            'unit_price' => 'decimal:3',
            'amount' => 'decimal:2',
            'discount' => 'decimal:2',
            'pack_quantity' => 'decimal:3',
            'doubtful' => 'boolean',
            'to_stock' => 'boolean',
        ];
    }

    /** @return BelongsTo<Receipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function packUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'pack_unit_id');
    }

    /** @return BelongsTo<BudgetCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class, 'budget_category_id');
    }

    /** @return BelongsTo<StockItem, $this> */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /** @return BelongsTo<ShoppingListItem, $this> */
    public function shoppingListItem(): BelongsTo
    {
        return $this->belongsTo(ShoppingListItem::class);
    }

    public function isPurchase(): bool
    {
        return in_array($this->kind, ['article', 'non_food'], true);
    }

    /** Montant payé pour la ligne, remise rattachée déduite. */
    public function paid(): float
    {
        return round((float) $this->amount + (float) $this->discount, 2);
    }
}
