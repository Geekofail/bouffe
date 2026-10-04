<?php

namespace App\Services\Kitchen;

use App\Models\MealTask;
use App\Models\PlannedMeal;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Qui fait quoi ce soir (lot 41, 41.3).
 *
 * Dans « Cuisiner le repas » (31.3), chaque plat revient à quelqu'un — celui qui le cuisine,
 * `planned_meals.cook_user_id` (14.7), « Pierre : gratin ; Monique : dessert » — et une étape peut
 * être confiée à une autre personne. Les étapes cochées « faite » sont partagées : sur deux
 * téléphones, chacun voit où en est l'autre.
 */
class MealTasks
{
    public const TOGETHER = 'ensemble';

    /**
     * Pour chaque étape (clé « repas-étape ») : à qui elle revient et si elle est faite.
     *
     * @param  Collection<int, PlannedMeal>  $meals
     * @param  Collection<int, array>  $tasks  étapes de MealCookPlan::build()
     * @return array<string, array{user_id: int|null, together: bool, override: bool, done: bool, done_by: string|null}>
     */
    public function states(Collection $meals, Collection $tasks): array
    {
        $rows = MealTask::query()->whereIn('planned_meal_id', $meals->pluck('id'))->get()
            ->keyBy(fn (MealTask $task) => $task->planned_meal_id.'-'.$task->step_number);
        $names = User::query()->whereIn('id', $rows->pluck('done_by')->filter()->unique())->pluck('name', 'id');
        $byMeal = $meals->keyBy('id');
        $states = [];

        foreach ($tasks as $task) {
            $row = $rows->get($task['key']);
            $meal = $byMeal->get($task['meal_id']);
            $override = $row?->user_id !== null;

            $states[$task['key']] = [
                'user_id' => $override ? (int) $row->user_id : ($meal?->cook_together ? null : ($meal?->cook_user_id ? (int) $meal->cook_user_id : null)),
                'together' => ! $override && (bool) $meal?->cook_together,
                'override' => $override,
                'done' => $row?->done_at !== null,
                'done_by' => $row?->done_by ? ($names[$row->done_by] ?? null) : null,
            ];
        }

        return $states;
    }

    /** Qui cuisine le plat : une personne du foyer, « ensemble », ou personne (vide). */
    public function assignDish(PlannedMeal $meal, string $who): void
    {
        $together = $who === self::TOGETHER;
        $userId = $together || $who === '' ? null : $this->member($who);

        $meal->update(['cook_user_id' => $userId, 'cook_together' => $together]);
    }

    /** Confie une étape à quelqu'un d'autre ; vide : elle revient à celui qui cuisine le plat. */
    public function assignStep(PlannedMeal $meal, int $step, string $who): void
    {
        $userId = $who === '' ? null : $this->member($who);
        $row = MealTask::query()->firstOrNew(['planned_meal_id' => $meal->id, 'step_number' => $step]);
        $row->user_id = $userId;

        if (! $row->exists && $userId === null) {
            return;
        }

        $row->user_id === null && $row->done_at === null ? ($row->exists ? $row->delete() : null) : $row->save();
    }

    /** « Faite » / pas faite, pour tout le monde. @return bool faite après le geste */
    public function toggleDone(PlannedMeal $meal, int $step, ?User $by = null): bool
    {
        $row = MealTask::query()->firstOrNew(['planned_meal_id' => $meal->id, 'step_number' => $step]);
        $done = $row->done_at === null;
        $row->forceFill(['done_at' => $done ? now() : null, 'done_by' => $done ? ($by ?? auth()->user())?->id : null]);

        $row->user_id === null && ! $done ? ($row->exists ? $row->delete() : null) : $row->save();

        return $done;
    }

    private function member(string $who): int
    {
        $id = (int) $who;

        if (! User::query()->inHousehold()->whereKey($id)->exists()) {
            throw new InvalidArgumentException('Cette personne ne fait pas partie du foyer.');
        }

        return $id;
    }
}
