<?php

namespace App\Livewire\Planner;

use App\Models\MealSlot;
use App\Services\Planning\PlanningRules;
use App\Services\Planning\WeekFiller;
use App\Services\Planning\WeekPlanner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Remplir la semaine (14.1, 14.2 — règle R15).
 *
 * L'écran propose, on ajuste, puis on valide : chaque case peut être relancée (🎲),
 * verrouillée (🔒) pour survivre à une relance, ou retirée (✕). Rien n'est enregistré avant
 * « Valider ».
 */
#[Title('Remplir la semaine')]
class FillWeek extends Component
{
    #[Url(as: 'semaine', except: '')]
    public string $week = '';

    public bool $useStock = true;

    public bool $useWishes = true;

    public bool $useLeftovers = true;

    /** @var list<int> créneaux à remplir */
    public array $slotIds = [];

    /** @var list<array> propositions en cours */
    public array $proposals = [];

    /** @var array<string, list<int>> recettes écartées, par case */
    public array $avoid = [];

    public bool $generated = false;

    public function mount(WeekFiller $filler): void
    {
        $this->slotIds = $this->mealSlots->pluck('id')->map('intval')->all();
        $this->generate($filler);
    }

    public function weekStart(): Carbon
    {
        return app(WeekPlanner::class)->weekStart($this->week ?: null);
    }

    /* ---------------------------------------------------------------- Propositions */

    public function generate(WeekFiller $filler): void
    {
        $this->avoid = [];
        $this->proposals = $filler->propose($this->weekStart(), $this->options());
        $this->generated = true;
    }

    /** Relance tout sauf les cases verrouillées. */
    public function regenerate(WeekFiller $filler): void
    {
        $this->proposals = $filler->propose($this->weekStart(), [...$this->options(), 'keep' => $this->lockedProposals()]);
    }

    /** Relance une seule case, en écartant la proposition actuelle. */
    public function reroll(string $key, WeekFiller $filler): void
    {
        $current = collect($this->proposals)->firstWhere('key', $key);

        if ($current && $current['recipe_id']) {
            $this->avoid[$key] = array_values(array_unique([...($this->avoid[$key] ?? []), (int) $current['recipe_id']]));
        }

        $keep = collect($this->proposals)
            ->filter(fn ($p) => $p['key'] !== $key && ($p['locked'] || $p['type'] !== 'recipe'))
            ->values()->all();

        $fresh = collect($filler->propose($this->weekStart(), [...$this->options(), 'keep' => $keep, 'avoid' => $this->avoid]))->keyBy('key');

        $this->proposals = collect($this->proposals)
            ->map(fn ($p) => $p['key'] === $key ? ($fresh->get($key) ?? null) : $p)
            ->filter()->values()->all();
    }

    public function toggleLock(string $key): void
    {
        $this->proposals = collect($this->proposals)
            ->map(fn ($p) => $p['key'] === $key ? [...$p, 'locked' => ! $p['locked']] : $p)
            ->values()->all();
    }

    public function remove(string $key): void
    {
        // Retirer une recette retire aussi les restes qui en dépendaient.
        $this->proposals = collect($this->proposals)
            ->reject(fn ($p) => $p['key'] === $key || ($p['leftover_key'] ?? null) === $key)
            ->values()->all();
    }

    public function updatedUseStock(): void
    {
        $this->regenerate(app(WeekFiller::class));
    }

    public function updatedUseWishes(): void
    {
        $this->regenerate(app(WeekFiller::class));
    }

    public function updatedUseLeftovers(): void
    {
        $this->regenerate(app(WeekFiller::class));
    }

    public function toggleSlot(int $slotId, WeekFiller $filler): void
    {
        $this->slotIds = in_array($slotId, $this->slotIds, true)
            ? array_values(array_diff($this->slotIds, [$slotId]))
            : [...$this->slotIds, $slotId];

        $this->generate($filler);
    }

    /* ---------------------------------------------------------------- Validation */

    public function apply(WeekFiller $filler): void
    {
        if ($this->proposals === []) {
            $this->dispatch('notify', message: 'Aucune proposition à enregistrer.');

            return;
        }

        $count = $filler->apply($this->proposals);

        session()->flash('status', $count.' repas ajouté'.($count > 1 ? 's' : '').' au planning.');
        $this->redirectRoute('planner.week', ['semaine' => $this->weekStart()->toDateString()], navigate: true);
    }

    public function cancel(): void
    {
        $this->redirectRoute('planner.week', ['semaine' => $this->weekStart()->toDateString()], navigate: true);
    }

    /* ---------------------------------------------------------------- Données */

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'useStock' => $this->useStock,
            'useWishes' => $this->useWishes,
            'useLeftovers' => $this->useLeftovers,
            'slotIds' => $this->slotIds,
            'avoid' => $this->avoid,
        ];
    }

    /** @return list<array> */
    private function lockedProposals(): array
    {
        return collect($this->proposals)->where('locked', true)->values()->all();
    }

    #[Computed]
    public function mealSlots(): Collection
    {
        return MealSlot::query()->active()->ordered()->get(['id', 'name']);
    }

    /** Propositions groupées par jour, dans l'ordre du planning. */
    #[Computed]
    public function byDay(): Collection
    {
        return collect($this->proposals)->groupBy('date');
    }

    #[Computed]
    public function lockedCount(): int
    {
        return count($this->lockedProposals());
    }

    #[Computed]
    public function emptyCells(): int
    {
        $planner = app(WeekPlanner::class);
        $taken = $planner->mealsForWeek($this->weekStart())
            ->map(fn ($m) => $m->date->toDateString().'|'.$m->meal_slot_id)->unique();

        $cells = $planner->days($this->weekStart())->crossJoin($this->slotIds)
            ->map(fn ($pair) => $pair[0]->toDateString().'|'.$pair[1]);

        return $cells->reject(fn ($key) => $taken->contains($key))->count();
    }

    #[Computed]
    public function rulesSummary(): array
    {
        $rules = app(PlanningRules::class);

        return $rules->isEmpty() ? [] : $rules->weekStatus(app(WeekPlanner::class)->mealsForWeek($this->weekStart()));
    }

    public function render()
    {
        return view('livewire.planner.fill-week');
    }
}
