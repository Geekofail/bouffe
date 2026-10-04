<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Courses à deux en magasin (lot 42, 42.3) : qui prend quel rayon de la liste. Le rayon est celui
 * du mode magasin (0 : autres, -1 : placard, -2 : ajoutés en magasin).
 *
 * @property int $id
 * @property int $shopping_list_id
 * @property int $aisle_id
 * @property int $user_id
 */
#[Fillable(['shopping_list_id', 'aisle_id', 'user_id'])]
class ShoppingListAisleOwner extends Model
{
    protected function casts(): array
    {
        return ['aisle_id' => 'integer', 'user_id' => 'integer'];
    }

    /** @return BelongsTo<ShoppingList, $this> */
    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
