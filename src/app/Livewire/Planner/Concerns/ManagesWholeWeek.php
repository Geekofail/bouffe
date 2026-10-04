<?php

namespace App\Livewire\Planner\Concerns;

use App\Models\MealSlot;
use App\Models\PlannedMeal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Planning — gestes sur la semaine : glisser-déposer, copier, vider (découpé de Week au lot 36).
 */
trait ManagesWholeWeek
{
    /**
     * Appelé par wire:sort : $groupId = « AAAA-MM-JJ|idCréneau » de la case de destination.
     */
    public function moveMeal(int|string $mealId, int $position, string $groupId): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        [$date, $slotId] = explode('|', $groupId) + [null, null];

        $this->attempt(function () use ($mealId, $position, $date, $slotId) {
            $meal = PlannedMeal::findOrFail((int) $mealId);
            $from = [$meal->date->toDateString(), (int) $meal->meal_slot_id];

            // Réordonner dans la même case : pas besoin d'annuler.
            if ($from === [Carbon::parse($date)->toDateString(), (int) $slotId]) {
                $this->planner()->move($meal, $date, (int) $slotId, $position);

                return;
            }

            // Lot 30 (R32) : un repas déplacé peut revenir à sa place pendant 10 secondes.
            $label = $meal->label();
            $slot = MealSlot::find((int) $slotId)?->name;
            $when = Carbon::parse($date)->locale('fr')->isoFormat('dddd D').($slot ? ' · '.mb_strtolower($slot) : '');

            $this->undoable('planning.move', "« {$label} » déplacé", function (\App\Services\Undo\UndoRecorder $r) use ($meal, $from, $date, $slotId, $position) {
                $r->track('planned_meals', [...$this->planner()->cellMealIds(...$from), ...$this->planner()->cellMealIds($date, (int) $slotId)]);
                $this->planner()->move($meal, $date, (int) $slotId, $position);
            }, "« {$label} » déplacé à {$when}.");
        });

        unset($this->mealConflicts);
    }

    public function openCopy(): void
    {
        $this->copyTarget = $this->weekStart()->addWeek()->toDateString();
        $this->copyMode = 'add';
        $this->showCopy = true;
    }

    public function closeCopy(): void
    {
        $this->showCopy = false;
        $this->resetErrorBag('copyTarget');
    }

    public function copyWeek(): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $this->validate(['copyTarget' => 'required|date', 'copyMode' => 'in:add,replace'], [], ['copyTarget' => 'semaine de destination']);

        $this->attempt(function () {
            $target = $this->planner()->weekStart($this->copyTarget);
            $replace = $this->copyMode === 'replace';
            $week = $target->locale('fr')->isoFormat('D MMMM');

            // Lot 30 (R32) : « Annuler » retire les copies et, en mode « remplacer », remet la semaine d'avant.
            $this->undoable('planning.copy', 'Semaine copiée vers le '.$week, function (\App\Services\Undo\UndoRecorder $r) use ($target, $replace, $week) {
                $r->track('planned_meals', fn () => $this->planner()->weekMealIds($target));
                $count = $this->planner()->copyWeek($this->weekStart(), $target, $replace);

                return "{$count} repas copié".($count > 1 ? 's' : '').' vers la semaine du '.$week.'.';
            });

            $this->showCopy = false;
            $this->goTo($target);
        }, errorField: 'copyTarget');
    }

    public function clearWeek(): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $ids = $this->planner()->weekMealIds($this->weekStart());
        $this->leftoverOffer = null;
        $this->servingsOffer = null;

        if ($ids === []) {
            $this->dispatch('notify', message: 'La semaine était déjà vide.');

            return;
        }

        // Lot 30 (R32) : la semaine vidée peut être remise telle quelle pendant 10 secondes.
        $week = $this->weekStart()->locale('fr')->isoFormat('D MMMM');
        $this->undoable('planning.clear', 'Semaine du '.$week.' vidée', function (\App\Services\Undo\UndoRecorder $r) use ($ids, $week) {
            $r->track('planned_meals', $ids);
            $count = $this->planner()->clearWeek($this->weekStart());

            app(\App\Services\Activity\ActivityLog::class)->record('planning.cleared', "a vidé la semaine du {$week} ({$count} repas)");

            return "Semaine vidée ({$count} repas retiré".($count > 1 ? 's' : '').').';
        });

        unset($this->meals, $this->mealConflicts);
    }

    /** Semaines proposées pour la copie : 8 semaines à partir de la suivante. */
    public function copyTargets(): Collection
    {
        return collect(range(1, 8))->map(fn (int $i) => $this->weekStart()->addWeeks($i));
    }
}
