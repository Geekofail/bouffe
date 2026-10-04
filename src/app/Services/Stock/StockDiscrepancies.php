<?php

namespace App\Services\Stock;

use App\Enums\LocationType;
use App\Enums\StockMode;
use App\Enums\UnitType;
use App\Models\StockItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Écarts signalés (lot 21 — 22.5).
 *
 * Plutôt qu'un inventaire complet, Bouffe propose de vérifier les quelques articles dont la
 * fiche a probablement dérivé de la réalité : une date dépassée depuis longtemps, un fond de
 * paquet jamais terminé, un produit frais qui n'a pas bougé depuis des semaines.
 * Un article vérifié n'est pas reproposé avant deux semaines.
 */
class StockDiscrepancies
{
    public const LIMIT = 5;

    public const EXPIRED_DAYS = 7;

    public const FRESH_IDLE_DAYS = 21;

    public const REMNANT_RATIO = 0.05;

    public const RECHECK_DAYS = 14;

    public function __construct(private readonly ExpiryCalculator $expiry) {}

    /**
     * @return Collection<int, array{item: StockItem, reason: string, rank: int}>
     */
    public function toCheck(?Carbon $today = null, int $limit = self::LIMIT): Collection
    {
        $today ??= Carbon::today();

        return StockItem::query()->active()
            ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', $today->copy()->subDays(self::RECHECK_DAYS)))
            ->with('ingredient', 'unit', 'location', 'movements')
            ->get()
            ->reject(fn (StockItem $item) => $item->isFrozen() || $item->ingredient?->stock_mode === StockMode::Presence)
            ->map(fn (StockItem $item) => $this->reasonFor($item, $today))
            ->filter()
            ->sortBy('rank')
            ->take($limit)
            ->values();
    }

    /** @return array{item: StockItem, reason: string, rank: int}|null */
    private function reasonFor(StockItem $item, Carbon $today): ?array
    {
        $date = $this->expiry->effective($item)['date'];

        if ($date && $date->copy()->startOfDay()->lt($today->copy()->subDays(self::EXPIRED_DAYS))) {
            $days = (int) $date->copy()->startOfDay()->diffInDays($today);

            return ['item' => $item, 'reason' => 'Date dépassée depuis '.$days.' jours : toujours là ?', 'rank' => 1];
        }

        $initial = (float) ($item->initial_quantity ?? 0);

        if ($item->quantity !== null && $initial > 0) {
            $left = (float) $item->quantity;
            $isRemnant = $item->unit?->type === UnitType::Piece ? $left < 1 : $left / $initial < self::REMNANT_RATIO;

            if ($isRemnant) {
                return ['item' => $item, 'reason' => 'Il ne reste presque rien : terminé ?', 'rank' => 2];
            }
        }

        $lastMove = $item->movements->max('created_at') ?? $item->created_at;

        if ($item->location?->type === LocationType::Fresh && ! $date && $lastMove && Carbon::parse($lastMove)->lt($today->copy()->subDays(self::FRESH_IDLE_DAYS))) {
            return ['item' => $item, 'reason' => 'Au frais depuis plus de trois semaines sans mouvement', 'rank' => 3];
        }

        return null;
    }

    public function markChecked(StockItem $item): void
    {
        $item->forceFill(['checked_at' => now()])->save();
    }
}
