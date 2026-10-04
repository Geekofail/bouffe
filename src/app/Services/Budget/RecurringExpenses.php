<?php

namespace App\Services\Budget;

use App\Models\RecurringExpense;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Dépenses récurrentes (C7) : saisies une fois, comptées à chaque échéance.
 *
 * Appliquées par la tâche planifiée et à l'ouverture du tableau de bord : une échéance passée
 * devient une dépense datée du jour de l'échéance (jamais d'avance, jamais deux fois).
 */
class RecurringExpenses
{
    /** Rattrapage maximal après une longue interruption. */
    public const MAX_CATCH_UP = 12;

    public function __construct(private readonly BudgetTracker $tracker) {}

    public function create(string $label, float $amount, int $categoryId, string $frequency, int $day, ?Carbon $from = null): RecurringExpense
    {
        $label = trim($label);

        if ($label === '' || $amount <= 0) {
            throw new InvalidArgumentException('Indiquez un libellé et un montant positif.');
        }

        $frequency = $frequency === 'weekly' ? 'weekly' : 'monthly';
        $day = $frequency === 'weekly' ? max(1, min(7, $day)) : max(1, min(28, $day));

        return RecurringExpense::create([
            'label' => mb_substr($label, 0, 150),
            'amount' => round($amount, 2),
            'budget_category_id' => $categoryId,
            'frequency' => $frequency,
            'day' => $day,
            'next_on' => RecurringExpense::nextOccurrence($frequency, $day, $from ?? Carbon::today()),
            'is_active' => true,
        ]);
    }

    /** @return int dépenses créées */
    public function apply(?Carbon $today = null): int
    {
        $today ??= Carbon::today();
        $count = 0;

        foreach (RecurringExpense::query()->where('is_active', true)->whereDate('next_on', '<=', $today->toDateString())->get() as $rule) {
            $guard = 0;

            while ($rule->next_on->lte($today) && $guard++ < self::MAX_CATCH_UP) {
                $this->tracker->record([
                    'amount' => (float) $rule->amount,
                    'spent_on' => $rule->next_on,
                    'budget_category_id' => $rule->budget_category_id,
                    'place' => $rule->label,
                    'recurring_expense_id' => $rule->id,
                    'source' => 'recurring',
                ]);

                $rule->next_on = RecurringExpense::nextOccurrence($rule->frequency, $rule->day, $rule->next_on->copy()->addDay());
                $count++;
            }

            $rule->save();
        }

        return $count;
    }
}
