<?php

namespace App\Services\Stock;

use App\Enums\MovementType;
use App\Models\Ingredient;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Durées réellement observées et emplacement habituel (16.2).
 *
 * Le réglage « conservation : 7 jours » d'un ingrédient est une estimation de départ. Ce service
 * regarde ce qui s'est **vraiment** passé chez vous : combien de jours ce produit reste avant
 * d'être fini, et où vous le rangez. Au rangement des courses, c'est cette durée-là qui est
 * proposée, quand elle repose sur assez d'observations.
 *
 * Prudence volontaire :
 *  - seuls les articles **consommés** comptent (un produit jeté n'apprend pas une durée de vie) ;
 *  - il faut au moins 3 observations, sinon on garde le réglage de l'ingrédient ;
 *  - c'est la **médiane** qui est retenue, pas la moyenne : un paquet oublié trois mois au fond
 *    du placard ne doit pas fausser la proposition.
 */
class ShelfLifeLearner
{
    public const MIN_OBSERVATIONS = 3;

    public const MAX_OBSERVATIONS = 8;

    /**
     * Durées de vie observées, en jours, de la plus récente à la plus ancienne.
     *
     * @return list<int>
     */
    public function observations(Ingredient $ingredient): array
    {
        $items = StockItem::query()
            ->where('ingredient_id', $ingredient->id)
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->limit(self::MAX_OBSERVATIONS * 2)
            ->get(['id', 'created_at', 'finished_at']);

        if ($items->isEmpty()) {
            return [];
        }

        // On ne retient que les articles réellement consommés (pas jetés).
        $consumed = StockMovement::query()
            ->whereIn('stock_item_id', $items->pluck('id'))
            ->where('type', MovementType::Consume->value)
            ->pluck('stock_item_id')
            ->unique();

        return $items
            ->filter(fn (StockItem $item) => $consumed->contains($item->id))
            ->map(fn (StockItem $item) => (int) round($item->created_at->diffInDays($item->finished_at)))
            ->filter(fn (int $days) => $days >= 0 && $days <= 400)
            ->take(self::MAX_OBSERVATIONS)
            ->values()
            ->all();
    }

    /** Durée de vie observée (médiane), ou null si on n'en sait pas encore assez. */
    public function observedDays(Ingredient $ingredient): ?int
    {
        $days = $this->observations($ingredient);

        if (count($days) < self::MIN_OBSERVATIONS) {
            return null;
        }

        sort($days);
        $middle = intdiv(count($days), 2);

        return count($days) % 2 === 1
            ? $days[$middle]
            : (int) round(($days[$middle - 1] + $days[$middle]) / 2);
    }

    /** Emplacement où cet ingrédient est habituellement rangé. */
    public function usualLocation(Ingredient $ingredient): ?int
    {
        return StockItem::query()
            ->where('ingredient_id', $ingredient->id)
            ->whereNotNull('storage_location_id')
            ->orderByDesc('id')
            ->limit(6)
            ->pluck('storage_location_id')
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();
    }

    /**
     * Ce que le rangement des courses doit proposer pour cet ingrédient.
     *
     * @return array{expires_on: string|null, storage_location_id: int|null, learned: bool, days: int|null, note: string|null}
     */
    public function suggest(Ingredient $ingredient, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $observed = $this->observedDays($ingredient);
        $location = $this->usualLocation($ingredient);

        if ($observed === null) {
            return [
                'expires_on' => null,
                'storage_location_id' => $location,
                'learned' => false,
                'days' => null,
                'note' => null,
            ];
        }

        return [
            'expires_on' => $today->copy()->addDays($observed)->toDateString(),
            'storage_location_id' => $location,
            'learned' => true,
            'days' => $observed,
            'note' => "D'après vos achats précédents : tenu {$observed} jour".($observed > 1 ? 's' : '').' en moyenne.',
        ];
    }

    /**
     * Ingrédients dont la durée observée s'écarte nettement du réglage : à proposer en correction.
     *
     * @return Collection<int, array{ingredient: Ingredient, configured: int, observed: int}>
     */
    public function disagreements(int $tolerance = 3): Collection
    {
        return Ingredient::query()
            ->whereSetting('shelf_life_days', 'not null')
            ->get()
            ->map(function (Ingredient $ingredient) {
                $observed = $this->observedDays($ingredient);

                return $observed === null ? null : [
                    'ingredient' => $ingredient,
                    'configured' => (int) $ingredient->shelf_life_days,
                    'observed' => $observed,
                ];
            })
            ->filter()
            ->filter(fn (array $row) => abs($row['observed'] - $row['configured']) > $tolerance)
            ->values();
    }
}
