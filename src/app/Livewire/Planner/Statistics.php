<?php

namespace App\Livewire\Planner;

use App\Services\Planning\PlanningStats;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Statistiques de planning (lot 35, 35.2) : ce qui a vraiment été cuisiné et mangé.
 */
#[Title('Statistiques')]
class Statistics extends Component
{
    public const PERIODS = [
        '1m' => 'Dernier mois',
        '3m' => '3 mois',
        '6m' => '6 mois',
        '12m' => '12 mois',
    ];

    #[Url(as: 'periode', except: '3m')]
    public string $period = '3m';

    public function mount(): void
    {
        if (! isset(self::PERIODS[$this->period])) {
            $this->period = '3m';
        }
    }

    public function setPeriod(string $period): void
    {
        $this->period = isset(self::PERIODS[$period]) ? $period : '3m';
    }

    public function render(PlanningStats $stats)
    {
        $months = (int) rtrim($this->period, 'm');
        $to = Carbon::today();
        $from = $to->copy()->subMonthsNoOverflow($months)->addDay();

        return view('livewire.planner.statistics', [
            'stats' => $stats->period($from, $to),
            'periods' => self::PERIODS,
        ]);
    }
}
