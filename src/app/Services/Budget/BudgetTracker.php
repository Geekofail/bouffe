<?php

namespace App\Services\Budget;

use App\Enums\MovementType;
use App\Models\BudgetAmount;
use App\Models\BudgetCategory;
use App\Models\Expense;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Pricing\PriceBook;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Dépenses et budget réel (lot 22 — module 23, règles R25 et R26).
 *
 * Ce qui compte dans un poste, sur une période :
 *  - les dépenses saisies (ou lues sur un ticket, ou récurrentes), réparties par poste ;
 *  - pour les courses alimentaires seulement, les prix cochés en magasin (15.7) **des listes
 *    sans ticket** : dès qu'une dépense est rattachée à une liste, ses prix cochés ne comptent
 *    plus — le ticket fait foi (R26).
 */
class BudgetTracker
{
    /** Alerte douce à partir de cette part du budget dépensée. */
    public const ALERT_SHARE = 80;

    /** Projection jugée inquiétante au-delà du budget + 5 %. */
    public const PROJECTION_MARGIN = 1.05;

    public function __construct(private readonly PriceBook $prices) {}

    /* ================================================================ Périodes (23.7) */

    public function startDay(): int
    {
        return Settings::int('budget.period_start_day', 1);
    }

    /** @return array{0: Carbon, 1: Carbon} premier et dernier jour de la période qui contient $date */
    public function period(?Carbon $date = null): array
    {
        $date = ($date ?? Carbon::today())->copy()->startOfDay();
        $day = $this->startDay();

        if ($day <= 1) {
            return [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()->startOfDay()];
        }

        $start = $date->day >= $day ? $date->copy()->day($day) : $date->copy()->subMonthNoOverflow()->day($day);

        return [$start, $start->copy()->addMonthNoOverflow()->subDay()];
    }

    /** Période précédente (ou suivante) d'un cran. @return array{0: Carbon, 1: Carbon} */
    public function shift(Carbon $start, int $by): array
    {
        return $this->period($start->copy()->addMonthsNoOverflow($by));
    }

    public function periodLabel(Carbon $start, Carbon $end): string
    {
        return $this->startDay() <= 1
            ? ucfirst($start->locale('fr')->isoFormat('MMMM YYYY'))
            : 'Du '.$start->locale('fr')->isoFormat('D MMM').' au '.$end->locale('fr')->isoFormat('D MMM YYYY');
    }

    /* ================================================================ Budgets (23.2, 23.7) */

    /** Budget d'un poste pour la période qui commence à $start, ou null s'il n'y en a pas. */
    public function budgetFor(BudgetCategory $category, Carbon $start): ?float
    {
        $amount = BudgetAmount::query()
            ->where('budget_category_id', $category->id)
            ->where('valid_from', '<=', $start->toDateString())
            ->orderByDesc('valid_from')
            ->value('amount');

        return $amount !== null && (float) $amount > 0 ? (float) $amount : null;
    }

    /** Fixe le budget d'un poste à partir de la période en cours ; l'historique garde les anciens montants. */
    public function setBudget(BudgetCategory $category, ?float $amount, ?Carbon $date = null): void
    {
        if ($amount !== null && ($amount < 0 || $amount > 100000)) {
            throw new InvalidArgumentException('Le budget doit être compris entre 0 et 100 000 €.');
        }

        [$start] = $this->period($date);

        BudgetAmount::updateOrCreate(
            ['budget_category_id' => $category->id, 'valid_from' => $start->toDateString()],
            ['amount' => round((float) $amount, 2)],
        );
    }

    /* ================================================================ Dépenses (23.1, 23.3) */

    /**
     * Enregistre une dépense.
     *
     * @param  array{amount: float|string, spent_on: string|Carbon, budget_category_id: int, store_id?: int|null, place?: string|null, persons?: int|null, paid_by?: int|null, shopping_list_id?: int|null, planned_meal_id?: int|null, recurring_expense_id?: int|null, note?: string|null, source?: string}  $data
     * @param  array<int, float>  $splits  poste => montant ; vide = tout sur le poste principal
     */
    public function record(array $data, array $splits = [], ?Expense $expense = null): Expense
    {
        $amount = round((float) str_replace(',', '.', (string) $data['amount']), 2);
        $date = Carbon::parse($data['spent_on'])->startOfDay();

        if ($amount <= 0 || $amount > 100000) {
            throw new InvalidArgumentException('Le montant doit être positif.');
        }

        if ($date->gt(Carbon::today())) {
            throw new InvalidArgumentException('Une dépense ne peut pas être datée dans le futur.');
        }

        if (! BudgetCategory::whereKey($data['budget_category_id'])->exists()) {
            throw new InvalidArgumentException('Choisissez un poste.');
        }

        $splits = array_filter(array_map(fn ($v) => round((float) $v, 2), $splits), fn ($v) => $v > 0);

        if ($splits !== [] && abs(array_sum($splits) - $amount) > 0.005) {
            throw new InvalidArgumentException('La répartition ('.$this->money(array_sum($splits)).') ne correspond pas au total ('.$this->money($amount).').');
        }

        $splits = $splits ?: [(int) $data['budget_category_id'] => $amount];

        return DB::transaction(function () use ($data, $amount, $date, $splits, $expense) {
            $expense ??= new Expense(['created_by' => auth()->id(), 'source' => $data['source'] ?? 'manual']);
            $expense->fill([
                'spent_on' => $date,
                'amount' => $amount,
                'budget_category_id' => (int) $data['budget_category_id'],
                'store_id' => $data['store_id'] ?? null,
                'place' => mb_substr(trim((string) ($data['place'] ?? '')), 0, 150) ?: null,
                'persons' => isset($data['persons']) && (int) $data['persons'] > 0 ? min(99, (int) $data['persons']) : null,
                'paid_by' => $data['paid_by'] ?? null,
                'shopping_list_id' => $data['shopping_list_id'] ?? null,
                'planned_meal_id' => $data['planned_meal_id'] ?? null,
                'recurring_expense_id' => $data['recurring_expense_id'] ?? $expense->recurring_expense_id,
                'receipt_id' => $data['receipt_id'] ?? $expense->receipt_id,
                'note' => mb_substr(trim((string) ($data['note'] ?? '')), 0, 255) ?: null,
            ])->save();

            $expense->splits()->delete();

            foreach ($splits as $categoryId => $part) {
                $expense->splits()->create(['budget_category_id' => $categoryId, 'amount' => $part]);
            }

            return $expense->load('splits', 'category', 'store');
        });
    }

    /** Une dépense identique existe déjà (même lieu, même jour, même montant) ? R26 */
    public function duplicateOf(array $data, ?int $ignoreId = null): ?Expense
    {
        return Expense::query()
            ->whereDate('spent_on', Carbon::parse($data['spent_on'])->toDateString())
            ->where('amount', round((float) str_replace(',', '.', (string) $data['amount']), 2))
            ->when($data['store_id'] ?? null, fn ($q, $store) => $q->where('store_id', $store))
            ->when(! ($data['store_id'] ?? null) && ($data['place'] ?? null), fn ($q) => $q->where('place', trim((string) $data['place'])))
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->first();
    }

    /**
     * Liste de courses à laquelle rattacher un ticket (R26) : articles cochés ce jour-là ou la
     * veille, dans ce magasin (ou sans magasin), et pas encore de ticket.
     */
    public function suggestList(?int $storeId, Carbon|string $date): ?ShoppingList
    {
        $day = Carbon::parse($date)->startOfDay();

        return ShoppingList::query()
            ->whereDoesntHave('expenses')
            ->when($storeId, fn ($q) => $q->where(fn ($w) => $w->where('store_id', $storeId)->orWhereNull('store_id')))
            ->whereHas('items', fn ($q) => $q->where('is_checked', true)
                ->whereBetween('checked_at', [$day->copy()->subDay(), $day->copy()->endOfDay()]))
            ->latest('id')->first();
    }

    /* ================================================================ Totaux */

    /**
     * Dépensé par poste sur une période.
     *
     * @return array<int, float> poste => montant
     */
    public function spentByCategory(Carbon $from, Carbon $to): array
    {
        $totals = DB::table('expense_splits')
            ->join('expenses', 'expenses.id', '=', 'expense_splits.expense_id')
            ->where('expenses.household_id', \App\Support\CurrentHousehold::id() ?? 0)   // R29
            ->whereBetween('expenses.spent_on', [$from->toDateString(), $to->toDateString()])
            ->groupBy('expense_splits.budget_category_id')
            ->selectRaw('expense_splits.budget_category_id as category, sum(expense_splits.amount) as total')
            ->pluck('total', 'category')
            ->map(fn ($v) => (float) $v)
            ->all();

        if ($groceries = BudgetCategory::groceries()) {
            $totals[$groceries->id] = round(($totals[$groceries->id] ?? 0.0) + $this->listPrices($from, $to)['total'], 2);
        }

        return $totals;
    }

    /**
     * Prix cochés en magasin des listes **sans ticket** (R26).
     *
     * @return array{total: float, entries: int}
     */
    public function listPrices(Carbon $from, Carbon $to): array
    {
        $query = ShoppingListItem::query()
            ->whereNotNull('paid_price')->whereNotNull('checked_at')
            ->whereBetween('checked_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereDoesntHave('shoppingList.expenses');

        return ['total' => round((float) $query->clone()->sum('paid_price'), 2), 'entries' => $query->count()];
    }

    /**
     * Où en est chaque poste sur la période qui contient $date (tableau de bord, 23.5 et R25).
     *
     * @return Collection<int, array{category: BudgetCategory, budget: float|null, spent: float, pace: float|null, projection: float, fragile: bool, remaining: float|null, per_week: float|null, share: int|null, alert: string|null}>
     */
    public function dashboard(?Carbon $date = null): Collection
    {
        [$start, $end] = $this->period($date);
        $spent = $this->spentByCategory($start, $end);
        $history = $this->history($start, 3);
        $today = Carbon::today();

        $days = (int) $start->diffInDays($end) + 1;
        $elapsed = match (true) {
            $today->lt($start) => 0,
            $today->gt($end) => $days,
            default => (int) $start->diffInDays($today) + 1,
        };

        return BudgetCategory::query()->active()->ordered()->get()
            ->map(function (BudgetCategory $category) use ($spent, $start, $days, $elapsed, $history) {
                $budget = $this->budgetFor($category, $start);
                $value = round($spent[$category->id] ?? 0.0, 2);
                [$projection, $fragile] = $this->projection($value, $days, $elapsed, array_map(fn ($p) => $p[$category->id] ?? 0.0, $history));

                $remainingDays = max(0, $days - $elapsed);
                $remaining = $budget !== null ? round($budget - $value, 2) : null;

                return [
                    'category' => $category,
                    'budget' => $budget,
                    'spent' => $value,
                    'pace' => $budget !== null ? round($budget * $elapsed / $days, 2) : null,
                    'projection' => $projection,
                    'fragile' => $fragile,
                    'remaining' => $remaining,
                    'per_week' => $remaining !== null && $remainingDays > 0 ? round(max(0, $remaining) / max(1, $remainingDays / 7), 2) : null,
                    'share' => $budget ? (int) round($value / $budget * 100) : null,
                    'alert' => $this->alert($value, $budget, $projection, $elapsed, $days),
                ];
            });
    }

    /**
     * R25 : dépensé + moyenne des 3 périodes précédentes ramenée aux jours restants ;
     * sans cet historique, simple prolongation du rythme actuel (« estimation fragile »).
     *
     * @param  list<float>  $previous  dépenses des périodes précédentes, de la plus récente à la plus ancienne
     * @return array{0: float, 1: bool}
     */
    public function projection(float $spent, int $days, int $elapsed, array $previous): array
    {
        if ($elapsed >= $days) {
            return [$spent, false];
        }

        $remainingShare = ($days - $elapsed) / $days;

        if (count($previous) >= 3 && count(array_filter($previous, fn ($v) => $v > 0)) >= 3) {
            return [round($spent + array_sum(array_slice($previous, 0, 3)) / 3 * $remainingShare, 2), false];
        }

        return [$elapsed > 0 ? round($spent / $elapsed * $days, 2) : $spent, true];
    }

    /** « soon » (80 %), « projected » (la projection dépasse), « over » (dépassé), ou null. */
    private function alert(float $spent, ?float $budget, float $projection, int $elapsed, int $days): ?string
    {
        if ($budget === null) {
            return null;
        }

        return match (true) {
            $spent > $budget => 'over',
            $spent >= $budget * self::ALERT_SHARE / 100 => 'soon',
            $elapsed > 0 && $elapsed < $days && $projection > $budget * self::PROJECTION_MARGIN => 'projected',
            default => null,
        };
    }

    /**
     * Dépenses par poste des N périodes précédentes, de la plus récente à la plus ancienne.
     *
     * @return list<array<int, float>>
     */
    public function history(Carbon $start, int $count): array
    {
        $periods = [];

        for ($i = 1; $i <= $count; $i++) {
            [$from, $to] = $this->shift($start, -$i);
            $periods[] = $this->spentByCategory($from, $to);
        }

        return $periods;
    }

    /* ================================================================ Qui a payé (23.8) */

    public function tracksPayer(): bool
    {
        return Settings::bool('budget.track_payer', false);
    }

    /**
     * @return Collection<int, array{user: User, total: float}>
     */
    public function payers(Carbon $from, Carbon $to): Collection
    {
        $totals = Expense::query()
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('paid_by')
            ->groupBy('paid_by')
            ->selectRaw('paid_by, sum(amount) as total')
            ->pluck('total', 'paid_by');

        return User::query()->inHousehold()->orderBy('name')->get()
            ->map(fn (User $user) => ['user' => $user, 'total' => round((float) ($totals[$user->id] ?? 0), 2)])
            ->filter(fn (array $row) => $row['total'] > 0 || $totals->isNotEmpty())
            ->values();
    }

    /* ================================================================ Outils */

    public function money(?float $amount, int $decimals = 2): string
    {
        return $this->prices->money($amount, $decimals);
    }

    /** Valeur estimée de ce qui a été jeté sur la période (C5). @return array{cost: float, count: int, priced: int} */
    public function wasteValue(Carbon $from, Carbon $to): array
    {
        $stats = app(\App\Services\Stock\WasteStats::class);
        $movements = StockMovement::query()
            ->where('type', MovementType::Waste->value)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->with(['ingredient.referencePriceUnit', 'unit'])
            ->get();

        $costs = $movements->map(fn (StockMovement $m) => $stats->movementCost($m));

        return ['cost' => round((float) $costs->filter()->sum(), 2), 'count' => $movements->count(), 'priced' => $costs->filter(fn ($c) => $c !== null)->count()];
    }
}
