<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Surplus à donner (26.7) : « 1 kg de courgettes avant jeudi ». Visible des foyers reliés, réservé
 * en un geste ; le stock du donneur n'est retiré qu'à la remise.
 *
 * @property int $id
 * @property int $household_id
 * @property int|null $stock_item_id
 * @property string $label
 * @property string|null $quantity
 * @property Carbon $available_until
 * @property string|null $note
 * @property int|null $reserved_by_household_id
 * @property Carbon|null $reserved_at
 * @property Carbon|null $handed_at
 * @property Carbon|null $cancelled_at
 */
#[Fillable(['stock_item_id', 'label', 'quantity', 'available_until', 'note', 'created_by', 'reserved_by_household_id', 'reserved_by_user_id', 'reserved_at', 'handed_at', 'cancelled_at'])]
class SurplusOffer extends Model
{
    use Concerns\BelongsToHousehold;

    protected function casts(): array
    {
        return ['available_until' => 'date', 'reserved_at' => 'datetime', 'handed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /** Annonces encore en cours : ni remises, ni retirées, pas dépassées. */
    public function scopeOpen(Builder $query, ?Carbon $today = null): Builder
    {
        return $query->whereNull('handed_at')->whereNull('cancelled_at')
            ->whereDate('available_until', '>=', ($today ?? Carbon::today())->toDateString());
    }

    /** @return BelongsTo<StockItem, $this> */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class)->withoutGlobalScope(Scopes\HouseholdScope::class);
    }

    /** @return BelongsTo<Household, $this> */
    public function reservedBy(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'reserved_by_household_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reservedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reserved_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function status(): string
    {
        return match (true) {
            $this->cancelled_at !== null => 'cancelled',
            $this->handed_at !== null => 'handed',
            $this->reserved_at !== null => 'reserved',
            $this->available_until->lt(Carbon::today()) => 'expired',
            default => 'open',
        };
    }
}
