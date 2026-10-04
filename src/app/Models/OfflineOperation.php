<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Action faite sans réseau sur la liste de courses, rejouée au retour (règle R20).
 *
 * @property int $id
 * @property string $uuid
 * @property int $shopping_list_id
 * @property int|null $shopping_list_item_id
 * @property string $action
 * @property string|null $value
 * @property Carbon $happened_at
 * @property Carbon|null $applied_at
 * @property string|null $result
 * @property string|null $detail
 */
#[Fillable([
    'uuid', 'user_id', 'shopping_list_id', 'shopping_list_item_id',
    'action', 'value', 'happened_at', 'applied_at', 'result', 'detail',
])]
class OfflineOperation extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    public const APPLIED = 'applied';

    public const IGNORED = 'ignored';

    public const MOVED = 'moved';       // liste régénérée : reporté sur l'article du même ingrédient

    protected function casts(): array
    {
        return [
            'happened_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ShoppingList, $this> */
    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }
}
