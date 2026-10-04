<?php

namespace App\Services\Stock;

use App\Enums\StockMode;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Services\QuantityFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * « Ranger les courses » (9.6) : les articles cochés d'une liste entrent dans le stock en un écran.
 */
class PutAwayService
{
    public function __construct(
        private readonly StockManager $stock,
        private readonly QuantityFormatter $formatter,
        private readonly ShelfLifeLearner $learner,
    ) {}

    /** Articles cochés, non retirés, pas encore rangés. */
    public function pendingItems(ShoppingList $list): Collection
    {
        return $list->items()
            ->whereNull('for_household_id')   // acheté pour un foyer relié (26.8) : ne va pas dans notre stock
            ->where('is_checked', true)->where('is_removed', false)->whereNull('stocked_at')
            ->with('ingredient.defaultUnit', 'unit', 'aisle')
            ->get()
            ->sortBy(fn (ShoppingListItem $item) => [$item->aisle?->sort_order ?? 999, mb_strtolower($item->label)])
            ->values();
    }

    public function pendingCount(ShoppingList $list): int
    {
        return $list->items()->whereNull('for_household_id')->where('is_checked', true)->where('is_removed', false)->whereNull('stocked_at')->count();
    }

    /**
     * Lignes proposées pour l'écran de rangement.
     *
     * @return list<array{item_id: int, label: string, ingredient_id: int|null, presence: bool, include: bool, quantity: string, unit_id: int|null, storage_location_id: int|null, expires_on: string|null, expiry_type: string}>
     */
    public function proposal(ShoppingList $list, ?Carbon $today = null): array
    {
        return $this->pendingItems($list)->map(function (ShoppingListItem $item) use ($today) {
            $ingredient = $item->ingredient;
            $defaults = $this->stock->defaults($ingredient, $today);
            $mode = $ingredient?->stock_mode;

            $quantity = '';

            if ($item->quantity !== null && $mode === StockMode::Quantity) {
                $rounded = $item->unit && $item->unit->is_metric
                    ? (float) $item->quantity
                    : $this->formatter->rounded((float) $item->quantity, $item->unit, QuantityFormatter::SHOPPING);
                $quantity = $this->formatter->number($rounded, 3);
            }

            // 16.2 : ce qui a été observé chez vous prime sur l'estimation de départ.
            $learned = $ingredient && $mode !== StockMode::Presence
                ? $this->learner->suggest($ingredient, $today)
                : ['expires_on' => null, 'storage_location_id' => null, 'learned' => false, 'days' => null, 'note' => null];

            return [
                'item_id' => $item->id,
                'label' => $item->label,
                'ingredient_id' => $ingredient?->id,
                'presence' => $mode === StockMode::Presence,
                // Articles sans ingrédient (lessive) ou non suivis : ignorés par défaut
                'include' => $ingredient !== null && $mode !== StockMode::None,
                'quantity' => str_replace("\u{202F}", '', $quantity),
                'unit_id' => $item->unit_id ?? $defaults['unit_id'],
                'storage_location_id' => $learned['storage_location_id'] ?? $defaults['storage_location_id'],
                'expires_on' => $learned['expires_on'] ?? $defaults['expires_on'],
                'expiry_type' => $defaults['expiry_type'],
                'learned' => $learned['learned'],
                'learned_note' => $learned['note'],
            ];
        })->all();
    }

    /**
     * Enregistre le rangement. Toutes les lignes reçues sont marquées « rangées » (incluses ou ignorées),
     * pour ne pas être proposées deux fois.
     *
     * @param  list<array>  $rows
     * @return int nombre d'articles entrés dans le stock
     */
    public function store(ShoppingList $list, array $rows): int
    {
        return DB::transaction(function () use ($list, $rows) {
            $added = 0;

            foreach ($rows as $index => $row) {
                $item = $list->items()->whereKey((int) ($row['item_id'] ?? 0))->whereNull('for_household_id')->whereNull('stocked_at')->first();

                if (! $item) {
                    continue;
                }

                if (! empty($row['include']) && $item->ingredient_id) {
                    $quantity = trim((string) ($row['quantity'] ?? ''));
                    $parsed = $quantity === '' ? null : app(\App\Services\QuantityParser::class)->tryParse($quantity);

                    if ($parsed === false) {
                        throw new InvalidArgumentException("« {$item->label} » : quantité invalide.");
                    }

                    $this->stock->add([
                        'ingredient_id' => $item->ingredient_id,
                        'quantity' => $parsed,
                        'unit_id' => $row['unit_id'] ?? null,
                        'storage_location_id' => $row['storage_location_id'] ?? null,
                        'expires_on' => $row['expires_on'] ?? null,
                        'expiry_type' => $row['expiry_type'] ?? null,
                        'shopping_list_item_id' => $item->id,
                        'shopping_list_id' => $list->id,
                    ]);
                    $added++;
                }

                $item->update(['stocked_at' => Carbon::now()]);
            }

            return $added;
        });
    }
}
