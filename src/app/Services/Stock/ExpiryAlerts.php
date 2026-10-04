<?php

namespace App\Services\Stock;

use App\Enums\ExpiryLevel;
use App\Models\StockItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Produits à surveiller (module 11.4) : pour le badge du menu, l'accueil et le planning.
 */
class ExpiryAlerts
{
    public function __construct(private readonly ExpiryCalculator $expiry) {}

    /**
     * Articles actifs à surveiller, du plus urgent au moins urgent.
     *
     * @return Collection<int, array{item: StockItem, level: ExpiryLevel, badge: array, date: Carbon}>
     */
    public function items(?Carbon $today = null): Collection
    {
        $today ??= Carbon::today();

        return StockItem::query()->active()->with('ingredient', 'unit', 'location')->get()
            ->map(function (StockItem $item) use ($today) {
                $level = $this->expiry->level($item, $today);

                return $level->needsAttention() ? [
                    'item' => $item,
                    'level' => $level,
                    'badge' => $this->expiry->badge($item, $today),
                    'date' => $this->expiry->effective($item)['date'],
                ] : null;
            })
            ->filter()
            ->sortBy(fn (array $row) => [$row['level']->rank(), $row['date']->timestamp, mb_strtolower($row['item']->name())])
            ->values();
    }

    /**
     * Compteurs pour le badge du menu.
     *
     * @return array{total: int, urgent: int} urgent = DLC dépassée, aujourd'hui ou demain
     */
    public function counts(?Carbon $today = null): array
    {
        $items = $this->items($today);

        return [
            'total' => $items->count(),
            'urgent' => $items->filter(fn (array $row) => in_array($row['level'], [ExpiryLevel::Expired, ExpiryLevel::Urgent], true))->count(),
        ];
    }

    /**
     * Articles dont la date effective tombe au plus tard le $date (dates dépassées comprises), hors DDM lointaines.
     *
     * @return Collection<int, array{item: StockItem, level: ExpiryLevel, badge: array, date: Carbon}>
     */
    public function expiringBy(Carbon $date, ?Carbon $today = null): Collection
    {
        $today ??= Carbon::today();

        return StockItem::query()->active()->with('ingredient', 'unit', 'location')->get()
            ->map(function (StockItem $item) use ($today, $date) {
                $effective = $this->expiry->effective($item)['date'];

                if (! $effective || $effective->gt($date) || $item->isFrozen()) {
                    return null;
                }

                return [
                    'item' => $item,
                    'level' => $this->expiry->level($item, $today),
                    'badge' => $this->expiry->badge($item, $today),
                    'date' => $effective,
                ];
            })
            ->filter()
            ->sortBy(fn (array $row) => [$row['date']->timestamp, mb_strtolower($row['item']->name())])
            ->values();
    }
}
