<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Budget mensuel d'un poste, à partir d'une date (23.7) : changer de budget ne réécrit pas le passé.
 *
 * @property int $id
 * @property int $budget_category_id
 * @property string $amount
 * @property Carbon $valid_from
 */
#[Fillable(['budget_category_id', 'amount', 'valid_from'])]
class BudgetAmount extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'valid_from' => 'date'];
    }

    public function setValidFromAttribute(mixed $value): void
    {
        $this->attributes['valid_from'] = Carbon::parse($value)->toDateString();
    }

    /** @return BelongsTo<BudgetCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class, 'budget_category_id');
    }
}
