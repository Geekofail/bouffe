<?php

namespace App\Livewire\Budget;

use App\Models\Expense;
use App\Services\Budget\BudgetTracker;
use App\Services\Budget\RecurringExpenses;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tableau de bord du budget (lot 22 — 23.5) : par poste, dépensé, budget, rythme, projection ;
 * puis les dépenses de la période.
 */
#[Title('Budget')]
class Index extends Component
{
    /** Un jour de la période affichée (AAAA-MM-JJ) ; vide = aujourd'hui. */
    #[Url(as: 'periode', except: '')]
    public string $at = '';

    public string $categoryFilter = '';

    public function mount(RecurringExpenses $recurring): void
    {
        // Les échéances passées apparaissent même si la tâche planifiée n'a pas tourné.
        $recurring->apply();
    }

    #[On('expenses-changed')]
    public function refresh(): void
    {
        unset($this->dashboard, $this->expenses);
    }

    public function previous(BudgetTracker $tracker): void
    {
        $this->at = $tracker->shift($this->period()[0], -1)[0]->toDateString();
        $this->refresh();
    }

    public function next(BudgetTracker $tracker): void
    {
        $next = $tracker->shift($this->period()[0], 1)[0];
        $this->at = $next->gte($tracker->period()[0]) ? '' : $next->toDateString();   // période en cours → adresse nue
        $this->refresh();
    }

    public function add(): void
    {
        $this->dispatch('open-expense')->to(ExpenseForm::class);
    }

    public function edit(int $expenseId): void
    {
        $this->dispatch('open-expense', expenseId: $expenseId)->to(ExpenseForm::class);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(): array
    {
        return app(BudgetTracker::class)->period($this->at !== '' ? Carbon::parse($this->at) : null);
    }

    #[Computed]
    public function dashboard(): Collection
    {
        return app(BudgetTracker::class)->dashboard($this->period()[0]);
    }

    #[Computed]
    public function expenses(): Collection
    {
        [$from, $to] = $this->period();

        return Expense::query()
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->when($this->categoryFilter !== '', fn ($q) => $q->whereHas('splits', fn ($s) => $s->where('budget_category_id', (int) $this->categoryFilter)))
            ->with('category', 'store', 'splits.category', 'payer')
            ->orderByDesc('spent_on')->orderByDesc('id')
            ->get();
    }

    public function render(BudgetTracker $tracker)
    {
        [$from, $to] = $this->period();
        $rows = $this->dashboard;
        $withBudget = $rows->whereNotNull('budget');

        return view('livewire.budget.index', [
            'tracker' => $tracker,
            'from' => $from,
            'to' => $to,
            'label' => $tracker->periodLabel($from, $to),
            'isCurrent' => $to->gte(Carbon::today()) && $from->lte(Carbon::today()),
            'total' => [
                'spent' => round($rows->sum('spent'), 2),
                'budget' => $withBudget->isEmpty() ? null : round($withBudget->sum('budget'), 2),
                'spentBudgeted' => round($withBudget->sum('spent'), 2),
            ],
            'listPrices' => $tracker->listPrices($from, $to),
            'waste' => $tracker->wasteValue($from, $to),
            'payers' => $tracker->tracksPayer() ? $tracker->payers($from, $to) : collect(),
        ]);
    }
}
