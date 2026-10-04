<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Une dépense (lot 22, 23.1) : un ticket de courses, un restaurant, un abonnement.
 *
 * Sa répartition entre postes est dans `splits` (au moins une ligne ; plusieurs pour un ticket mixte).
 *
 * @property int $id
 * @property Carbon $spent_on
 * @property string $amount
 * @property int $budget_category_id
 * @property int|null $store_id
 * @property string|null $place
 * @property int|null $persons
 * @property int|null $paid_by
 * @property int|null $shopping_list_id
 * @property int|null $planned_meal_id
 * @property int|null $recurring_expense_id
 * @property string|null $note
 * @property string $source
 * @property-read BudgetCategory $category
 * @property-read Store|null $store
 * @property-read User|null $payer
 */
#[Fillable(['spent_on', 'amount', 'budget_category_id', 'store_id', 'place', 'persons', 'paid_by', 'shopping_list_id', 'planned_meal_id', 'recurring_expense_id', 'receipt_id', 'note', 'source', 'created_by'])]
class Expense extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    protected function casts(): array
    {
        return ['spent_on' => 'date', 'amount' => 'decimal:2', 'persons' => 'integer'];
    }

    public function setSpentOnAttribute(mixed $value): void
    {
        $this->attributes['spent_on'] = Carbon::parse($value)->toDateString();
    }

    /** @return BelongsTo<BudgetCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class, 'budget_category_id');
    }

    /** @return HasMany<ExpenseSplit, $this> */
    public function splits(): HasMany
    {
        return $this->hasMany(ExpenseSplit::class);
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<User, $this> */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /** @return BelongsTo<ShoppingList, $this> */
    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    /** @return BelongsTo<PlannedMeal, $this> */
    public function plannedMeal(): BelongsTo
    {
        return $this->belongsTo(PlannedMeal::class);
    }

    /** « Cactus », « Chez Mario », ou le poste à défaut. */
    public function placeLabel(): string
    {
        return $this->store?->name ?? $this->place ?? $this->category?->name ?? 'Dépense';
    }

    public function isSplit(): bool
    {
        return $this->splits->count() > 1;
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Receipt, $this> */
    public function receipt(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }
}
