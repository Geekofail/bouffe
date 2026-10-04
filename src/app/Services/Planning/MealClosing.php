<?php

namespace App\Services\Planning;

use App\Enums\MealType;
use App\Models\PlannedMeal;
use App\Services\Stock\MealStockService;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Clôture des repas passés (lot 21 — 22.1, règle R23).
 *
 * Le stock ne peut suivre les repas que si l'on sait lesquels ont été mangés. Ceux qui ne sont
 * ni cochés ni marqués « pas fait » restent **à clôturer** : Bouffe le demande dans la cloche et
 * sur l'accueil, ou, si on l'a choisi, les marque mangés tout seul au bout de quelques jours.
 */
class MealClosing
{
    /** Au-delà, un repas n'est plus proposé (ni clôturé automatiquement) : trop vieux pour être fiable. */
    public const MAX_AGE_DAYS = 14;

    /** Heure à partir de laquelle les repas de la veille sont proposés. */
    public const MORNING_HOUR = 7;

    public function __construct(
        private readonly WeekPlanner $planner,
        private readonly MealStockService $stock,
    ) {}

    /**
     * Repas à clôturer, du plus récent au plus ancien.
     *
     * @return Collection<int, PlannedMeal>
     */
    public function pending(?Carbon $now = null): Collection
    {
        $now ??= now();
        // Avant 7 h, « hier » n'est pas encore terminé dans la tête de tout le monde.
        $until = $now->hour < self::MORNING_HOUR ? $now->copy()->subDays(2) : $now->copy()->subDay();

        return PlannedMeal::query()
            ->whereIn('type', [MealType::Recipe->value, MealType::Leftover->value])
            ->whereNull('cooked_at')->whereNull('skipped_at')
            ->whereBetween('date', [$now->copy()->subDays(self::MAX_AGE_DAYS)->toDateString(), $until->toDateString()])
            ->with('recipe', 'leftoverOf.recipe', 'slot')
            ->orderByDesc('date')->orderByDesc('meal_slot_id')
            ->get();
    }

    /** Délai de clôture automatique (0 = on demande toujours). */
    public function autoDays(): int
    {
        return Settings::int('stock.auto_close_days', 0);
    }

    /** « Mangé » : le retrait du stock suit le réglage du foyer (la fenêtre est ouverte par l'écran). */
    public function markEaten(PlannedMeal $meal): PlannedMeal
    {
        if (! $meal->cooked_at) {
            $this->planner->toggleCooked($meal);
        }

        return $meal->fresh();
    }

    /** « Pas mangé » : rien n'est retiré, plus d'alerte. */
    public function skip(PlannedMeal $meal): void
    {
        $meal->update(['skipped_at' => now(), 'cooked_at' => null]);
        $meal->reminders()->delete();
    }

    public function unskip(PlannedMeal $meal): void
    {
        $meal->update(['skipped_at' => null]);
    }

    /**
     * Replace un repas non fait dans la prochaine case libre du même créneau (7 jours au plus).
     *
     * @return PlannedMeal|null le repas replacé, ou null s'il n'y a pas de place
     */
    public function reschedule(PlannedMeal $meal, ?Carbon $today = null): ?PlannedMeal
    {
        $today ??= Carbon::today();

        for ($i = 0; $i < 7; $i++) {
            $date = $today->copy()->addDays($i);
            $taken = PlannedMeal::query()->whereDate('date', $date->toDateString())->where('meal_slot_id', $meal->meal_slot_id)->exists();

            if (! $taken) {
                return DB::transaction(function () use ($meal, $date) {
                    $copy = $meal->replicate(['cooked_at', 'skipped_at', 'closed_automatically', 'stock_state', 'prepared_at']);
                    $copy->date = $date;
                    $copy->position = 1;
                    $copy->save();

                    // L'original reste « pas fait » à sa date : l'historique ne ment pas.
                    $meal->update(['skipped_at' => $meal->skipped_at ?? now()]);

                    return $copy;
                });
            }
        }

        return null;
    }

    /**
     * Repas encore à clôturer d'un jour précis (« hier », 37.2).
     *
     * @return Collection<int, PlannedMeal>
     */
    public function pendingOn(Carbon $day, ?Carbon $now = null): Collection
    {
        return $this->pending($now)->filter(fn (PlannedMeal $meal) => $meal->date->isSameDay($day))->values();
    }

    /**
     * « Comme prévu » (lot 37, 37.2) : les repas sont marqués mangés **avec** le retrait du stock,
     * comme la clôture automatique (sauf si le foyer a choisi de ne jamais toucher au stock).
     *
     * @param  Collection<int, PlannedMeal>  $meals
     * @return int repas clôturés
     */
    public function closeAsPlanned(Collection $meals): int
    {
        $withStock = Settings::get('stock.deduction_mode', 'ask') !== 'never';
        $count = 0;

        foreach ($meals as $meal) {
            if ($meal->cooked_at || $meal->skipped_at || ! in_array($meal->type, [MealType::Recipe, MealType::Leftover], true)) {
                continue;
            }

            DB::transaction(function () use ($meal, $withStock) {
                $this->planner->toggleCooked($meal);

                if ($withStock) {
                    $this->stock->applyDefault($meal->fresh());
                }

                // Un repas mangé n'a plus de rappels : supprimés dans le geste, pour que « Annuler » les remette.
                $meal->reminders()->delete();
            });

            $count++;
        }

        return $count;
    }

    /**
     * Ce que « comme prévu » va toucher, pour « Annuler » (R32) : les repas, les articles du stock
     * qui vont être retirés (et les restes rangés au réfrigérateur), les mouvements créés, et les
     * recettes « à tester » qui ne le seront plus.
     *
     * @param  Collection<int, PlannedMeal>  $meals
     */
    public function trackForUndo(Collection $meals, \App\Services\Undo\UndoRecorder $recorder): void
    {
        $ids = $meals->pluck('id')->map(fn ($id) => (int) $id)->all();
        $sources = $meals->pluck('leftover_of_id')->filter()->map(fn ($id) => (int) $id)->all();

        $items = $meals->flatMap(fn (PlannedMeal $meal) => collect($this->stock->plan($meal))->flatMap(fn (array $row) => collect($row['allocations'] ?? [])->pluck('stock_item_id')))
            ->merge(\App\Models\StockItem::query()->whereIn('planned_meal_id', [...$ids, ...$sources])->pluck('id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();

        $lastItem = (int) \App\Models\StockItem::query()->max('id');
        $lastMovement = (int) \App\Models\StockMovement::query()->max('id');

        $recorder->track('planned_meals', $ids);
        $recorder->track('stock_items', $items);
        // Restes rangés et mouvements : créés par le geste, supprimés par « Annuler ».
        $recorder->track('stock_items', fn () => \App\Models\StockItem::query()->where('id', '>', $lastItem)->pluck('id'));
        $recorder->track('stock_movements', fn () => \App\Models\StockMovement::query()->where('id', '>', $lastMovement)->pluck('id'));

        $toTest = $meals->map(fn (PlannedMeal $meal) => $meal->eatenRecipe())->filter(fn ($recipe) => $recipe?->is_to_test)->pluck('id')->unique()->values()->all();

        if ($toTest !== []) {
            $recorder->track('recipes', $toTest);
        }
    }

    /**
     * Clôture automatique (tâche planifiée) : les repas en attente depuis N jours sont marqués mangés
     * et le retrait du stock est appliqué sans fenêtre.
     *
     * @return int repas clôturés
     */
    public function autoClose(?Carbon $now = null): int
    {
        $days = $this->autoDays();

        if ($days === 0) {
            return 0;
        }

        $now ??= now();
        $limit = $now->copy()->subDays($days)->toDateString();
        $count = 0;

        foreach ($this->pending($now)->filter(fn (PlannedMeal $m) => $m->date->toDateString() <= $limit) as $meal) {
            DB::transaction(function () use ($meal) {
                $this->planner->toggleCooked($meal);
                $meal->forceFill(['closed_automatically' => true])->save();

                if (Settings::get('stock.deduction_mode', 'ask') !== 'never') {
                    $this->stock->applyDefault($meal->fresh());
                }
            });

            $count++;
        }

        return $count;
    }

    /**
     * Repas clôturés automatiquement ces derniers jours (annulables depuis la cloche).
     *
     * @return Collection<int, PlannedMeal>
     */
    public function recentlyAutoClosed(int $days = 3): Collection
    {
        return PlannedMeal::query()
            ->where('closed_automatically', true)
            ->whereNotNull('cooked_at')
            ->where('cooked_at', '>=', now()->subDays($days))
            ->with('recipe', 'leftoverOf.recipe', 'slot')
            ->orderByDesc('date')
            ->get();
    }

    /** « Pas mangé, en fait » : annule une clôture automatique, remet le stock, repas marqué non fait. */
    public function undoAutoClose(PlannedMeal $meal): void
    {
        DB::transaction(function () use ($meal) {
            $this->stock->revert($meal);
            $meal->update(['cooked_at' => null, 'closed_automatically' => false, 'stock_state' => null, 'skipped_at' => now()]);
        });
    }
}
