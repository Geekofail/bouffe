<?php

namespace App\Services\Stock;

use App\Enums\MealType;
use App\Enums\StockMode;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\StockItem;
use App\Services\Recipes\SubRecipes;
use Illuminate\Support\Carbon;

/**
 * Stock réservé (lot 21 — 22.3, règle R24).
 *
 * Les repas planifiés des deux prochaines semaines « réservent » ce qu'ils retireront du stock,
 * dans l'ordre où ils seront mangés : le premier repas se sert d'abord. Rien n'est enregistré —
 * les réservations se recalculent à chaque lecture, donc déplacer ou supprimer un repas suffit.
 *
 * Un repas est **en conflit** quand le stock aurait suffi pour lui seul, mais que des repas plus
 * proches l'ont déjà pris : « les œufs sont pour la quiche de mardi ».
 */
class StockReservations
{
    public const DAYS = 14;

    public function __construct(
        private readonly StockAvailability $availability,
        private readonly MealStockService $mealStock,
        private readonly SubRecipes $subRecipes,
    ) {}

    /**
     * @return array{
     *   items: array<int, array{reserved: float, meals: list<array{id: int, label: string, date: Carbon}>}>,
     *   meals: array<int, list<array{ingredient: string, missing: string, taken_by: list<string>}>>,
     *   allocations: list<array{meal_id: int, date: string, item_id: int, quantity: float}>
     * }
     */
    public function compute(?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $slotOrder = MealSlot::query()->pluck('sort_order', 'id');

        $meals = PlannedMeal::query()
            ->where('type', MealType::Recipe->value)
            ->whereNull('cooked_at')->whereNull('skipped_at')->whereNull('prepared_at')
            ->whereBetween('date', [$today->toDateString(), $today->copy()->addDays(self::DAYS - 1)->toDateString()])
            ->whereHas('recipe')
            ->with('recipe.ingredients.ingredient.defaultUnit', 'recipe.ingredients.unit')
            ->get()
            ->sortBy(fn (PlannedMeal $m) => sprintf('%s-%05d-%03d', $m->date->toDateString(), $slotOrder[$m->meal_slot_id] ?? 0, $m->position))
            ->values();

        $pools = [];          // ingredient_id => ['entries' => [[item, left]], 'total' => float|null]
        $takenBy = [];        // ingredient_id => list<label>
        $result = ['items' => [], 'meals' => [], 'allocations' => []];

        foreach ($meals as $meal) {
            $recipe = $meal->recipe;
            $label = $recipe->title.' ('.$meal->date->locale('fr')->isoFormat('ddd D').')';
            $already = $this->mealStock->deductedIngredientIds($meal);

            foreach ($this->subRecipes->lines($recipe)->reject(fn ($l) => $l->is_optional)->groupBy('ingredient_id') as $ingredientId => $lines) {
                $ingredient = $lines->first()->ingredient;

                if (! $ingredient || in_array((int) $ingredientId, $already, true)
                    || in_array($ingredient->stock_mode, [StockMode::None, StockMode::Presence], true)) {
                    continue;
                }

                [$need, $unit] = $this->mealStock->need($lines, (int) $recipe->servings, (int) $meal->servings, $ingredient);

                if ($need === null || $need <= 0) {
                    continue;
                }

                $pools[$ingredientId] ??= $this->pool($ingredient);
                $pool = &$pools[$ingredientId];
                $total = 0.0;

                foreach ($pool as $entry) {
                    if ($entry['left'] === null || ($entry['date'] !== null && $entry['date']->lt($meal->date->copy()->startOfDay()))) {
                        continue;
                    }

                    $total += $this->availability->convert($entry['initial'], $entry['item']->unit, $unit, $ingredient) ?? 0.0;
                }

                $remaining = $need;

                foreach ($pool as &$entry) {
                    if ($remaining <= 0.0005) {
                        break;
                    }

                    // Un article qui aura dépassé sa date le jour du repas ne peut pas lui être réservé (comme R8).
                    if ($entry['date'] !== null && $entry['date']->lt($meal->date->copy()->startOfDay())) {
                        continue;
                    }

                    $available = $entry['left'] === null ? null : $this->availability->convert($entry['left'], $entry['item']->unit, $unit, $ingredient);

                    if ($available === null || $available <= 0.0005) {
                        continue;
                    }

                    $takeInNeedUnit = min($available, $remaining);
                    $take = $this->availability->convert($takeInNeedUnit, $unit, $entry['item']->unit, $ingredient) ?? 0.0;
                    $entry['left'] = max(0.0, $entry['left'] - $take);
                    $remaining -= $takeInNeedUnit;

                    $id = $entry['item']->id;
                    $result['items'][$id] ??= ['reserved' => 0.0, 'meals' => []];
                    $result['items'][$id]['reserved'] += $take;
                    $result['items'][$id]['meals'][] = ['id' => $meal->id, 'label' => $label, 'date' => $meal->date];
                    $result['allocations'][] = ['meal_id' => $meal->id, 'date' => $meal->date->toDateString(), 'item_id' => $id, 'quantity' => $take];
                }
                unset($entry, $pool);

                // Conflit : le stock suffisait pour ce repas seul, mais d'autres repas l'ont pris avant lui.
                if ($remaining > 0.0005 && $total >= $need - 0.0005 && ! empty($takenBy[$ingredientId])) {
                    $result['meals'][$meal->id][] = [
                        'ingredient' => $ingredient->name,
                        'missing' => app(\App\Services\IngredientLineFormatter::class)->format($remaining, $unit, $ingredient)['text'],
                        'taken_by' => array_slice(array_values(array_unique($takenBy[$ingredientId])), -3),
                    ];
                }

                // Seuls les repas qui ont vraiment pris une part du stock sont cités dans un conflit.
                if ($remaining < $need - 0.0005) {
                    $takenBy[$ingredientId][] = $label;
                }
            }
        }

        foreach ($result['items'] as &$item) {
            $item['reserved'] = round($item['reserved'], 3);
        }

        return $result;
    }

    /**
     * Quantités réservées par des repas antérieurs à une date (pour la liste de courses, R8 + R24).
     *
     * @param  list<int>  $excludeMealIds  repas de la liste elle-même : ils sont déjà dans le besoin
     * @return array<int, float> par article de stock, dans son unité
     */
    public function reservedBefore(Carbon $date, array $excludeMealIds = [], ?Carbon $today = null): array
    {
        $reserved = [];

        foreach ($this->compute($today)['allocations'] as $allocation) {
            if ($allocation['date'] < $date->toDateString() && ! in_array($allocation['meal_id'], $excludeMealIds, true)) {
                $reserved[$allocation['item_id']] = ($reserved[$allocation['item_id']] ?? 0.0) + $allocation['quantity'];
            }
        }

        return $reserved;
    }

    /** @return list<array{item: StockItem, left: float|null, initial: float}> */
    private function pool($ingredient): array
    {
        return $this->availability->items($ingredient)
            ->map(fn (StockItem $item) => [
                'item' => $item,
                'left' => $item->quantity === null ? null : (float) $item->quantity,
                'initial' => (float) ($item->quantity ?? 0),
                'date' => app(ExpiryCalculator::class)->effective($item)['date']?->copy()->startOfDay(),
            ])
            ->all();
    }
}
