<?php

namespace App\Services\Stays;

use App\Enums\ItemOrigin;
use App\Enums\StockMode;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Stay;
use App\Models\StayMeal;
use App\Models\StayPackedItem;
use App\Services\QuantityFormatter;
use App\Services\Shopping\ShoppingLine;
use App\Services\Shopping\ShoppingListGenerator;
use App\Services\UnitConverter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Courses d'un séjour (lot 34, 34.2) : une liste calculée sur les repas du séjour, avec les portions
 * des présents. Sans le stock de la maison (on n'est pas chez soi), sauf ce qu'on emporte (34.4).
 * Elle est ouverte aux foyers reliés qui participent (liste groupée, 26.8), et reste hors des
 * « listes en cours » de la maison.
 */
class StayShopping
{
    public function __construct(
        private readonly ShoppingListGenerator $generator,
        private readonly StayService $stays,
        private readonly UnitConverter $converter,
        private readonly QuantityFormatter $formatter,
    ) {}

    public function create(Stay $stay): ShoppingList
    {
        if ($stay->shoppingList) {
            return $this->sync($stay->shoppingList);
        }

        return DB::transaction(function () use ($stay) {
            $list = ShoppingList::create([
                'name' => 'Séjour : '.$stay->name,
                'stay_id' => $stay->id,
                'period_start' => $stay->starts_on,
                'period_end' => $stay->ends_on,
                'include_past' => true,
                'deduct_stock' => false,
                'shared_with_links' => $stay->participants()->whereNotNull('linked_household_id')->exists()
                    || $stay->households()->where('status', 'accepted')->exists(),   // lot 42 : foyers qui co-organisent
                'created_by' => auth()->id(),
            ]);

            return $this->sync($list);
        });
    }

    /**
     * Recalcule les articles issus des repas du séjour. Les articles ajoutés à la main, cochés,
     * retirés ou dont la quantité a été changée restent comme ils sont.
     */
    public function sync(ShoppingList $list): ShoppingList
    {
        $stay = $list->stay()->with('participants')->firstOrFail();
        $lines = $this->generator->generate($this->meals($stay))->keyBy(fn (ShoppingLine $line) => $line->ingredient->id);
        // Ce que chaque foyer emporte de chez lui (lot 42 : tous les foyers du séjour).
        $packed = $stay->packedItems()->whereNull('returned_at')->whereNotNull('ingredient_id')->with('unit', 'ingredient')->get()->groupBy('ingredient_id');
        // Lot 42 (42.2) : « Monique apporte le pain ».
        $brought = app(\App\Services\Together\Contributions::class)->broughtIngredients($stay);

        DB::transaction(function () use ($list, $lines, $packed, $brought) {
            $existing = $list->items()->whereIn('origin', [ItemOrigin::Generated->value, ItemOrigin::Staple->value])->get()->keyBy('ingredient_id');

            foreach ($lines as $ingredientId => $line) {
                $item = $existing->get($ingredientId) ?? new ShoppingListItem(['shopping_list_id' => $list->id]);
                $previous = $item->exists ? [(float) $item->quantity, $item->unit_id] : null;

                $item->fill([
                    'ingredient_id' => $line->ingredient->id,
                    'label' => $line->ingredient->name,
                    'aisle_id' => $line->ingredient->aisle_id,
                    'origin' => $line->isStaple ? ItemOrigin::Staple : ItemOrigin::Generated,
                    'is_optional' => $line->isOptional,
                ]);

                if (! $item->quantity_overridden) {
                    $item->fill([
                        'quantity' => $line->quantity() === null ? null : round($line->quantity(), 3),
                        'unit_id' => $line->unit()?->id,
                        'extra_quantities' => $line->extraQuantities() ?: null,
                    ]);
                    $this->applyPacked($item, $line, $packed->get($ingredientId, collect()));

                    if (isset($brought[$ingredientId]) && $item->stock_status !== 'covered') {
                        $item->fill(['stock_status' => 'covered', 'stock_deducted' => null, 'stock_note' => 'Apporté par '.implode(', ', $brought[$ingredientId])]);
                    }

                    if ($previous && $item->is_checked && ($item->unit_id !== $previous[1] || (float) $item->quantity > $previous[0] + 0.0005)) {
                        $item->fill(['is_checked' => false, 'checked_by' => null, 'checked_at' => null]);
                    }
                }

                $item->shopping_list_id = $list->id;
                $item->save();
                $item->sources()->delete();
                // Les repas d'un séjour ne sont pas ceux du planning de la maison : pas de lien vers eux.
                $item->sources()->createMany(array_map(fn (array $source) => ['planned_meal_id' => null] + $source, $line->sources));
            }

            foreach ($existing as $ingredientId => $item) {
                if (! $lines->has($ingredientId)) {
                    $item->delete();
                }
            }

            $list->update(['generated_at' => now()]);
        });

        return $list->fresh();
    }

    /**
     * Repas du séjour sous la forme attendue par le calcul des courses (R1, R2, R5).
     *
     * @return Collection<int, object>
     */
    public function meals(Stay $stay): Collection
    {
        $brought = app(\App\Services\Together\Contributions::class)->broughtStayMeals($stay);

        return $stay->meals()->whereNotNull('recipe_id')
            ->when($brought !== [], fn ($q) => $q->whereNotIn('id', $brought))   // lot 42 : quelqu'un l'apporte
            ->with(['slot', 'recipe.ingredients.ingredient.defaultUnit', 'recipe.ingredients.ingredient.aisle', 'recipe.ingredients.unit',
                ...array_map(fn ($r) => 'recipe.'.$r, \App\Services\Recipes\SubRecipes::EAGER)])
            ->get()
            ->filter(fn (StayMeal $meal) => $meal->recipe !== null)
            ->map(fn (StayMeal $meal) => (object) [
                'id' => null,
                'recipe' => $meal->recipe,
                'servings' => $this->stays->servingsFor($meal, $stay),
                'date' => $meal->date,
                'slot' => $meal->slot,
            ])
            ->values();
    }

    /** Ce qu'on emporte de la maison est déduit de la liste (34.4). */
    private function applyPacked(ShoppingListItem $item, ShoppingLine $line, Collection $packed): void
    {
        $item->fill(['stock_status' => null, 'stock_deducted' => null, 'stock_note' => null]);

        if ($packed->isEmpty()) {
            return;
        }

        $unit = $line->unit();
        $need = $line->quantity();
        $whole = $packed->contains(fn (StayPackedItem $p) => $p->quantity === null || $p->ingredient?->stock_mode === StockMode::Presence);

        if ($whole || $need === null) {
            $item->stock_status = 'covered';
            $item->stock_note = 'Emporté de la maison';

            return;
        }

        $amount = 0.0;
        $unknown = [];

        foreach ($packed as $p) {
            if ($unit && $p->unit && $this->converter->canConvert($p->unit, $unit, $line->ingredient)) {
                $amount += $this->converter->convert((float) $p->quantity, $p->unit, $unit, $line->ingredient);
            } elseif (! $unit && ! $p->unit) {
                $amount += (float) $p->quantity;
            } else {
                $unknown[] = $this->formatter->format((float) $p->quantity, $p->unit, QuantityFormatter::SHOPPING);
            }
        }

        $format = fn (float $q) => $this->formatter->format($q, $unit, QuantityFormatter::SHOPPING);

        if ($amount > 0) {
            $deducted = min($need, $amount);
            $covered = $deducted >= $need - 0.0005;
            $item->stock_status = $covered ? 'covered' : 'partial';
            $item->stock_deducted = round($deducted, 3);
            $item->stock_note = 'Besoin '.$format($need).' · emporté de la maison '.$format($amount);

            if (! $covered) {
                $item->quantity = round($need - $deducted, 3);
            }
        } else {
            $item->stock_note = 'Emporté de la maison : '.implode(', ', $unknown).' — vérifier';
        }
    }

    /** Résumé pour la fiche du séjour. @return array{total: int, checked: int, list: ShoppingList|null, updated: Carbon|null} */
    public function summary(Stay $stay): array
    {
        $list = $stay->shoppingList;

        return [
            'list' => $list,
            'total' => $list ? $list->items()->where('is_removed', false)->count() : 0,
            'checked' => $list ? $list->items()->where('is_removed', false)->where('is_checked', true)->count() : 0,
            'updated' => $list?->generated_at,
        ];
    }
}
