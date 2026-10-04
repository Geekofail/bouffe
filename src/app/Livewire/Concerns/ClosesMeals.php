<?php

namespace App\Livewire\Concerns;

use App\Livewire\Stock\MealStockDialog;
use App\Models\PlannedMeal;
use App\Services\Planning\MealClosing;

/**
 * Réponses à « C'était mangé ? » (lot 21, R23), partagées par la cloche et l'accueil.
 */
trait ClosesMeals
{
    public function closeEaten(int $mealId): void
    {
        $meal = app(MealClosing::class)->markEaten(PlannedMeal::findOrFail($mealId));
        $this->dispatch('meal-cooked', mealId: $meal->id, cooked: true)->to(MealStockDialog::class);
        $this->afterClosing();
    }

    public function closeSkipped(int $mealId): void
    {
        app(MealClosing::class)->skip(PlannedMeal::findOrFail($mealId));
        $this->dispatch('notify', message: 'Noté : pas mangé, rien n\'a été retiré du stock.', action: ['label' => 'Replacer', 'event' => 'reschedule-meal', 'params' => ['mealId' => $mealId]]);
        $this->afterClosing();
    }

    /** Rattrapage (première utilisation, retour de vacances) : tout est marqué mangé, le stock n'est pas touché. */
    public function closeAllWithoutStock(): void
    {
        $closing = app(MealClosing::class);
        $meals = $closing->pending();

        foreach ($meals as $meal) {
            $closing->markEaten($meal)->forceFill(['stock_state' => 'ignored'])->save();
        }

        $this->dispatch('notify', message: $meals->count().' repas marqués mangés ; le stock n\'a pas été modifié.');
        $this->afterClosing();
    }

    /**
     * « Hier comme prévu » (lot 37, 37.2) : les repas d'hier encore à clôturer sont marqués mangés,
     * **avec** le retrait du stock ; « Annuler » remet tout (R32). La classe utilise OffersUndo.
     */
    public function closeYesterday(): void
    {
        $closing = app(MealClosing::class);
        $this->closeAsPlannedWithUndo($closing->pendingOn(now()->subDay()), 'Repas d\'hier marqués mangés');
    }

    /** @param  \Illuminate\Support\Collection<int, PlannedMeal>  $meals */
    protected function closeAsPlannedWithUndo(\Illuminate\Support\Collection $meals, string $label): void
    {
        if (! auth()->user()->canEdit()) {
            $this->dispatch('notify', type: 'warning', message: 'Votre compte est en consultation : cette modification est réservée aux comptes complets.');

            return;
        }

        if ($meals->isEmpty()) {
            $this->dispatch('notify', message: 'Plus rien à clôturer.');

            return;
        }

        $closing = app(MealClosing::class);

        $this->undoable('meals.as-planned', $label, function (\App\Services\Undo\UndoRecorder $r) use ($closing, $meals) {
            $closing->trackForUndo($meals, $r);

            return $closing->closeAsPlanned($meals);
        }, fn (int $count) => $count === 0 ? null : $count.' repas marqué'.($count > 1 ? 's' : '').' mangé'.($count > 1 ? 's' : '')
            .(\App\Support\Settings::get('stock.deduction_mode', 'ask') !== 'never' ? ', stock mis à jour.' : '.'));

        $this->dispatch('stock-changed');
        $this->afterClosing();
    }

    /** Après « Annuler » (R32) : la page se redessine. */
    #[\Livewire\Attributes\On('bouffe-undone')]
    public function refreshAfterUndo(): void
    {
        $this->afterClosing();
    }

    public function settleStock(int $mealId): void
    {
        $this->dispatch('settle-meal-stock', mealId: $mealId)->to(MealStockDialog::class);
    }

    public function ignoreStock(int $mealId): void
    {
        PlannedMeal::whereKey($mealId)->update(['stock_state' => 'ignored']);
        $this->afterClosing();
    }

    public function undoAutoClose(int $mealId): void
    {
        app(MealClosing::class)->undoAutoClose(PlannedMeal::findOrFail($mealId));
        $this->dispatch('notify', message: 'Repas remis en « pas mangé » ; le stock est revenu.');
        $this->dispatch('stock-changed');
        $this->afterClosing();
    }

    protected function afterClosing(): void
    {
        $this->dispatch('reminders-changed');
    }
}
