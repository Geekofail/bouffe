<?php

namespace App\Livewire\Concerns;

use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Services\Planning\WeekPlanner;
use Illuminate\Support\Carbon;

/**
 * « Restes à finir » (lot 21) : placer d'un geste les portions en trop d'un repas, partagé par
 * l'accueil et la page « Ce soir » (lot 37).
 */
trait PlacesLeftovers
{
    /** « Restes à finir » : place les portions restantes dans la première case libre à partir de demain soir. */
    public function placeLeftovers(int $mealId, WeekPlanner $planner): void
    {
        $source = PlannedMeal::with('recipe')->findOrFail($mealId);
        $slots = MealSlot::query()->active()->ordered()->get();
        $tomorrow = Carbon::today()->addDay();
        $occupied = PlannedMeal::query()->whereBetween('date', [$tomorrow->toDateString(), $tomorrow->copy()->addDay()->toDateString()])
            ->get()->map(fn (PlannedMeal $m) => $m->date->toDateString().'|'.$m->meal_slot_id)->flip();

        // Demain soir (dernier créneau), puis les autres créneaux de demain, puis après-demain.
        $candidates = collect([$tomorrow, $tomorrow->copy()->addDay()])
            ->flatMap(fn (Carbon $day) => $slots->reverse()->map(fn (MealSlot $slot) => [$day, $slot]));
        $cell = $candidates->first(fn (array $c) => ! $occupied->has($c[0]->toDateString().'|'.$c[1]->id));

        if (! $cell) {
            $this->dispatch('notify', type: 'warning', message: 'Pas de case libre demain ni après-demain : placez les restes depuis le planning.');

            return;
        }

        try {
            $meal = $planner->addLeftover($cell[0], $cell[1], $source);
        } catch (\InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());

            return;
        }

        $this->dispatch('notify', message: 'Restes de « '.$source->recipe?->title.' » placés '.$meal->date->locale('fr')->isoFormat('dddd').' · '.mb_strtolower($cell[1]->name).'.');
    }
}
