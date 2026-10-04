<?php

namespace App\Livewire\Budget;

use App\Models\BudgetCategory;
use App\Services\Budget\BudgetAnalysis;
use App\Services\Budget\BudgetTracker;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Analyses du budget (lot 22 — 23.6).
 */
#[Title('Analyses du budget')]
class Analyses extends Component
{
    /** Nombre de périodes analysées : 3, 6 ou 12. */
    #[Url(as: 'sur', except: 6)]
    public int $range = 6;

    public function render(BudgetTracker $tracker, BudgetAnalysis $analysis)
    {
        $this->range = in_array($this->range, [3, 6, 12], true) ? $this->range : 6;
        $periods = $analysis->periods($this->range);
        $from = $periods[0]['start'];
        $to = end($periods)['end'];
        $categories = BudgetCategory::query()->ordered()->get();

        return view('livewire.budget.analyses', [
            'tracker' => $tracker,
            'periods' => $periods,
            'peak' => max(1, collect($periods)->max('total')),
            'categories' => $categories->filter(fn ($c) => collect($periods)->sum(fn ($p) => $p['categories'][$c->id] ?? 0) > 0 || ! $c->archived_at)->values(),
            'places' => $analysis->places($from, $to)->take(10),
            'meals' => $analysis->mealCosts($from, $to),
            'planned' => $analysis->plannedVsActual($from, $to),
            'waste' => $tracker->wasteValue($from, $to),
            'rangeLabel' => $tracker->periodLabel($from, $from).' → '.$tracker->periodLabel($periods[count($periods) - 1]['start'], $to),
        ]);
    }
}
