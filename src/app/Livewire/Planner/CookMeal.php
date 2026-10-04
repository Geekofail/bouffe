<?php

namespace App\Livewire\Planner;

use App\Models\MealOccasion;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Services\IngredientLineFormatter;
use App\Services\Kitchen\MealTasks;
use App\Services\Planning\MealCookPlan;
use App\Services\Planning\OccasionService;
use App\Services\Planning\WeekPlanner;
use App\Services\QuantityScaler;
use App\Services\Receptions\ReceptionPlanner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Cuisiner un repas complet (lot 31, 31.3) : entrée, plat et dessert dans un seul mode cuisine,
 * étapes entrelacées pour que tout soit prêt à l'heure ; minuteurs nommés par plat.
 */
#[Layout('layouts.cook')]
class CookMeal extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;

    #[Locked]
    public string $date;

    #[Locked]
    public int $slotId;

    /** Heure du repas (« 19:30 ») : modifiable, tout se recalcule. */
    #[Url(as: 'heure', except: '')]
    public string $time = '';

    /** Lot 41 (41.3) : « toutes » les étapes, ou seulement les « miennes ». */
    #[Url(as: 'qui', except: '')]
    public string $who = '';

    public function mount(string $date, int $slot): void
    {
        $this->date = Carbon::parse($date)->toDateString();
        $this->slotId = MealSlot::query()->findOrFail($slot)->id;

        if (! preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $this->time)) {
            $this->time = $this->defaultServe()->format('H:i');
        }
    }

    public function updatedTime(): void
    {
        if (! preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $this->time)) {
            $this->time = $this->defaultServe()->format('H:i');
        }

        unset($this->plan);
    }

    /** « Faite » : partagé avec les autres appareils (lot 41, 41.3). */
    public function toggle(string $key): void
    {
        [$meal, $step] = $this->taskOf($key);
        app(MealTasks::class)->toggleDone($meal, $step);
        unset($this->states);
    }

    /** Qui cuisine ce plat : une personne, « ensemble » ou personne. */
    public function assignDish(int $mealId, string $who): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $this->attemptTask(fn () => app(MealTasks::class)->assignDish($this->meals->firstWhere('id', $mealId) ?? abort(404), $who));
        unset($this->meals, $this->plan);
    }

    /** Confier une étape à quelqu'un d'autre (vide : celui qui cuisine le plat). */
    public function assignStep(string $key, string $who): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        [$meal, $step] = $this->taskOf($key);
        $this->attemptTask(fn () => app(MealTasks::class)->assignStep($meal, $step, $who));
    }

    public function toggleMine(): void
    {
        $this->who = $this->who === 'moi' ? '' : 'moi';
    }

    /** @return array{0: PlannedMeal, 1: int} */
    private function taskOf(string $key): array
    {
        abort_unless(preg_match('/^(\d+)-(\d+)$/', $key, $m), 404);
        $meal = $this->meals->firstWhere('id', (int) $m[1]);
        abort_unless($meal !== null, 404);

        return [$meal, (int) $m[2]];
    }

    private function attemptTask(callable $action): void
    {
        try {
            $action();
        } catch (\InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());
        }

        unset($this->states);
    }

    /**
     * Pour chaque étape : à qui elle revient, et si elle est faite (relu à chaque passage : les autres
     * appareils voient les étapes cochées).
     *
     * @return array<string, array{user_id: int|null, together: bool, override: bool, done: bool, done_by: string|null}>
     */
    #[Computed]
    public function states(): array
    {
        return app(MealTasks::class)->states($this->meals, $this->plan['tasks']);
    }

    /** Personnes du foyer (pour répartir). */
    #[Computed]
    public function members(): Collection
    {
        return \App\Models\User::query()->inHousehold()->orderBy('name')->get(['id', 'name']);
    }

    /** L'étape est-elle pour moi ? (à moi, ensemble, ou à personne) */
    public function isMine(array $state): bool
    {
        return $state['together'] || $state['user_id'] === null || $state['user_id'] === (int) auth()->id();
    }

    /** Tout le repas est mangé : chaque plat, comme « Mangé » dans le planning (le stock est proposé). */
    public function markAllCooked(WeekPlanner $planner): void
    {
        if (! auth()->user()->canEdit()) {
            $this->dispatch('notify', type: 'warning', message: 'Votre compte est en consultation : cette modification est réservée aux comptes complets.');

            return;
        }

        $count = 0;

        foreach ($this->meals->filter(fn (PlannedMeal $meal) => ! $meal->cooked_at) as $meal) {
            $planner->toggleCooked($meal);
            $this->dispatch('meal-cooked', mealId: $meal->id, cooked: true)->to(\App\Livewire\Stock\MealStockDialog::class);
            $count++;
        }

        unset($this->meals, $this->plan);
        $this->dispatch('notify', message: $count > 0 ? 'Repas marqué comme mangé ('.$count.' plat'.($count > 1 ? 's' : '').').' : 'Le repas était déjà marqué comme mangé.');
    }

    /* ================================================================ Données */

    #[Computed]
    public function slot(): MealSlot
    {
        return MealSlot::query()->findOrFail($this->slotId);
    }

    #[Computed]
    public function occasion(): ?MealOccasion
    {
        return app(OccasionService::class)->find($this->date, $this->slotId);
    }

    /** @return Collection<int, PlannedMeal> */
    #[Computed]
    public function meals(): Collection
    {
        return app(MealCookPlan::class)->meals($this->date, $this->slotId);
    }

    private function defaultServe(): Carbon
    {
        return app(MealCookPlan::class)->serveAt($this->date, $this->slot, $this->occasion);
    }

    public function serve(): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $this->time ?: $this->defaultServe()->format('H:i')));

        return Carbon::parse($this->date)->setTime($hour, $minute);
    }

    /** @return array{tasks: Collection<int, array>, dishes: Collection<int, array>} */
    #[Computed]
    public function plan(): array
    {
        $reception = $this->occasion && app(ReceptionPlanner::class)->isReception($this->occasion);

        return app(MealCookPlan::class)->build($this->meals, $this->serve(), $reception);
    }

    /** Ingrédients de chaque plat, aux portions du repas. @return array<int, Collection<int, array>> */
    #[Computed]
    public function ingredients(): array
    {
        $scaler = app(QuantityScaler::class);
        $formatter = app(IngredientLineFormatter::class);
        $lines = [];

        foreach ($this->meals as $meal) {
            if (! $meal->isRecipe() || $meal->isPrepared()) {
                continue;
            }

            $recipe = $meal->recipe;
            $lines[$meal->id] = app(\App\Services\Recipes\SubRecipes::class)->lines($recipe)->values()
                ->map(fn ($line) => $formatter->format($scaler->scale($line->quantity, $recipe->servings, $meal->servings), $line->unit, $line->ingredient)
                    + ['optional' => (bool) $line->is_optional]);
        }

        return $lines;
    }

    public function render()
    {
        return view('livewire.planner.cook-meal')
            ->title('Cuisiner le repas · '.Carbon::parse($this->date)->locale('fr')->isoFormat('ddd D MMM'));
    }
}
