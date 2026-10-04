<?php

namespace App\Livewire;

use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Services\Backup\BackupManager;
use App\Services\Planning\WeekPlanner;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Accueil')]
class Dashboard extends Component
{
    use \App\Livewire\Concerns\ClosesMeals;
    use \App\Livewire\Concerns\OffersUndo;
    use \App\Livewire\Concerns\PlacesLeftovers;

    public function greeting(): string
    {
        $hour = Carbon::now()->hour;

        return match (true) {
            $hour < 5 => 'Bonne nuit',
            $hour < 18 => 'Bonjour',
            default => 'Bonsoir',
        };
    }

    public function toggleCooked(int $mealId, WeekPlanner $planner): void
    {
        $meal = $planner->toggleCooked(PlannedMeal::findOrFail($mealId));
        $this->dispatch('meal-cooked', mealId: $meal->id, cooked: $meal->cooked_at !== null)->to(\App\Livewire\Stock\MealStockDialog::class);
    }

    public function render(WeekPlanner $planner, BackupManager $backups)
    {
        app(\App\Services\Shopping\ShoppingListManager::class)->closeStaleLists();
        $today = Carbon::today();
        $weekStart = $planner->weekStart($today);
        $slots = MealSlot::query()->active()->ordered()->get();
        $weekMeals = $planner->mealsForWeek($weekStart);

        $todayMeals = $weekMeals->filter(fn (PlannedMeal $m) => $m->date->isSameDay($today))->groupBy('meal_slot_id');

        $filledCells = $weekMeals
            ->filter(fn (PlannedMeal $m) => $slots->contains('id', $m->meal_slot_id))
            ->map(fn (PlannedMeal $m) => $m->date->toDateString().'|'.$m->meal_slot_id)
            ->unique()
            ->count();

        $tomorrow = $today->copy()->addDay();

        $weekDays = collect(range(0, 6))->map(function (int $offset) use ($weekStart, $weekMeals, $slots, $today) {
            $day = $weekStart->copy()->addDays($offset);
            $filled = $weekMeals->filter(fn (PlannedMeal $m) => $m->date->isSameDay($day) && $slots->contains('id', $m->meal_slot_id))->pluck('meal_slot_id')->unique()->count();

            return ['date' => $day, 'filled' => $filled, 'today' => $day->isSameDay($today)];
        });

        return view('livewire.dashboard', [
            'today' => $today,
            'toClose' => $toClose = app(\App\Services\Planning\MealClosing::class)->pending(),
            // « Hier comme prévu » (37.2) : les repas d'hier parmi ceux à clôturer.
            'yesterdayCount' => $toClose->filter(fn (PlannedMeal $m) => $m->date->isSameDay($today->copy()->subDay()))->count(),
            'sections' => collect(\App\Support\Navigation::home(auth()->user()))->where('visible', true)->pluck('key')->all(),
            'mealSlots' => $slots,
            'todayMeals' => $todayMeals,
            'tomorrowMeals' => PlannedMeal::query()->whereDate('date', $tomorrow->toDateString())
                ->whereIn('meal_slot_id', $slots->pluck('id'))->with(['recipe', 'leftoverOf.recipe', 'slot', 'forPerson'])
                ->orderBy('meal_slot_id')->orderBy('position')->get(),
            'filledCells' => $filledCells,
            'totalCells' => $slots->count() * 7,
            'weekDays' => $weekDays,
            'weekStart' => $weekStart,
            'activeList' => \App\Models\ShoppingList::query()->active()
                ->withCount([
                    'items as total_count' => fn ($q) => $q->where('is_removed', false),
                    'items as checked_count' => fn ($q) => $q->where('is_removed', false)->where('is_checked', true),
                ])
                ->orderByDesc('period_start')->first(),
            'ideas' => $planner->suggestions($weekStart, 3),
            'leftovers' => $this->leftovers($planner, $today),
            'lunchboxes' => app(\App\Services\Planning\Lunchboxes::class)->toPrepareOn($today),
            'tomorrow' => $tomorrow,
            'todayOccasions' => app(\App\Services\Planning\OccasionService::class)->forRange($today, $today)->keyBy('meal_slot_id'),
            'backupWarning' => $this->backupWarning($backups),
            'reminders' => $this->reminders($today),
            'yearReview' => $this->yearReview($today),
            // Fil des proches (26.10) : calculé seulement si le bloc est affiché.
            'linkedFeed' => in_array('linked', collect(\App\Support\Navigation::home(auth()->user()))->where('visible', true)->pluck('key')->all(), true)
                ? app(\App\Services\Linked\LinkedFeed::class)->items() : collect(),
            // Hausses de prix (lot 27, C2) : relevées ces 30 derniers jours, calculées si le bloc est affiché.
            'priceRises' => in_array('prices', collect(\App\Support\Navigation::home(auth()->user()))->where('visible', true)->pluck('key')->all(), true)
                ? app(\App\Services\Pricing\PersonalInflation::class)->rises(days: 30)->take(5) : collect(),
        ]);
    }

    /** « L'année en cuisine » (35.3) : proposée en décembre et jusqu'au 15 janvier, s'il y a de quoi. */
    private function yearReview(Carbon $today): ?int
    {
        $year = match (true) {
            $today->month === 12 => $today->year,
            $today->month === 1 && $today->day <= 15 => $today->year - 1,
            default => null,
        };

        return $year && PlannedMeal::query()->whereNotNull('cooked_at')->whereYear('date', $year)->exists() ? $year : null;
    }

    /** Rappels de préparation anticipée à faire aujourd'hui ou demain (14.6). */
    private function reminders(Carbon $today): \Illuminate\Support\Collection
    {
        $planner = app(\App\Services\Planning\PrepReminderPlanner::class);
        $planner->sync($today, $today->copy()->addDays(7));

        return $planner->due($today->copy()->addDay()->endOfDay())->take(5);
    }

    /** « C'est fait » depuis l'accueil. */
    public function reminderDone(int $reminderId): void
    {
        app(\App\Services\Planning\PrepReminderPlanner::class)->markDone(\App\Models\Reminder::findOrFail($reminderId));
        $this->dispatch('reminders-changed');
    }

    /**
     * Restes à finir : portions de repas pas encore placées, et plats préparés en stock.
     *
     * @return \Illuminate\Support\Collection<int, array{meal: PlannedMeal|null, remaining: int, item: \App\Models\StockItem|null, title: string}>
     */
    private function leftovers(WeekPlanner $planner, Carbon $today): \Illuminate\Support\Collection
    {
        $items = \App\Models\StockItem::query()->active()->whereNull('ingredient_id')->with('location', 'unit')->get();
        $byMeal = $items->whereNotNull('planned_meal_id')->keyBy('planned_meal_id');
        $rows = $planner->availableLeftovers($today, 3)->map(fn (array $row) => [
            'meal' => $row['meal'],
            'remaining' => $row['remaining'],
            'item' => $byMeal->get($row['meal']->id),
            'title' => (string) $row['meal']->recipe?->title,
        ]);

        $linked = $rows->pluck('meal.id')->all();
        $others = $items->reject(fn ($item) => $item->planned_meal_id && in_array($item->planned_meal_id, $linked, true))
            ->map(fn ($item) => ['meal' => null, 'remaining' => 0, 'item' => $item, 'title' => $item->name()]);

        return $rows->concat($others->values())->values();
    }

    /** Rappel si la sauvegarde automatique ne fonctionne pas (extension zip absente, dossier non accessible…). */
    private function backupWarning(BackupManager $backups): ?string
    {
        $days = (int) config('bouffe.backups.auto_days');
        $latest = $backups->latest();

        if (! $backups->zipAvailable()) {
            return 'Sauvegardes impossibles : l\'extension PHP zip est désactivée.';
        }

        if ($days <= 0) {
            return $latest === null || $latest->createdAt->lt(now()->subDays(30))
                ? 'Aucune sauvegarde depuis plus d\'un mois.'
                : null;
        }

        // La sauvegarde automatique a normalement lieu dans la journée où elle est due.
        if ($latest && $latest->createdAt->lt(now()->subDays($days + 2))) {
            return 'Dernière sauvegarde '.$latest->createdAt->locale('fr')->diffForHumans().'.';
        }

        return null;
    }
}
