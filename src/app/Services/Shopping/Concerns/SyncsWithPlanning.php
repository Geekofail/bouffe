<?php

namespace App\Services\Shopping\Concerns;

use App\Enums\ItemOrigin;
use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\RecurringItem;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\StandingItem;
use App\Models\Unit;
use App\Services\Shopping\ShoppingLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Listes de courses — mise à jour depuis le planning : lignes calculées, stock déduit, articles saisis
 * à la main absorbés, réassort, articles récurrents et permanents (découpé de ShoppingListManager
 * au lot 36).
 */
trait SyncsWithPlanning
{
    /**
     * Recalcule les articles issus du planning (règle 4.3) :
     *  - conserve articles manuels, récurrents, retirés et quantités modifiées à la main ;
     *  - conserve la case cochée si la quantité n'a pas augmenté ;
     *  - renvoie la liste des différences, en texte.
     *
     * @return list<string>
     */
    public function regenerate(ShoppingList $list): array
    {
        // Liste d'un séjour (lot 34) : calculée sur les repas du séjour, jamais sur le planning de la maison.
        if ($list->stay_id) {
            app(\App\Services\Stays\StayShopping::class)->sync($list);

            return [];
        }

        return DB::transaction(function () use ($list) {
            $changes = $this->syncGenerated($list);
            $this->addRestockItems($list);

            return $changes;
        });
    }

    /** @return list<string> différences lisibles */
    private function syncGenerated(ShoppingList $list): array
    {
        $today = Carbon::today();
        $meals = $this->generator->meals($list->period_start, $list->period_end, $list->include_past, (array) $list->excluded_meal_ids, $today);
        $lines = $this->generator->generate($meals)->keyBy(fn (ShoppingLine $line) => $line->ingredient->id);

        // R24 : le stock déjà promis aux repas d'avant la liste (demain midi…) n'est plus disponible pour elle.
        $this->reservedByItem = $list->deduct_stock
            ? app(\App\Services\Stock\StockReservations::class)->reservedBefore($list->period_start->copy()->max($today), $meals->pluck('id')->all(), $today)
            : [];

        /** @var Collection<int, ShoppingListItem> $existing */
        $existing = $list->items()
            ->whereIn('origin', [ItemOrigin::Generated->value, ItemOrigin::Staple->value])
            ->with(['ingredient', 'unit'])
            ->get()
            ->keyBy('ingredient_id');

        $changes = [];
        $isUpdate = $list->generated_at !== null;

        // 15.5 : articles ajoutés à la main pour un ingrédient aussi calculé depuis le planning → fusionnés.
        $manual = $list->items()
            ->where('origin', ItemOrigin::Manual->value)
            ->whereNull('for_household_id')   // article d'un foyer relié (26.8) : jamais fusionné avec les nôtres
            ->whereIn('ingredient_id', $lines->keys())
            ->where('is_checked', false)->where('is_removed', false)
            ->with('unit')
            ->get()
            ->groupBy('ingredient_id');

        foreach ($lines as $ingredientId => $line) {
            $item = $existing->get($ingredientId);
            $before = $item ? $this->presenter->text($item) : null;

            $item ??= new ShoppingListItem(['shopping_list_id' => $list->id]);
            $wasNew = ! $item->exists;
            $previous = $item->exists ? [(float) $item->quantity, $item->unit_id, (array) $item->extra_quantities] : null;

            $item->fill([
                'ingredient_id' => $line->ingredient->id,
                'label' => $line->ingredient->name,
                'aisle_id' => $line->ingredient->aisle_id,
                'origin' => $line->isStaple ? ItemOrigin::Staple : ItemOrigin::Generated,
                'is_optional' => $line->isOptional,
            ]);

            $merged = collect();

            if (! $item->quantity_overridden) {
                $item->fill([
                    'quantity' => $line->quantity() === null ? null : round($line->quantity(), 3),
                    'unit_id' => $line->unit()?->id,
                    'extra_quantities' => $line->extraQuantities() ?: null,
                ]);

                $this->applyStock($list, $item, $line);

                if (! $item->is_checked && ! $item->is_removed) {
                    $merged = $this->absorbManual($item, $manual->get($ingredientId, collect()), $line);
                }

                $this->applyAdded($item, $line);

                if ($previous && $item->is_checked && $this->increased($previous, $item)) {
                    $item->fill(['is_checked' => false, 'checked_by' => null, 'checked_at' => null]);
                }
            }

            $item->shopping_list_id = $list->id;
            $item->save();

            $merged->each->delete();

            $item->sources()->delete();
            $item->sources()->createMany($line->sources);

            if ($isUpdate && ! $item->is_removed) {
                $item->setRelation('ingredient', $line->ingredient);
                $after = $this->presenter->text($item->fresh(['ingredient']));

                if ($wasNew) {
                    $changes[] = "Ajouté : {$after}";
                } elseif ($before !== $after) {
                    $changes[] = "Modifié : {$before} → {$after}";
                }
            }
        }

        foreach ($existing as $ingredientId => $item) {
            if (! $lines->has($ingredientId)) {
                if ($isUpdate && ! $item->is_removed) {
                    $changes[] = 'Retiré : '.$this->presenter->text($item);
                }

                $item->delete();
            }
        }

        $list->update(['generated_at' => now()]);

        return $changes;
    }

    private function applyStock(ShoppingList $list, ShoppingListItem $item, ShoppingLine $line): void
    {
        $item->fill(['stock_status' => null, 'stock_deducted' => null, 'stock_note' => null]);

        if (! $list->deduct_stock || $item->buy_anyway) {
            return;
        }

        $availability = app(\App\Services\Stock\StockAvailability::class);
        $ingredient = $line->ingredient;
        $unit = $line->unit();
        $need = $line->quantity();
        $neededOn = Carbon::parse(collect($line->sources)->min('meal_date') ?? Carbon::today());
        $stock = $availability->forNeed($ingredient, $unit, max($neededOn, Carbon::today()), $this->reservedByItem);

        if (! $stock['tracked']) {
            return;
        }

        $notes = [];
        $format = fn (float $q) => app(\App\Services\QuantityFormatter::class)->format($q, $unit, \App\Services\QuantityFormatter::SHOPPING);

        if ($ingredient->stock_mode === \App\Enums\StockMode::Presence || $need === null) {
            $status = $stock['present'] ? 'covered' : 'out';
            $notes[] = $stock['present'] ? 'En stock' : null;
        } elseif ($stock['amount'] > 0) {
            $deducted = min($need, $stock['amount']);
            $covered = $deducted >= $need - 0.0005;
            $status = $covered ? 'covered' : 'partial';
            $item->stock_deducted = round($deducted, 3);
            $notes[] = $covered
                ? 'Besoin '.$format($need).' · en stock '.$format($stock['amount'])
                : 'Besoin '.$format($need).' − en stock '.$format($stock['amount']);

            if (! $covered) {
                $item->quantity = round($need - $deducted, 3);
            }
        } elseif ($stock['unknown']->isNotEmpty()) {
            $status = null;
            $notes[] = 'En stock : '.$stock['unknown']->map(fn ($i) => $availability->describe($i))->join(', ').' — vérifier';
        } else {
            $status = 'out';
        }

        foreach ($stock['expiring']->take(2) as $expiring) {
            $date = app(\App\Services\Stock\ExpiryCalculator::class)->effective($expiring)['date'];
            $notes[] = 'périme le '.$date->format('d/m').', prévu le '.$neededOn->format('d/m').' — non compté';
        }

        $item->stock_status = $status;
        $item->stock_note = mb_substr(implode(' · ', array_filter($notes)), 0, 255) ?: null;
    }

    /**
     * Reporte la quantité d'articles manuels sur l'article généré (added_quantity, dans l'unité de l'article).
     * Un article manuel dont l'unité n'est pas convertible reste séparé.
     *
     * @param  Collection<int, ShoppingListItem>  $manualItems
     * @return Collection<int, ShoppingListItem> articles manuels absorbés (à supprimer)
     */
    private function absorbManual(ShoppingListItem $item, Collection $manualItems, ShoppingLine $line): Collection
    {
        $absorbed = collect();
        $converter = app(\App\Services\UnitConverter::class);

        foreach ($manualItems as $manual) {
            if ($manual->quantity === null) {
                $item->added_quantity ??= 0;
                $absorbed->push($manual);

                continue;
            }

            $quantity = (float) $manual->quantity;
            $targetUnit = $item->added_unit_id ? Unit::find($item->added_unit_id) : ($line->unit() ?? $manual->unit);

            if ($manual->unit && $targetUnit && $manual->unit->id !== $targetUnit->id) {
                if (! $converter->canConvert($manual->unit, $targetUnit, $line->ingredient)) {
                    continue;
                }

                $quantity = $converter->convert($quantity, $manual->unit, $targetUnit, $line->ingredient);
            } elseif (($manual->unit === null) !== ($targetUnit === null)) {
                continue;   // « 2 » sans unité face à des grammes : pas comparable
            }

            $item->added_quantity = round((float) $item->added_quantity + $quantity, 3);
            $item->added_unit_id = $targetUnit?->id;
            $absorbed->push($manual);
        }

        return $absorbed;
    }

    /** Ajoute à la quantité calculée ce qui a été ajouté à la main (15.5). */
    private function applyAdded(ShoppingListItem $item, ShoppingLine $line): void
    {
        if ($item->added_quantity === null) {
            return;
        }

        $added = (float) $item->added_quantity;
        $addedUnit = $item->added_unit_id ? Unit::find($item->added_unit_id) : null;
        $unit = $item->unit_id ? Unit::find($item->unit_id) : null;
        $converter = app(\App\Services\UnitConverter::class);
        $formatter = app(\App\Services\QuantityFormatter::class);

        if ($added <= 0) {
            if ($item->stock_status === 'covered') {
                $item->stock_status = null;
            }

            $item->stock_note = trim(($item->stock_note ? $item->stock_note.' · ' : '').'aussi ajouté à la main');

            return;
        }

        $note = 'dont '.$formatter->format($added, $addedUnit, \App\Services\QuantityFormatter::SHOPPING).' ajouté à la main';

        if ($item->quantity === null && $unit === null) {
            $item->quantity = $added;
            $item->unit_id = $addedUnit?->id;
        } elseif ($unit && $addedUnit && ($unit->id === $addedUnit->id || $converter->canConvert($addedUnit, $unit, $line->ingredient))) {
            $quantity = $unit->id === $addedUnit->id ? $added : $converter->convert($added, $addedUnit, $unit, $line->ingredient);
            $item->quantity = $item->stock_status === 'covered' ? round($quantity, 3) : round((float) $item->quantity + $quantity, 3);
        } elseif (! $unit && ! $addedUnit) {
            $item->quantity = $item->stock_status === 'covered' ? $added : round((float) $item->quantity + $added, 3);
        } else {
            $extras = (array) $item->extra_quantities;
            $extras[] = ['q' => $added, 'unit_id' => $addedUnit?->id];
            $item->extra_quantities = $extras;
        }

        if ($item->stock_status === 'covered') {
            $item->stock_status = 'partial';
        }

        $item->stock_note = mb_substr(trim(($item->stock_note ? $item->stock_note.' · ' : '').$note), 0, 255);
    }

    /** « Acheter quand même » un article couvert par le stock (ou revenir à la déduction). */
    public function setBuyAnyway(ShoppingListItem $item, bool $buyAnyway): ShoppingListItem
    {
        $item->update(['buy_anyway' => $buyAnyway]);
        $this->regenerate($item->shoppingList);

        return $item->fresh();
    }

    /** Ingrédients sous leur stock minimum (9.10) ajoutés à la liste. @return int nombre d'articles ajoutés */
    public function addRestockItems(ShoppingList $list): int
    {
        $present = $list->items()->whereNotNull('ingredient_id')->pluck('ingredient_id')->all();
        $added = 0;

        foreach (app(\App\Services\Stock\StockMinimum::class)->below() as $row) {
            $ingredient = $row['ingredient'];

            if (in_array($ingredient->id, $present, true)) {
                continue;
            }

            $list->items()->create([
                'ingredient_id' => $ingredient->id,
                'label' => $ingredient->name,
                'aisle_id' => $ingredient->aisle_id,
                'quantity' => $row['missing'] === null ? null : round($row['missing'], 3),
                'unit_id' => $row['missing'] === null ? null : $row['unit']?->id,
                'origin' => ItemOrigin::Restock,
                'stock_note' => 'Stock bas : '.$row['text'],
            ]);
            $added++;
        }

        return $added;
    }

    /** La quantité a-t-elle augmenté (ou changé d'unité) ? */
    private function increased(array $previous, ShoppingListItem $item): bool
    {
        [$quantity, $unitId, $extras] = $previous;

        if ($unitId !== $item->unit_id || count($extras) !== count((array) $item->extra_quantities)) {
            return true;
        }

        if ((float) $item->quantity > $quantity + 0.0005) {
            return true;
        }

        foreach ((array) $item->extra_quantities as $i => $extra) {
            if (($extras[$i]['unit_id'] ?? null) !== ($extra['unit_id'] ?? null) || (float) $extra['q'] > (float) ($extras[$i]['q'] ?? 0) + 0.0005) {
                return true;
            }
        }

        return false;
    }

    private function addRecurringItems(ShoppingList $list): void
    {
        $present = $list->items()->whereNotNull('ingredient_id')->pluck('ingredient_id')->all();

        foreach (RecurringItem::query()->active()->with('ingredient')->orderBy('label')->get() as $recurring) {
            if ($recurring->ingredient_id && in_array($recurring->ingredient_id, $present, true)) {
                continue;
            }

            $list->items()->create([
                'ingredient_id' => $recurring->ingredient_id,
                'label' => $recurring->label,
                'aisle_id' => $recurring->aisle_id ?? $recurring->ingredient?->aisle_id,
                'origin' => ItemOrigin::Recurring,
            ]);
        }
    }

    /**
     * Liste « quand je passe » (15.8) : ce qui attend est versé dans la nouvelle liste,
     * puis marqué comme pris en charge — on garde la trace de la liste qui l'a emporté.
     */
    private function addStandingItems(ShoppingList $list): void
    {
        foreach (StandingItem::query()->waiting()->with('ingredient')->orderBy('id')->get() as $standing) {
            $item = $list->items()->create([
                'ingredient_id' => $standing->ingredient_id,
                'label' => $standing->label,
                'aisle_id' => $standing->aisle_id ?? $standing->ingredient?->aisle_id ?? Aisle::where('name', 'Divers')->value('id'),
                'store_id' => $standing->store_id,
                'origin' => ItemOrigin::Manual,
                'stock_note' => $standing->note,
            ]);

            $standing->update(['added_to_list_id' => $list->id, 'added_at' => now()]);

            unset($item);
        }
    }

    /**
     * Articles fréquents (15.6) : ce qu'on achète souvent et qui n'est pas dans cette liste.
     *
     * « Souvent » se lit dans l'historique réel des listes : un ingrédient coché au moins deux
     * fois ces derniers mois. Rien n'est ajouté tout seul, ce sont des suggestions.
     *
     * @return Collection<int, array{ingredient: Ingredient, count: int, last: Carbon}>
     */
    public function frequentItems(ShoppingList $list, int $limit = 8, int $days = 60): Collection
    {
        $present = $list->items()->whereNotNull('ingredient_id')->pluck('ingredient_id')->all();

        $rows = ShoppingListItem::query()
            ->whereNotNull('ingredient_id')
            ->where('is_checked', true)
            ->where('shopping_list_id', '!=', $list->id)
            ->where('checked_at', '>=', Carbon::today()->subDays($days))
            ->when($present !== [], fn ($query) => $query->whereNotIn('ingredient_id', $present))
            ->selectRaw('ingredient_id, count(*) as occurrences, max(checked_at) as last_checked')
            ->groupBy('ingredient_id')
            ->havingRaw('count(*) >= 2')
            ->orderByDesc('occurrences')
            ->orderByDesc('last_checked')
            ->limit($limit)
            ->get();

        $ingredients = Ingredient::query()->whereIn('id', $rows->pluck('ingredient_id'))->get()->keyBy('id');

        return $rows
            ->filter(fn ($row) => $ingredients->has($row->ingredient_id))
            ->map(fn ($row) => [
                'ingredient' => $ingredients[$row->ingredient_id],
                'count' => (int) $row->occurrences,
                'last' => Carbon::parse($row->last_checked),
            ])
            ->values();
    }
}
