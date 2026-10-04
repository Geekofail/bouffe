<?php

namespace App\Livewire\Layout;

use App\Models\Reminder;
use App\Services\Planning\PrepReminderPlanner;
use App\Services\Stock\ExpiryAlerts;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Centre de notifications (19.1) : la cloche du menu.
 *
 * Rassemble ce qui demande une action aujourd'hui — les rappels de préparation anticipée (14.6)
 * et les produits à consommer (lot 8) — pour qu'il n'y ait qu'un seul endroit à regarder.
 */
class Notifications extends Component
{
    use \App\Livewire\Concerns\ClosesMeals;

    public bool $open = false;

    /** Rappels des trois prochains jours (au-delà, ce n'est pas encore utile). */
    public const HORIZON_DAYS = 3;

    #[On('open-notifications')]
    public function show(): void
    {
        $this->open = true;
        unset($this->reminders, $this->expiring, $this->newWishes, $this->awaitingReactions, $this->readyLists, $this->leftovers, $this->toClose, $this->stockPending, $this->autoClosed);
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function toggle(): void
    {
        $this->open ? $this->close() : $this->show();
    }

    public function markDone(int $reminderId, PrepReminderPlanner $planner): void
    {
        $planner->markDone(Reminder::findOrFail($reminderId));
        unset($this->reminders);
        $this->dispatch('reminders-changed');
    }

    public function ignore(int $reminderId, PrepReminderPlanner $planner): void
    {
        $planner->markIgnored(Reminder::findOrFail($reminderId));
        unset($this->reminders);
        $this->dispatch('reminders-changed');
    }

    /* ---------------------------------------------------------------- 18.2 · envies des autres */

    public function markWishesSeen(): void
    {
        auth()->user()->setPreference('wishes_seen_at', now()->toIso8601String());
        unset($this->newWishes);
    }

    /* ---------------------------------------------------------------- 18.4 · réactions */

    public function react(int $mealId, int $value): void
    {
        app(\App\Services\Planning\MealFeedback::class)
            ->toggle(\App\Models\PlannedMeal::findOrFail($mealId), auth()->user(), $value);

        unset($this->awaitingReactions);
        $this->dispatch('reactions-changed');
    }

    #[On('reminders-changed')]
    #[On('stock-changed')]
    #[On('wishes-changed')]
    #[On('reactions-changed')]
    public function refreshAll(): void
    {
        unset($this->reminders, $this->expiring, $this->newWishes, $this->awaitingReactions, $this->readyLists, $this->leftovers, $this->toClose, $this->stockPending, $this->autoClosed);
    }

    /* ---------------------------------------------------------------- Données */

    /** @return Collection<int, Reminder> */
    #[Computed]
    public function reminders(): Collection
    {
        return app(PrepReminderPlanner::class)->due(Carbon::today()->addDays(self::HORIZON_DAYS)->endOfDay());
    }

    /** Produits à consommer (lot 8), limités à ce qui presse. */
    #[Computed]
    public function expiring(): Collection
    {
        return Settings::bool('stock.nav_badge', true)
            ? app(ExpiryAlerts::class)->items()->take(5)
            : collect();
    }

    /** Envies notées par quelqu'un d'autre depuis la dernière visite (18.2). @return Collection<int, \App\Models\Wish> */
    #[Computed]
    public function newWishes(): Collection
    {
        $since = auth()->user()->preference('wishes_seen_at');

        return \App\Models\Wish::query()->open()
            ->where('user_id', '!=', auth()->id())
            ->when($since, fn ($q) => $q->where('created_at', '>', Carbon::parse($since)))
            ->with('recipe', 'user')
            ->latest('id')->limit(5)->get();
    }

    /** Repas mangés sans réaction de ma part (18.4). @return Collection<int, \App\Models\PlannedMeal> */
    #[Computed]
    public function awaitingReactions(): Collection
    {
        return app(\App\Services\Planning\MealFeedback::class)->awaiting(auth()->user())->take(3);
    }

    /** Listes de courses préparées par quelqu'un d'autre (19.1, lot 20). */
    #[Computed]
    public function readyLists(): Collection
    {
        $seen = auth()->user()->preference('lists_seen_at');

        return app(\App\Services\Notifications\HouseholdNews::class)->readyLists(auth()->user())
            ->when($seen, fn ($lists) => $lists->filter(fn ($list) => $list->created_at->gt(Carbon::parse($seen))))
            ->values();
    }

    public function markListsSeen(): void
    {
        auth()->user()->setPreference('lists_seen_at', now()->toIso8601String());
        unset($this->readyLists);
    }

    /** Restes au réfrigérateur sans repas prévu (19.1, lot 20). */
    #[Computed]
    public function leftovers(): Collection
    {
        return app(\App\Services\Notifications\HouseholdNews::class)->leftoversToPlan()->take(3);
    }

    /** Repas passés à clôturer (R23). */
    #[Computed]
    public function toClose(): Collection
    {
        return app(\App\Services\Planning\MealClosing::class)->pending()->take(5);
    }

    /** Repas mangés dont le retrait du stock est resté en attente (22.2). */
    #[Computed]
    public function stockPending(): Collection
    {
        return \App\Models\PlannedMeal::query()
            ->where('stock_state', 'pending')->whereNotNull('cooked_at')
            ->where('date', '>=', Carbon::today()->subDays(14)->toDateString())
            ->with('recipe', 'leftoverOf.recipe', 'slot')
            ->orderByDesc('date')->limit(5)->get();
    }

    /** Clôturés automatiquement ces trois derniers jours, annulables. */
    #[Computed]
    public function autoClosed(): Collection
    {
        return app(\App\Services\Planning\MealClosing::class)->recentlyAutoClosed()->take(5);
    }

    #[On('reschedule-meal')]
    public function reschedule(int $mealId): void
    {
        $copy = app(\App\Services\Planning\MealClosing::class)->reschedule(\App\Models\PlannedMeal::findOrFail($mealId));

        $this->dispatch('notify', ...($copy
            ? ['message' => 'Replacé '.$copy->date->locale('fr')->isoFormat('dddd D').' · '.mb_strtolower($copy->slot?->name ?? '').'.']
            : ['type' => 'warning', 'message' => 'Pas de place libre sur ce créneau dans les 7 prochains jours : à replacer depuis le planning.']));
        $this->afterClosing();
    }

    #[Computed]
    public function count(): int
    {
        return $this->reminders->count() + $this->expiring->count() + $this->newWishes->count() + $this->awaitingReactions->count()
            + $this->readyLists->count() + $this->leftovers->count() + $this->toClose->count() + $this->stockPending->count();
    }

    /** Rappels en retard : la cloche passe en rouge. */
    #[Computed]
    public function lateCount(): int
    {
        return $this->reminders->filter(fn (Reminder $r) => $r->isLate())->count();
    }

    public function render()
    {
        return view('livewire.layout.notifications');
    }
}
