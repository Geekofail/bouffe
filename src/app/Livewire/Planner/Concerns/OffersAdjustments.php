<?php

namespace App\Livewire\Planner\Concerns;

use App\Livewire\Planner\MealPicker;
use App\Livewire\Planner\OccasionEditor;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Services\Planning\OccasionService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;

/**
 * Planning — après un ajout ou un changement de convives : proposer de placer les restes ou
 * d'adapter les portions (découpé de Week au lot 36).
 */
trait OffersAdjustments
{
    public function openPicker(string $date, int $slotId): void
    {
        $this->dispatch('open-meal-picker', date: $date, slotId: $slotId)->to(MealPicker::class);
    }

    /** Le sélecteur a ajouté un repas. */
    #[On('meal-planned')]
    public function mealPlanned(int $mealId): void
    {
        unset($this->meals, $this->mealConflicts);
        $meal = PlannedMeal::with('slot')->find($mealId);

        if ($meal) {
            $this->offerLeftovers($meal);
        }
    }

    private function offerLeftovers(PlannedMeal $meal): void
    {
        $remaining = $this->planner()->remainingLeftovers($meal);
        // Lot 32 : une demi-portion qui reste ne fait pas un repas de restes.
        $next = $remaining >= 1 ? $this->planner()->nextSlot($meal->date, $meal->meal_slot_id) : null;

        $this->leftoverOffer = $next ? [
            'meal_id' => $meal->id,
            'date' => $next['date']->toDateString(),
            'slot_id' => $next['slot']->id,
            'slot' => $next['slot']->name,
            'servings' => min($remaining, app(\App\Services\Planning\OccasionService::class)->servingsAt($next['date'], $next['slot'])),
            'remaining' => $remaining,
        ] : null;
    }

    public function acceptLeftoverOffer(): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        if (! $this->leftoverOffer) {
            return;
        }

        $offer = $this->leftoverOffer;
        $this->leftoverOffer = null;

        $this->attempt(function () use ($offer) {
            $source = PlannedMeal::findOrFail($offer['meal_id']);
            $this->planner()->addLeftover($offer['date'], $offer['slot_id'], $source, $offer['servings']);

            $this->dispatch('notify', message: 'Restes ajoutés '.Carbon::parse($offer['date'])->locale('fr')->isoFormat('dddd').' '.mb_strtolower($offer['slot']).'.');
        });
    }

    public function dismissLeftoverOffer(): void
    {
        $this->leftoverOffer = null;
    }

    public function openOccasion(string $date, int $slotId): void
    {
        $this->selectedMealId = null;
        $this->dispatch('open-occasion', date: $date, slotId: $slotId)->to(OccasionEditor::class);
    }

    /** La fenêtre Convives a enregistré : proposer d'adapter les portions des plats déjà prévus. */
    #[On('occasion-saved')]
    public function occasionSaved(string $date, int $slotId, float $before, float $after): void
    {
        unset($this->meals, $this->occasions, $this->mealConflicts);
        $this->servingsOffer = null;

        if ($before === $after) {
            return;
        }

        $count = PlannedMeal::query()->whereDate('date', $date)->where('meal_slot_id', $slotId)
            ->where('type', \App\Enums\MealType::Recipe->value)->count();

        if ($count > 0) {
            $this->servingsOffer = [
                'date' => $date, 'slot_id' => $slotId, 'slot' => (string) MealSlot::find($slotId)?->name,
                'before' => $before, 'after' => $after, 'count' => $count,
            ];
        }
    }

    public function acceptServingsOffer(OccasionService $occasions): void
    {
        if (! $this->servingsOffer) {
            return;
        }

        $offer = $this->servingsOffer;
        $this->servingsOffer = null;
        $adapted = $occasions->adaptServings($offer['date'], $offer['slot_id'], $offer['before'], $offer['after']);
        unset($this->meals, $this->mealConflicts);

        $this->dispatch('notify', ...$adapted === $offer['count']
            ? ['message' => 'Portions adaptées.']
            : ['type' => 'warning', 'message' => 'Portions adaptées sauf pour les plats dont des restes sont déjà placés.']);
    }

    public function dismissServingsOffer(): void
    {
        $this->servingsOffer = null;
    }
}
