<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Place d'un rayon dans un magasin donné (15.3).
 *
 * @property int $store_id
 * @property int $aisle_id
 * @property int $position
 * @property bool $is_hidden
 */
#[Fillable(['store_id', 'aisle_id', 'position', 'is_hidden'])]
class StoreAisle extends Model
{
    protected $attributes = ['is_hidden' => false];

    protected function casts(): array
    {
        return ['position' => 'integer', 'is_hidden' => 'boolean'];
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<Aisle, $this> */
    public function aisle(): BelongsTo
    {
        return $this->belongsTo(Aisle::class);
    }
}
