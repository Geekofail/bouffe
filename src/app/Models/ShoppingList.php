<?php

namespace App\Models;

use App\Enums\ListStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property bool $include_past
 * @property list<int>|null $excluded_meal_ids
 * @property ListStatus $status
 * @property Carbon|null $generated_at
 */
#[Fillable(['name', 'store_id', 'stay_id', 'period_start', 'period_end', 'include_past', 'excluded_meal_ids', 'deduct_stock', 'status', 'generated_at', 'created_by', 'shared_with_links'])]
class ShoppingList extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    protected $attributes = [
        'status' => 'active',
        'include_past' => false,
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'include_past' => 'boolean',
            'deduct_stock' => 'boolean',
            'excluded_meal_ids' => 'array',
            'status' => ListStatus::class,
            'generated_at' => 'datetime',
            'shared_with_links' => 'boolean',   // liste groupée ouverte aux foyers reliés (26.8)
        ];
    }

    public function setPeriodStartAttribute(mixed $value): void
    {
        $this->attributes['period_start'] = Carbon::parse($value)->toDateString();
    }

    public function setPeriodEndAttribute(mixed $value): void
    {
        $this->attributes['period_end'] = Carbon::parse($value)->toDateString();
    }

    /** @return HasMany<ShoppingListItem, $this> */
    /** Qui a préparé la liste (19.1 : « Monique a préparé la liste »). @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Tickets rattachés à la liste (lot 22, R26). @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class);
    }

    /** Magasin dont l'ordre des rayons est utilisé pour afficher la liste (15.3). */
    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Store, $this> */
    public function store(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** Listes en cours de la maison : la liste d'un séjour (lot 34) n'en fait pas partie. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ListStatus::Active->value)->whereNull('stay_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Stay, $this> */
    public function stay(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function isDone(): bool
    {
        return $this->status === ListStatus::Done;
    }

    public function periodLabel(): string
    {
        $start = $this->period_start->locale('fr');
        $end = $this->period_end->locale('fr');

        return $start->isSameDay($end)
            ? $start->isoFormat('dddd D MMMM')
            : 'du '.$start->isoFormat('ddd D MMM').' au '.$end->isoFormat('ddd D MMM YYYY');
    }
}
