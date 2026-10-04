<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part d'une dépense affectée à un poste (ticket mixte, 23.3).
 *
 * @property int $id
 * @property int $expense_id
 * @property int $budget_category_id
 * @property string $amount
 */
#[Fillable(['expense_id', 'budget_category_id', 'amount'])]
class ExpenseSplit extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /** @return BelongsTo<BudgetCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class, 'budget_category_id');
    }
}
