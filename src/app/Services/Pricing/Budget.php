<?php

namespace App\Services\Pricing;

use App\Models\BudgetCategory;
use App\Models\Expense;
use App\Services\Budget\BudgetTracker;
use Illuminate\Support\Carbon;

/**
 * Budget des courses, par mois civil (17.3).
 *
 * Depuis le lot 22, le budget unique du lot 17 est celui du poste « Courses alimentaires » et
 * les dépenses comptent en plus des prix saisis en magasin (sans double compte, R26). Cette
 * classe garde l'interface du lot 17 ; le suivi complet est dans BudgetTracker.
 */
class Budget
{
    public function __construct(
        private readonly PriceBook $prices,
        private readonly BudgetTracker $tracker,
    ) {}

    public function monthly(): ?float
    {
        $category = BudgetCategory::groceries();

        return $category ? $this->tracker->budgetFor($category, Carbon::today()->startOfMonth()) : null;
    }

    public function setMonthly(?float $amount): void
    {
        if ($category = BudgetCategory::groceries()) {
            $this->tracker->setBudget($category, $amount === null || $amount < 0 ? 0 : $amount, Carbon::today());
        }
    }

    /** Dépenses de courses d'un mois (prix cochés sans ticket + dépenses du poste). */
    public function spent(Carbon $month): float
    {
        $category = BudgetCategory::groceries();
        $totals = $this->tracker->spentByCategory($month->copy()->startOfMonth(), $month->copy()->endOfMonth());

        return round($category ? ($totals[$category->id] ?? 0.0) : 0.0, 2);
    }

    /** Nombre de saisies (prix cochés et dépenses) sur lesquelles repose le total. */
    public function entries(Carbon $month): int
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        return $this->tracker->listPrices($from, $to)['entries']
            + Expense::query()->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
                ->whereHas('splits.category', fn ($q) => $q->where('kind', 'groceries'))->count();
    }

    /**
     * Les N derniers mois, du plus ancien au plus récent.
     *
     * @return list<array{month: Carbon, label: string, short: string, spent: float, entries: int, share: float|null}>
     */
    public function months(int $count = 6): array
    {
        $budget = $this->monthly();
        $start = Carbon::today()->startOfMonth()->subMonths(max(1, $count) - 1);
        $months = [];

        for ($i = 0; $i < max(1, $count); $i++) {
            $month = $start->copy()->addMonths($i);
            $spent = $this->spent($month);

            $months[] = [
                'month' => $month,
                'label' => ucfirst($month->locale('fr')->isoFormat('MMMM YYYY')),
                'short' => ucfirst($month->locale('fr')->isoFormat('MMM')),
                'spent' => $spent,
                'entries' => $this->entries($month),
                'share' => $budget ? round($spent / $budget * 100) : null,
            ];
        }

        return $months;
    }

    /**
     * @return array{budget: float|null, spent: float, entries: int, remaining: float|null, share: float|null, over: bool, pace: float|null}
     */
    public function status(?Carbon $month = null): array
    {
        $month ??= Carbon::today();
        $budget = $this->monthly();
        $spent = $this->spent($month);
        $days = $month->daysInMonth;
        $elapsed = $month->isSameMonth(Carbon::today()) ? Carbon::today()->day : $days;

        return [
            'budget' => $budget,
            'spent' => $spent,
            'entries' => $this->entries($month),
            'remaining' => $budget ? $budget - $spent : null,
            'share' => $budget ? round($spent / $budget * 100) : null,
            'over' => $budget !== null && $spent > $budget,
            'pace' => $elapsed > 0 ? round($spent / $elapsed * $days, 2) : null,
        ];
    }

    public function money(float $amount): string
    {
        return $this->prices->money($amount);
    }
}
