<?php

namespace App\Services\Stock;

use App\Models\StockItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Inventaire par ancienneté (30.8) : « 12 articles n'ont pas bougé depuis 2 mois ».
 *
 * Un article « bouge » quand il est ajouté, ouvert, entamé, déplacé, vérifié… Ceux qui n'ont rien
 * connu de tout cela depuis 60 jours sont proposés en revue, un par un : toujours là, fini, ou jeté.
 * Un article déclaré « toujours là » repart pour 60 jours.
 */
class StockReview
{
    public const IDLE_DAYS = 60;

    /** Bandeau sur la page du stock à partir de ce nombre d'articles. */
    public const BANNER_MIN = 3;

    /**
     * Articles immobiles, du plus ancien au plus récent.
     *
     * @return Collection<int, StockItem> avec l'attribut `idle_since` (Carbon)
     */
    public function items(?int $locationId = null, ?Carbon $today = null): Collection
    {
        $limit = ($today ?? Carbon::today())->copy()->subDays(self::IDLE_DAYS);

        return StockItem::query()->active()
            ->when($locationId, fn ($q) => $q->where('storage_location_id', $locationId))
            ->where('created_at', '<', $limit)
            ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', $limit))
            ->withMax('movements', 'created_at')
            ->with('ingredient', 'unit', 'location')
            ->get()
            ->map(function (StockItem $item) {
                $item->idle_since = collect([$item->created_at, $item->checked_at, $item->movements_max_created_at ? Carbon::parse($item->movements_max_created_at) : null])
                    ->filter()->max();

                return $item;
            })
            ->filter(fn (StockItem $item) => $item->idle_since->lt($limit))
            ->sortBy(fn (StockItem $item) => $item->idle_since->timestamp)
            ->values();
    }

    /** Nombre d'articles immobiles, par emplacement. @return Collection<int, int> clé : emplacement */
    public function countsByLocation(?Carbon $today = null): Collection
    {
        return $this->items(null, $today)->countBy('storage_location_id');
    }

    /** « Toujours là » : l'article repart pour 60 jours. */
    public function keep(StockItem $item): void
    {
        $item->forceFill(['checked_at' => now()])->save();
    }
}
