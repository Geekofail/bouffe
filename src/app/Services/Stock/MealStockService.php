<?php

namespace App\Services\Stock;

use App\Enums\MealType;
use App\Enums\MovementType;
use App\Enums\StockMode;
use App\Enums\UnitType;
use App\Models\Ingredient;
use App\Models\PlannedMeal;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Services\Planning\WeekPlanner;
use App\Services\QuantityFormatter;
use App\Services\QuantityScaler;
use App\Support\StockDefaults;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock et repas mangés (9.8, règle R9) : ce qu'il faut retirer du stock, les restes à ranger,
 * et l'annulation quand on décoche « mangé ».
 */
class MealStockService
{
    /** Seuil sous lequel un article est proposé comme terminé (part de la quantité de départ). */
    private const FINISH_RATIO = 0.02;

    public function __construct(
        private readonly StockManager $stock,
        private readonly StockAvailability $availability,
        private readonly QuantityScaler $scaler,
        private readonly QuantityFormatter $formatter,
    ) {}

    /**
     * Proposition de retrait pour un repas « recette ».
     *
     * @return list<array{
     *   key: string, ingredient_id: int, name: string, optional: bool, mode: string,
     *   need: string, status: string, include: bool, finish_unknown: bool,
     *   allocations: list<array{stock_item_id: int, take: float|null, finish: bool, text: string}>, text: string
     * }>
     *   status : ok (stock suffisant) · partial · unknown (quantité inconnue / non comparable) · presence · missing
     */
    public function plan(PlannedMeal $meal): array
    {
        // Plat cuisiné à l'avance (14.8) : ses ingrédients ont été retirés le jour où il a été préparé.
        if ($meal->type !== MealType::Recipe || ! $meal->recipe || $meal->isPrepared()) {
            return [];
        }

        $recipe = $meal->recipe->loadMissing('ingredients.ingredient.defaultUnit', 'ingredients.unit');
        $rows = [];

        // Déjà retiré pour ce repas (retrait au fil du mode cuisine, 22.6) : on ne retire pas deux fois.
        $already = $this->deductedIngredientIds($meal);

        foreach (app(\App\Services\Recipes\SubRecipes::class)->lines($recipe)->groupBy('ingredient_id') as $ingredientId => $lines) {
            if (in_array((int) $ingredientId, $already, true)) {
                continue;
            }

            /** @var Ingredient $ingredient */
            $ingredient = $lines->first()->ingredient;

            if (! $ingredient || $ingredient->stock_mode === StockMode::None) {
                continue;
            }

            $items = $this->availability->items($ingredient, includeFrozen: false);
            $optional = $lines->every(fn ($l) => $l->is_optional);

            if ($items->isEmpty()) {
                continue;   // rien en stock : rien à retirer (pas de ligne « manquant » dans la fenêtre)
            }

            [$need, $unit] = $this->need($lines, $recipe->servings, $meal->servings, $ingredient);
            $needText = $need === null ? 'à convenance' : $this->formatter->format($need, $unit);

            if ($ingredient->stock_mode === StockMode::Presence) {
                $rows[] = $this->row($ingredient, $optional, 'presence', $needText, include: false, finishUnknown: false,
                    allocations: $items->map(fn (StockItem $i) => ['stock_item_id' => $i->id, 'take' => null, 'finish' => true, 'text' => 'plus rien'])->all(),
                    text: 'Suivi en présence : cochez s\'il n\'en reste plus');

                continue;
            }

            $allocations = [];
            $remaining = $need;
            $unknown = false;

            foreach ($items as $item) {
                if ($remaining !== null && $remaining <= 0.0005) {
                    break;
                }

                $available = $need === null ? null : $this->availability->amountIn($item, $unit, $ingredient);

                if ($available === null) {
                    $unknown = true;
                    $allocations[] = ['stock_item_id' => $item->id, 'take' => null, 'finish' => true, 'text' => $this->availability->describe($item).' : marquer terminé ?'];

                    continue;
                }

                $takeInNeedUnit = min($available, $remaining);
                $take = $this->availability->convert($takeInNeedUnit, $unit, $item->unit, $ingredient) ?? (float) $item->quantity;
                $left = max(0.0, (float) $item->quantity - $take);
                $finish = $this->isFinished($item, $left);

                $allocations[] = [
                    'stock_item_id' => $item->id,
                    'take' => round($take, 3),
                    'finish' => $finish,
                    'text' => '−'.$this->formatter->format($take, $item->unit).($finish ? ' (terminé)' : ' (reste '.$this->formatter->format($left, $item->unit).')'),
                ];

                $remaining -= $takeInNeedUnit;
            }

            $quantified = collect($allocations)->whereNotNull('take');
            $status = match (true) {
                $quantified->isEmpty() => 'unknown',
                $remaining !== null && $remaining > 0.0005 && ! $unknown => 'partial',
                default => 'ok',
            };

            $rows[] = $this->row($ingredient, $optional, $status, $needText,
                include: ! $optional && $quantified->isNotEmpty(),
                finishUnknown: false,
                allocations: $allocations,
                text: collect($allocations)->pluck('text')->join(' · ').($status === 'partial' ? ' — pas assez en stock' : ''));
        }

        return $rows;
    }

    /**
     * Applique la proposition (lignes éventuellement modifiées dans la fenêtre).
     *
     * @param  list<array>  $rows
     * @return int nombre de mouvements créés
     */
    public function apply(PlannedMeal $meal, array $rows, bool $linkToMeal = true): int
    {
        $stock = $this->stock->forMeal($linkToMeal ? $meal->id : null);

        return DB::transaction(function () use ($rows, $stock) {
            $count = 0;

            foreach ($rows as $row) {
                $withQuantity = ! empty($row['include']);
                $finishUnknown = ! empty($row['finish_unknown']);

                foreach ($row['allocations'] ?? [] as $allocation) {
                    $item = StockItem::active()->find($allocation['stock_item_id'] ?? 0);

                    if (! $item) {
                        continue;
                    }

                    $isUnknown = $allocation['take'] === null;

                    if ($isUnknown ? ! $finishUnknown : ! $withQuantity) {
                        continue;
                    }

                    if ($isUnknown || $allocation['finish']) {
                        $stock->finish($item);
                    } else {
                        $stock->setQuantity($item, max(0.001, round((float) $item->quantity - (float) $allocation['take'], 3)));
                    }

                    $count++;
                }
            }

            return $count;
        });
    }

    /**
     * Retrait sans fenêtre (mode automatique, clôture automatique) : la proposition telle quelle,
     * les restes consommés et les portions en trop rangées au réfrigérateur.
     *
     * @return list<string> ce qui a été fait, pour le message
     */
    public function applyDefault(PlannedMeal $meal): array
    {
        $parts = [];
        $count = $this->apply($meal, $this->plan($meal));

        if ($count > 0) {
            $parts[] = $count.' article'.($count > 1 ? 's' : '').' retiré'.($count > 1 ? 's' : '');
        }

        if ($this->leftoverConsumption($meal) && $this->consumeLeftovers($meal)) {
            $parts[] = $meal->isPrepared() ? 'plat préparé retiré' : 'restes retirés';
        }

        if (($offer = $this->leftoversOffer($meal)) && $this->storeLeftovers($meal, $offer['portions'])) {
            $parts[] = $offer['portions'].' portion'.($offer['portions'] > 1 ? 's' : '').' au réfrigérateur';
        }

        $meal->forceFill(['stock_state' => 'done'])->save();

        return $parts;
    }

    public function hasDeduction(PlannedMeal $meal): bool
    {
        return $this->deductionMovements($meal)->isNotEmpty();
    }

    /**
     * Annule les retraits d'un repas (on décoche « mangé ») : chaque article revient à son état d'avant,
     * sauf s'il a été modifié depuis.
     *
     * @return array{restored: int, skipped: int}
     */
    public function revert(PlannedMeal $meal): array
    {
        $restored = 0;
        $skipped = 0;

        DB::transaction(function () use ($meal, &$restored, &$skipped) {
            foreach ($this->deductionMovements($meal)->sortByDesc('id') as $movement) {
                if ($this->stock->isUndoable($movement) || $this->isLatestOnItem($movement)) {
                    $this->forceUndo($movement);
                    $restored++;
                } else {
                    $skipped++;
                }
            }
        });

        $meal->forceFill(['stock_state' => null])->save();

        return ['restored' => $restored, 'skipped' => $skipped];
    }

    /* ================================================================ Restes */

    /**
     * Restes à proposer au réfrigérateur quand un repas est mangé (R7) : portions non mangées et non planifiées ailleurs.
     *
     * @return array{portions: float, label: string, expires_on: string}|null
     */
    public function leftoversOffer(PlannedMeal $meal): ?array
    {
        if ($meal->type !== MealType::Recipe || ! $meal->recipe) {
            return null;
        }

        $planner = app(WeekPlanner::class);
        $portions = max(0.0, round($meal->servings - min($meal->servings, $planner->dinersOf($meal)), 1));

        if ($portions <= 0 || StockItem::active()->where('planned_meal_id', $meal->id)->exists()) {
            return null;
        }

        return [
            'portions' => $portions,
            'label' => 'Restes de '.mb_strtolower(mb_substr($meal->recipe->title, 0, 1)).mb_substr($meal->recipe->title, 1),
            'expires_on' => Carbon::today()->addDays(StockDefaults::PREPARED_FRIDGE_DAYS)->toDateString(),
        ];
    }

    public function storeLeftovers(PlannedMeal $meal, int|float $portions): ?StockItem
    {
        $offer = $this->leftoversOffer($meal);

        if (! $offer || $portions < 0.5) {
            return null;
        }

        return $this->stock->forMeal($meal->id)->add([
            'label' => $offer['label'],
            'planned_meal_id' => $meal->id,
            'quantity' => min($portions, $offer['portions']),
            'unit_id' => Unit::firstWhere('code', 'portion')?->id,
            'expires_on' => $offer['expires_on'],
            'expiry_type' => 'dlc',
        ]);
    }

    /**
     * Repas « restes » mangé : plat préparé correspondant encore en stock.
     *
     * @return array{stock_item_id: int, label: string, take: float, left: float|null}|null
     */
    public function leftoverConsumption(PlannedMeal $meal): ?array
    {
        // Plat cuisiné à l'avance (14.8) : on mange ce qui a été rangé, le reste devient des restes.
        if ($meal->type === MealType::Recipe && $meal->isPrepared()) {
            $item = StockItem::active()->where('planned_meal_id', $meal->id)->whereNull('ingredient_id')->latest('id')->first();
            $take = $item ? min($meal->servings, app(WeekPlanner::class)->dinersOf($meal)) : 0;
        } elseif ($meal->type === MealType::Leftover && $meal->leftover_of_id) {
            $item = StockItem::active()->where('planned_meal_id', $meal->leftover_of_id)->first();
            $take = $meal->servings;
        } else {
            return null;
        }

        if (! $item) {
            return null;
        }

        return [
            'stock_item_id' => $item->id,
            'label' => $item->name(),
            'take' => $take,
            'left' => $item->quantity === null ? null : max(0, (float) $item->quantity - $take),
        ];
    }

    public function consumeLeftovers(PlannedMeal $meal): bool
    {
        $offer = $this->leftoverConsumption($meal);
        $item = $offer ? StockItem::active()->find($offer['stock_item_id']) : null;

        if (! $item) {
            return false;
        }

        $stock = $this->stock->forMeal($meal->id);
        $offer['left'] === null || $offer['left'] <= 0 ? $stock->finish($item) : $stock->setQuantity($item, $offer['left']);

        return true;
    }

    /* ================================================================ Retrait ingrédient par ingrédient (22.6) */

    /**
     * Retire un seul ingrédient d'un repas (mode cuisine, case cochée).
     *
     * @return string|null ce qui a été retiré (« −3 œufs »), ou null si rien n'était en stock
     */
    public function applyIngredient(PlannedMeal $meal, int $ingredientId): ?string
    {
        $row = collect($this->plan($meal))->firstWhere('ingredient_id', $ingredientId);

        if (! $row || $row['status'] === 'presence' || collect($row['allocations'])->whereNotNull('take')->isEmpty()) {
            return null;
        }

        $this->apply($meal, [[...$row, 'include' => true]]);

        return $row['text'];
    }

    /** @return list<int> ingrédients déjà retirés du stock pour ce repas */
    public function deductedIngredientIds(PlannedMeal $meal): array
    {
        if (! $meal->exists) {
            return [];
        }

        return $this->deductionMovements($meal)
            ->whereIn('type', [MovementType::Consume, MovementType::Adjust])
            ->map(fn (StockMovement $m) => StockItem::find($m->stock_item_id)?->ingredient_id)
            ->filter()->unique()->map(fn ($id) => (int) $id)->values()->all();
    }

    /* ================================================================ Outils */

    /** @return Collection<int, StockMovement> retraits du repas pas encore annulés */
    private function deductionMovements(PlannedMeal $meal): Collection
    {
        $reverted = StockMovement::query()->whereNotNull('reverts_movement_id')->pluck('reverts_movement_id');

        return StockMovement::query()
            ->where('planned_meal_id', $meal->id)
            ->whereIn('type', [MovementType::Consume->value, MovementType::Adjust->value, MovementType::In->value])
            ->whereNotIn('id', $reverted)
            ->get();
    }

    private function isLatestOnItem(StockMovement $movement): bool
    {
        return StockMovement::query()->where('stock_item_id', $movement->stock_item_id)->max('id') === $movement->id;
    }

    /** Annulation sans limite de délai (retrait lié à un repas). */
    private function forceUndo(StockMovement $movement): void
    {
        $item = StockItem::find($movement->stock_item_id);

        if (! $item) {
            return;
        }

        if ($movement->type === MovementType::In) {
            $item->finished_at = Carbon::now();   // restes rangés : l'article disparaît
        } else {
            foreach ($movement->snapshot ?? [] as $field => $value) {
                $item->{$field} = $value;
            }
        }

        $item->save();

        StockMovement::create([
            'stock_item_id' => $item->id,
            'ingredient_id' => $item->ingredient_id,
            'label' => $item->name(),
            'type' => MovementType::Undo,
            'quantity' => $movement->quantity === null ? null : -(float) $movement->quantity,
            'unit_id' => $movement->unit_id,
            'reverts_movement_id' => $movement->id,
            'planned_meal_id' => $movement->planned_meal_id,
            'user_id' => auth()->id(),
        ]);
    }

    /**
     * Besoin total de l'ingrédient pour le repas, dans l'unité de la première ligne (R1).
     *
     * @return array{0: float|null, 1: Unit|null}
     */
    public function need(Collection $lines, int $recipeServings, int $mealServings, Ingredient $ingredient): array
    {
        $unit = $lines->first()->unit;
        $total = null;

        foreach ($lines->reject(fn ($l) => $l->is_optional && ! $lines->every(fn ($x) => $x->is_optional)) as $line) {
            $quantity = $this->scaler->scale($line->quantity, max(1, $recipeServings), $mealServings);

            if ($quantity === null) {
                continue;
            }

            $converted = $this->availability->convert($quantity, $line->unit, $unit, $ingredient);
            $total = ($total ?? 0.0) + ($converted ?? 0.0);
        }

        return [$total, $unit];
    }

    private function isFinished(StockItem $item, float $left): bool
    {
        if ($left <= 0.0005) {
            return true;
        }

        if ($item->unit?->type === UnitType::Piece) {
            return $left < 1;
        }

        $initial = (float) ($item->initial_quantity ?? $item->quantity);

        return $initial > 0 && $left / $initial <= self::FINISH_RATIO;
    }

    private function row(Ingredient $ingredient, bool $optional, string $status, string $need, bool $include, bool $finishUnknown, array $allocations, string $text): array
    {
        return [
            'key' => 'i'.$ingredient->id,
            'ingredient_id' => $ingredient->id,
            'name' => $ingredient->name,
            'optional' => $optional,
            'mode' => $ingredient->stock_mode->value,
            'need' => $need,
            'status' => $status,
            'include' => $include,
            'finish_unknown' => $finishUnknown,
            'allocations' => $allocations,
            'text' => $text,
        ];
    }
}
