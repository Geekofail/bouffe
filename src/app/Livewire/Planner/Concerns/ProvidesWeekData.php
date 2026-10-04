<?php

namespace App\Livewire\Planner\Concerns;

use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Services\Planning\GuestCompatibility;
use App\Services\Planning\OccasionService;
use App\Services\Planning\PlanningRules;
use App\Services\Planning\PrepReminderPlanner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;

/**
 * Planning — données affichées : repas, convives, conflits, stock à consommer, rappels, répartition
 * des repas (découpé de Week au lot 36).
 */
trait ProvidesWeekData
{
    /** Séjours qui touchent la semaine (lot 34) : leurs repas sont dans le séjour, pas ici. */
    #[Computed]
    public function stays(): Collection
    {
        $from = $this->weekStart();

        return \App\Models\Stay::query()
            ->where('starts_on', '<=', $from->copy()->addDays(6)->toDateString())
            ->where('ends_on', '>=', $from->toDateString())
            ->orderBy('starts_on')->get();
    }

    #[Computed]
    public function activeSlots(): Collection
    {
        return MealSlot::query()->active()->ordered()->get();
    }

    /** @return Collection<string, Collection<int, PlannedMeal>> repas groupés par « date|créneau » */
    #[Computed]
    public function meals(): Collection
    {
        return $this->planner()->mealsForWeek($this->weekStart())
            ->groupBy(fn (PlannedMeal $meal) => $meal->date->toDateString().'|'.$meal->meal_slot_id);
    }

    /**
     * Repas dont le stock a été pris par des repas plus proches (lot 21, R24).
     *
     * @return array<int, list<array{ingredient: string, missing: string, taken_by: list<string>}>>
     */
    #[Computed]
    public function stockConflicts(): array
    {
        $weekStart = $this->weekStart();

        if ($weekStart->copy()->addDays(6)->lt(Carbon::today()) || $weekStart->gt(Carbon::today()->addDays(\App\Services\Stock\StockReservations::DAYS))) {
            return [];
        }

        return app(\App\Services\Stock\StockReservations::class)->compute()['meals'];
    }

    /** @return Collection<string, \App\Models\MealOccasion> convives de la semaine par « date|créneau » */
    #[Computed]
    public function occasions(): Collection
    {
        $weekStart = $this->weekStart();

        return app(OccasionService::class)->forRange($weekStart, $weekStart->copy()->addDays(6));
    }

    /** Convives pondérés d'une case. */
    /** « 4 » ou « 4 · 3,5 p. » (personnes, et portions quand elles diffèrent — lot 32). */
    /** Pastille de la case : le nombre de personnes ; le détail « 5 personnes · 5,5 portions » est en infobulle. */
    public function dinersFor(string $cellKey): string
    {
        [$date, $slot] = explode('|', $cellKey) + [null, null];

        return (string) app(OccasionService::class)->people($this->occasions->get($cellKey), $date, (int) $slot);
    }

    public function dinersSummaryFor(string $cellKey): string
    {
        [$date, $slot] = explode('|', $cellKey) + [null, null];

        return app(OccasionService::class)->summary($this->occasions->get($cellKey), $date, (int) $slot);
    }

    /**
     * Conflits entre les plats et les contraintes des invités de leur case.
     *
     * @return array<int, array{level: string, messages: list<string>, items: list<array>}> par identifiant de repas
     */
    #[Computed]
    public function mealConflicts(): array
    {
        $compatibility = app(GuestCompatibility::class);
        $household = app(\App\Services\Planning\HouseholdService::class);
        $default = $household->hasRestrictions() ? $household->eaters(null) : collect();
        $canteen = app(\App\Services\People\CanteenCalendar::class)->anyone();
        $result = [];

        foreach ($this->meals as $cellKey => $cellMeals) {
            $occasion = $this->occasions->get($cellKey);
            [$date, $slot] = explode('|', (string) $cellKey) + [null, null];
            // À table : les personnes du foyer présentes (18.1, 39.1) et les invités (lot 6) ; à la cantine, pas à table (39.2).
            $guests = $occasion || $canteen ? $household->eaters($occasion, $date, (int) $slot) : $default;

            if ($guests->isEmpty()) {
                continue;
            }

            foreach ($cellMeals as $meal) {
                if ($meal->isRecipe() && $meal->recipe && ($conflicts = $compatibility->conflicts($meal->recipe, $guests)) !== []) {
                    $result[$meal->id] = ['level' => $compatibility->worstLevel($conflicts), 'messages' => array_column($conflicts, 'message'), 'items' => $conflicts];
                }
            }
        }

        return $result;
    }

    /**
     * Produits du stock à consommer d'ici la fin de la semaine affichée (semaine en cours uniquement).
     *
     * @return Collection<int, array>
     */
    #[On('stock-changed')]
    public function refreshExpiringStock(): void
    {
        unset($this->expiringStock);
    }

    #[Computed]
    public function expiringStock(): Collection
    {
        $weekStart = $this->weekStart();

        if (! \App\Support\Settings::bool('stock.planning_banner', true) || ! $weekStart->equalTo($this->planner()->weekStart())) {
            return collect();
        }

        return app(\App\Services\Stock\ExpiryAlerts::class)->expiringBy($weekStart->copy()->addDays(6));
    }

    /** Repas planifiés sur des créneaux désactivés (invisibles dans la grille). */
    #[Computed]
    public function hiddenMealsCount(): int
    {
        $activeIds = $this->activeSlots->pluck('id')->all();

        return $this->meals->flatten()->filter(fn (PlannedMeal $m) => ! in_array($m->meal_slot_id, $activeIds, true))->count();
    }

    /** Membres du foyer, pour le choix « qui cuisine » (14.7). */
    #[Computed]
    public function householdMembers(): Collection
    {
        return \App\Models\User::query()->inHousehold()->orderBy('name')->get(['id', 'name']);
    }

    /** Cantine de la semaine (lot 39) : par jour, les personnes qui y mangent ce midi-là. */
    #[Computed]
    public function canteenWeek(): Collection
    {
        $canteen = app(\App\Services\People\CanteenCalendar::class);

        if (! $canteen->anyone()) {
            return collect();
        }

        // La semaine du planning peut commencer un autre jour que le lundi : les deux semaines d'école qu'elle touche.
        $start = $this->weekStart();

        return $canteen->week($start)->union($canteen->week($start->copy()->addDays(6)));
    }

    /** @return Collection<int, array> midis de cantine d'une case (seulement celle du déjeuner) */
    public function canteenAt(string $dateKey, int $slotId): Collection
    {
        if ($this->canteenWeek->isEmpty() || ! app(\App\Services\People\CanteenCalendar::class)->isLunch($slotId)) {
            return collect();
        }

        return $this->canteenWeek->get($dateKey, collect())->filter(fn (array $entry) => $entry['status'] === \App\Models\CanteenMeal::CANTEEN)->values();
    }

    /** Personnes du foyer à qui préparer une gamelle (32.3 ; compte ou pas depuis le lot 39). */
    #[Computed]
    public function lunchboxPeople(): Collection
    {
        return app(\App\Services\Planning\Lunchboxes::class)->people();
    }

    /** Répartition de la semaine : qui cuisine combien de repas (14.7). */
    #[Computed]
    public function cookCounts(): array
    {
        $meals = $this->meals->flatten(1)->filter(fn (PlannedMeal $m) => $m->isRecipe());
        $mine = $meals->filter(fn (PlannedMeal $m) => (int) $m->cook_user_id === (int) auth()->id())->count();
        $together = $meals->where('cook_together', true)->count();
        $unassigned = $meals->filter(fn (PlannedMeal $m) => ! $m->cook_user_id && ! $m->cook_together)->count();

        return [
            'mine' => $mine,
            'together' => $together,
            'others' => $meals->count() - $mine - $together - $unassigned,
            'unassigned' => $unassigned,
            'total' => $meals->count(),
        ];
    }

    /** Rappels de préparation en attente, par case (icône ⏰ du planning, 14.6). */
    #[Computed]
    public function reminders(): Collection
    {
        $planner = app(PrepReminderPlanner::class);
        $weekStart = $this->weekStart();
        $planner->sync($weekStart, $weekStart->copy()->addDays(6));

        return $planner->forWeek($weekStart);
    }

    #[On('reminders-changed')]
    public function refreshReminders(): void
    {
        unset($this->reminders);
    }

    /** Bilan des règles de la semaine (14.4). */
    #[Computed]
    public function rulesStatus(): array
    {
        $rules = app(PlanningRules::class);

        return $rules->isEmpty() ? [] : $rules->weekStatus($this->meals->flatten(1));
    }
}
