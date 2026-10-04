<?php

namespace App\Services\Stock;

use App\Enums\LocationType;
use App\Models\StockItem;
use App\Models\StorageLocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Le congélateur, rangé (16.4).
 *
 * Un congélateur bien tenu n'a pas besoin d'inventaire : il a besoin qu'on sache **ce qui y est
 * depuis le plus longtemps**. Cette vue trie les plats maison par ancienneté et propose de
 * planifier les plus vieux, avant qu'ils ne deviennent un problème.
 *
 * Les seuils sont volontairement doux : un plat de 4 mois n'est pas dangereux, il est juste
 * moins bon. On parle donc d'« à manger en premier », pas d'alerte.
 */
class FreezerBoard
{
    /** Au-delà, un plat maison est signalé comme vieux (mois). */
    public const OLD_MONTHS = 3;

    public const VERY_OLD_MONTHS = 6;

    /** @return Collection<int, StorageLocation> */
    public function locations(): Collection
    {
        return StorageLocation::query()->where('type', LocationType::Freezer->value)->ordered()->get();
    }

    /**
     * Les plats maison du congélateur, du plus ancien au plus récent.
     *
     * « Plat maison » = ce qui a été congelé sans être un produit du commerce : un article
     * nommé à la main (« Chili »), ou issu d'un repas cuisiné (lot 12).
     *
     * @return Collection<int, array{item: StockItem, days: int, months: int, age: string, level: string}>
     */
    public function homemade(?Carbon $today = null): Collection
    {
        $today ??= Carbon::today();
        $locationIds = $this->locations()->pluck('id');

        if ($locationIds->isEmpty()) {
            return collect();
        }

        return StockItem::query()
            ->active()
            ->whereIn('storage_location_id', $locationIds)
            ->where(fn ($query) => $query->whereNotNull('label')->orWhereNotNull('planned_meal_id'))
            ->with(['location', 'unit', 'plannedMeal.recipe'])
            ->get()
            ->map(fn (StockItem $item) => $this->describe($item, $today))
            ->sortByDesc('days')
            ->values();
    }

    /**
     * Tout le contenu du congélateur (plats maison **et** produits), du plus ancien au plus récent.
     *
     * @return Collection<int, array{item: StockItem, days: int, months: int, age: string, level: string}>
     */
    public function all(?Carbon $today = null): Collection
    {
        $today ??= Carbon::today();
        $locationIds = $this->locations()->pluck('id');

        if ($locationIds->isEmpty()) {
            return collect();
        }

        return StockItem::query()
            ->active()
            ->whereIn('storage_location_id', $locationIds)
            ->with(['ingredient', 'location', 'unit', 'plannedMeal.recipe'])
            ->get()
            ->map(fn (StockItem $item) => $this->describe($item, $today))
            ->sortByDesc('days')
            ->values();
    }

    /** Les plats à manger en premier. */
    public function toEatFirst(int $limit = 3, ?Carbon $today = null): Collection
    {
        return $this->homemade($today)->take($limit);
    }

    /** Nombre de portions dormant au congélateur : « 3 repas d'avance ». */
    public function servingsInStore(?Carbon $today = null): float
    {
        return (float) $this->homemade($today)->sum(fn (array $row) => (float) ($row['item']->servings ?? 0));
    }

    /**
     * @return array{item: StockItem, days: int, months: int, age: string, level: string}
     */
    public function describe(StockItem $item, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $since = $item->frozen_on ?? $item->created_at->copy()->startOfDay();
        $days = max(0, (int) round($since->diffInDays($today)));
        $months = intdiv($days, 30);

        return [
            'item' => $item,
            'days' => $days,
            'months' => $months,
            'age' => $this->ageLabel($days),
            'level' => match (true) {
                $months >= self::VERY_OLD_MONTHS => 'old',
                $months >= self::OLD_MONTHS => 'ageing',
                default => 'fresh',
            },
        ];
    }

    /** « congelé hier », « il y a 3 semaines », « il y a 4 mois ». */
    public function ageLabel(int $days): string
    {
        return match (true) {
            $days <= 0 => "aujourd'hui",
            $days === 1 => 'hier',
            $days < 14 => "il y a {$days} jours",
            $days < 60 => 'il y a '.intdiv($days, 7).' semaines',
            default => 'il y a '.intdiv($days, 30).' mois',
        };
    }
}
