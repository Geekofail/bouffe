<?php

namespace App\Models;

use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historique du stock. Jamais modifié : une correction ou une annulation crée un nouveau mouvement.
 *
 * @property int $id
 * @property int|null $stock_item_id
 * @property MovementType $type
 * @property string $label
 * @property string|null $quantity
 * @property array|null $snapshot
 * @property int|null $reverts_movement_id
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon $created_at
 */
#[Fillable(['stock_item_id', 'ingredient_id', 'label', 'type', 'quantity', 'unit_id', 'reason', 'snapshot', 'reverts_movement_id', 'planned_meal_id', 'shopping_list_id', 'user_id'])]
class StockMovement extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['type' => MovementType::class, 'quantity' => 'decimal:3', 'snapshot' => 'array'];
    }

    /** @return BelongsTo<StockItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    /** Gardé sur le mouvement : l'historique reste lisible même si l'article a disparu. */
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
