<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Dépense récurrente (C7) : cantine, panier bio, abonnement — saisie une fois, comptée à chaque échéance.
 *
 * @property int $id
 * @property string $label
 * @property string $amount
 * @property int $budget_category_id
 * @property string $frequency monthly · weekly
 * @property int $day 1-28 (mois) ou 1-7 (semaine, 1 = lundi)
 * @property Carbon $next_on
 * @property bool $is_active
 */
#[Fillable(['label', 'amount', 'budget_category_id', 'frequency', 'day', 'next_on', 'is_active'])]
class RecurringExpense extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'day' => 'integer', 'next_on' => 'date', 'is_active' => 'boolean'];
    }

    public function setNextOnAttribute(mixed $value): void
    {
        $this->attributes['next_on'] = Carbon::parse($value)->toDateString();
    }

    /** @return BelongsTo<BudgetCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class, 'budget_category_id');
    }

    /** Prochaine échéance à partir d'une date (incluse). */
    public static function nextOccurrence(string $frequency, int $day, Carbon $from): Carbon
    {
        if ($frequency === 'weekly') {
            $date = $from->copy()->startOfDay();

            while ($date->dayOfWeekIso !== max(1, min(7, $day))) {
                $date->addDay();
            }

            return $date;
        }

        $day = max(1, min(28, $day));
        $date = $from->copy()->startOfDay()->day($day);

        return $date->lt($from->copy()->startOfDay()) ? $date->addMonthNoOverflow() : $date;
    }

    public function describe(): string
    {
        return $this->frequency === 'weekly'
            ? 'chaque '.['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'][$this->day]
            : 'le '.$this->day.' de chaque mois';
    }
}
