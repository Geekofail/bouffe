<?php

namespace App\Services\Budget;

use App\Enums\MealType;
use App\Models\BudgetCategory;
use App\Models\Expense;
use App\Models\PlannedMeal;
use App\Services\Planning\OccasionService;
use App\Services\Pricing\CostCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Analyses du budget (lot 22 — 23.6) : l'année par poste, les magasins, le coût d'un repas à la
 * maison et dehors, le gaspillage en euros, et l'écart entre le coût prévu du planning et la
 * dépense réelle. Chaque chiffre dit sur quoi il repose.
 */
class BudgetAnalysis
{
    public function __construct(
        private readonly BudgetTracker $tracker,
        private readonly OccasionService $occasions,
    ) {}

    /**
     * Les N dernières périodes, de la plus ancienne à la plus récente.
     *
     * @return list<array{start: Carbon, end: Carbon, label: string, short: string, total: float, categories: array<int, float>}>
     */
    public function periods(int $count = 12, ?Carbon $date = null): array
    {
        [$current] = $this->tracker->period($date);
        $rows = [];

        for ($i = $count - 1; $i >= 0; $i--) {
            [$start, $end] = $this->tracker->shift($current, -$i);
            $categories = $this->tracker->spentByCategory($start, $end);

            $rows[] = [
                'start' => $start,
                'end' => $end,
                'label' => $this->tracker->periodLabel($start, $end),
                'short' => ucfirst($start->locale('fr')->isoFormat($this->tracker->startDay() > 1 ? 'D MMM' : 'MMM')),
                'total' => round(array_sum($categories), 2),
                'categories' => $categories,
            ];
        }

        return $rows;
    }

    /**
     * Magasins et lieux : nombre de passages, total, panier moyen.
     *
     * @return Collection<int, array{label: string, visits: int, total: float, average: float}>
     */
    public function places(Carbon $from, Carbon $to): Collection
    {
        return Expense::query()
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->where('source', '!=', 'recurring')
            ->with('store', 'category')
            ->get()
            ->groupBy(fn (Expense $e) => $e->store_id ? 's'.$e->store_id : 'p'.mb_strtolower((string) ($e->place ?? $e->category?->name)))
            ->map(fn (Collection $group) => [
                'label' => $group->first()->placeLabel(),
                'visits' => $group->count(),
                'total' => round((float) $group->sum('amount'), 2),
                'average' => round((float) $group->avg('amount'), 2),
            ])
            ->sortByDesc('total')
            ->values();
    }

    /**
     * Coût d'un repas par personne : à la maison (courses ÷ couverts des repas mangés) et dehors
     * (restaurant, à emporter, midi au travail ÷ personnes).
     *
     * Le coût à la maison n'est donné que si les repas marqués mangés couvrent au moins la moitié
     * des jours de la période pour tout le foyer : sinon on diviserait un mois de courses par
     * quelques repas, et le chiffre serait faux (« home » vaut alors null, « home_reliable » false).
     *
     * @return array{home: float|null, home_covers: int, home_reliable: bool, out: float|null, out_covers: int}
     */
    public function mealCosts(Carbon $from, Carbon $to): array
    {
        $groceries = BudgetCategory::groceries();
        $spent = $this->tracker->spentByCategory($from, $to);
        $homeSpent = $groceries ? ($spent[$groceries->id] ?? 0.0) : 0.0;

        // Couverts : chaque repas mangé à la maison compte ses convives (restes compris, ils ont été achetés).
        $covers = PlannedMeal::query()
            ->whereIn('type', [MealType::Recipe->value, MealType::Leftover->value])
            ->whereNotNull('cooked_at')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->unique(fn (PlannedMeal $m) => $m->date->toDateString().'|'.$m->meal_slot_id)
            ->sum(fn (PlannedMeal $m) => $this->occasions->dinersAt($m->date, $m->meal_slot_id));

        $out = Expense::query()
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->whereHas('category', fn ($q) => $q->whereIn('kind', ['restaurant', 'takeaway', 'work']))
            ->get();

        $outCovers = (int) $out->sum(fn (Expense $e) => $e->persons ?: 1);

        $last = $to->copy()->min(Carbon::today());
        $days = $last->lt($from) ? 0 : (int) $from->diffInDays($last) + 1;
        $reliable = $covers > 0 && $covers >= $days * $this->occasions->householdSize() / 2;

        return [
            'home' => $reliable && $homeSpent > 0 ? round($homeSpent / $covers, 2) : null,
            'home_covers' => (int) $covers,
            'home_reliable' => $reliable,
            'out' => $outCovers > 0 ? round((float) $out->sum('amount') / $outCovers, 2) : null,
            'out_covers' => $outCovers,
        ];
    }

    /**
     * Coût prévu par le planning (R17) face à la dépense réelle de courses.
     *
     * @return array{planned: float, known: bool, missing: int, actual: float, gap: float|null} gap : seulement si aucun prix ne manque
     */
    public function plannedVsActual(Carbon $from, Carbon $to): array
    {
        $cost = app(CostCalculator::class)->week($from, $to);
        $groceries = BudgetCategory::groceries();
        $actual = $groceries ? ($this->tracker->spentByCategory($from, $to)[$groceries->id] ?? 0.0) : 0.0;

        return [
            'planned' => round($cost->total, 2),
            'known' => $cost->isKnown(),
            'missing' => $cost->missing,
            'actual' => round($actual, 2),
            // Un écart n'a de sens que si tous les ingrédients du planning ont un prix.
            'gap' => $cost->total > 0 && $actual > 0 && $cost->missing === 0 ? round($actual - $cost->total, 2) : null,
        ];
    }
}
