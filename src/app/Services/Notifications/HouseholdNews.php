<?php

namespace App\Services\Notifications;

use App\Enums\ListStatus;
use App\Enums\LocationType;
use App\Models\PlannedMeal;
use App\Models\ShoppingList;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Ce qui s'est passé dans la maison et mérite un signe (19.1, complété au lot 20) :
 * une liste de courses préparée par quelqu'un d'autre, des restes au réfrigérateur sans repas prévu.
 */
class HouseholdNews
{
    /** Une liste préparée depuis moins de 2 jours est « nouvelle ». */
    public const READY_LIST_DAYS = 2;

    /**
     * Listes préparées par un autre membre du foyer.
     *
     * @return Collection<int, ShoppingList>
     */
    public function readyLists(User $user, ?Carbon $now = null): Collection
    {
        $now ??= now();

        return ShoppingList::query()
            ->where('status', ListStatus::Active->value)
            ->whereNotNull('created_by')->where('created_by', '!=', $user->id)
            ->where('created_at', '>=', $now->copy()->subDays(self::READY_LIST_DAYS))
            ->withCount(['items' => fn ($q) => $q->where('is_removed', false)->where('is_checked', false)])
            ->with('creator')
            ->latest('id')->get()
            ->filter(fn (ShoppingList $list) => $list->items_count > 0)
            ->values();
    }

    /**
     * Restes rangés au réfrigérateur dont aucun repas « restes » n'est prévu (hors congélateur).
     *
     * @return Collection<int, StockItem>
     */
    public function leftoversToPlan(?Carbon $today = null): Collection
    {
        $today ??= Carbon::today();

        return StockItem::query()->active()
            ->whereNull('ingredient_id')->whereNotNull('planned_meal_id')
            ->whereHas('location', fn ($q) => $q->where('type', '!=', LocationType::Freezer->value))
            ->whereHas('plannedMeal', fn ($q) => $q->whereNotNull('cooked_at'))
            ->with('plannedMeal', 'location')
            ->get()
            ->reject(fn (StockItem $item) => PlannedMeal::query()
                ->where('leftover_of_id', $item->planned_meal_id)
                ->whereDate('date', '>=', $today->toDateString())
                ->whereNull('cooked_at')
                ->exists())
            ->values();
    }
}
