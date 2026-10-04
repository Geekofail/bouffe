<?php

namespace App\Services\Planning;

use App\Enums\Course;
use App\Enums\MealType;
use App\Enums\MovementType;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\StockMovement;
use App\Services\Seasons\SeasonCalendar;
use App\Services\Stock\WasteStats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Statistiques de planning (lot 35, 35.2) et « L'année en cuisine » (35.3).
 *
 * Uniquement des chiffres réels, chacun avec sa base : « 12 repas végétariens sur 80 plats ».
 * Un repas passé ni coché ni marqué « pas fait » n'est ni mangé ni raté : il est « à clôturer »
 * et le dit. Les jours à venir ne comptent pas.
 */
class PlanningStats
{
    /** @var array<int, list<string>> familles par recette (WeekBalance) */
    private array $families = [];

    /** @var array<string, string> état de saison par recette et par mois */
    private array $seasons = [];

    public function __construct(
        private readonly WeekBalance $balance,
        private readonly SeasonCalendar $calendar,
        private readonly WasteStats $waste,
    ) {}

    /**
     * Toutes les statistiques d'une période (bornes comprises, arrêtée à aujourd'hui).
     *
     * @return array<string, mixed>
     */
    public function period(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay()->min(Carbon::today());

        if ($to->lt($from)) {
            $to = $from->copy();
        }

        $meals = $this->meals($from, $to);
        $eaten = $meals->filter(fn (PlannedMeal $meal) => $meal->cooked_at !== null);
        $cooked = $eaten->filter(fn (PlannedMeal $meal) => $meal->type === MealType::Recipe && $meal->recipe_id);

        return [
            'from' => $from,
            'to' => $to,
            'days' => (int) $from->diffInDays($to) + 1,
            'weeks' => max(1, (int) ceil(($from->diffInDays($to) + 1) / 7)),
            'occasions' => $eaten->map(fn (PlannedMeal $meal) => $meal->date->toDateString().'|'.$meal->meal_slot_id)->unique()->count(),
            'dishes' => $eaten->count(),
            'followed' => $this->followed($meals),
            'top' => $this->top($cooked),
            'distinct' => $cooked->pluck('recipe_id')->unique()->count(),
            'new' => $this->newRecipes($cooked, $from),
            'vegetarian' => $this->vegetarian($eaten, $from, $to),
            'season' => $this->seasonal($eaten),
            'leftovers' => $this->leftovers($meals, $from, $to),
        ];
    }

    /**
     * Part des repas planifiés réellement mangés : repas avec une recette ou des restes, jours passés.
     *
     * @return array{planned: int, eaten: int, skipped: int, pending: int, share: int|null}
     */
    public function followed(Collection $meals): array
    {
        $past = $meals->filter(fn (PlannedMeal $meal) => $meal->type !== MealType::Free && $meal->date->lt(Carbon::today()));
        $eaten = $past->whereNotNull('cooked_at')->count();
        $skipped = $past->whereNull('cooked_at')->whereNotNull('skipped_at')->count();

        return [
            'planned' => $past->count(),
            'eaten' => $eaten,
            'skipped' => $skipped,
            'pending' => $past->count() - $eaten - $skipped,
            'share' => $past->isNotEmpty() ? (int) round($eaten / $past->count() * 100) : null,
        ];
    }

    /**
     * Recettes les plus cuisinées (restes non comptés : ce n'est pas cuisiner une deuxième fois).
     *
     * @return Collection<int, array{recipe: Recipe, count: int, last: Carbon}>
     */
    public function top(Collection $cooked, int $limit = 10): Collection
    {
        return $cooked->groupBy('recipe_id')
            ->map(fn (Collection $group) => [
                'recipe' => $group->first()->recipe,
                'count' => $group->count(),
                'last' => $group->max('date'),
            ])
            ->filter(fn (array $row) => $row['recipe'] !== null)
            ->sortBy([['count', 'desc'], [fn ($a, $b) => $b['last'] <=> $a['last']]])
            ->take($limit)
            ->values();
    }

    /**
     * Recettes cuisinées pour la première fois pendant la période.
     *
     * @return array{count: int, titles: list<string>}
     */
    public function newRecipes(Collection $cooked, Carbon $from): array
    {
        $ids = $cooked->pluck('recipe_id')->unique()->values();

        if ($ids->isEmpty()) {
            return ['count' => 0, 'titles' => []];
        }

        $before = PlannedMeal::query()->whereIn('recipe_id', $ids)
            ->where('type', MealType::Recipe->value)->whereNotNull('cooked_at')
            ->where('date', '<', $from->toDateString())
            ->distinct()->pluck('recipe_id');

        $new = $cooked->whereNotIn('recipe_id', $before->all())->sortBy('date')->unique('recipe_id');

        return ['count' => $new->count(), 'titles' => $new->map(fn (PlannedMeal $meal) => (string) $meal->recipe?->title)->filter()->values()->all()];
    }

    /**
     * Plats principaux mangés sans viande ni poisson (même classement que l'équilibre de la semaine).
     *
     * @return array{mains: int, count: int, share: int|null, perWeek: float, weeks: list<array{start: Carbon, count: int, mains: int}>}
     */
    public function vegetarian(Collection $eaten, Carbon $from, Carbon $to): array
    {
        $mains = $eaten->filter(fn (PlannedMeal $meal) => ($meal->course === null || $meal->course === Course::Main) && $meal->eatenRecipe());
        $veggie = $mains->filter(fn (PlannedMeal $meal) => in_array('vegetarien', $this->familiesOf($meal->eatenRecipe()), true));
        $weeks = max(1, (int) ceil(($from->diffInDays($to) + 1) / 7));

        // Semaine par semaine (lundi), pour les 12 dernières semaines de la période au plus.
        $series = [];
        $start = $to->copy()->startOfWeek()->subWeeks(min(12, $weeks) - 1)->max($from->copy()->startOfWeek());

        for ($week = $start->copy(); $week->lte($to); $week->addWeek()) {
            $end = $week->copy()->addDays(6);
            $in = fn (PlannedMeal $meal) => $meal->date->between($week, $end);
            $series[] = ['start' => $week->copy(), 'count' => $veggie->filter($in)->count(), 'mains' => $mains->filter($in)->count()];
        }

        return [
            'mains' => $mains->count(),
            'count' => $veggie->count(),
            'share' => $mains->isNotEmpty() ? (int) round($veggie->count() / $mains->count() * 100) : null,
            'perWeek' => round($veggie->count() / $weeks, 1),
            'weeks' => $series,
        ];
    }

    /**
     * Plats de saison au mois où ils ont été mangés (R18). Base : les plats qui ont au moins un
     * fruit ou légume de saison connue ; les autres (pâtes au beurre) ne comptent ni pour ni contre.
     *
     * @return array{base: int, count: int, share: int|null}
     */
    public function seasonal(Collection $eaten): array
    {
        $statuses = $eaten->map(fn (PlannedMeal $meal) => $meal->eatenRecipe() ? $this->seasonOf($meal->eatenRecipe(), $meal->date) : null)
            ->filter(fn (?string $status) => $status !== null && $status !== 'none');
        $count = $statuses->filter(fn (string $status) => $status === SeasonCalendar::IN_SEASON)->count();

        return ['base' => $statuses->count(), 'count' => $count, 'share' => $statuses->isNotEmpty() ? (int) round($count / $statuses->count() * 100) : null];
    }

    /**
     * Restes : repas « restes » planifiés (mangés ou pas faits) et plats préparés du stock
     * (finis ou jetés). Seuls les repas clôturés et les gestes non annulés comptent.
     *
     * @return array{meals: int, eaten: int, dishes: int, finished: int, wasted: int, share: int|null}
     */
    public function leftovers(Collection $meals, Carbon $from, Carbon $to): array
    {
        $leftovers = $meals->filter(fn (PlannedMeal $meal) => $meal->type === MealType::Leftover && ($meal->cooked_at || $meal->skipped_at));
        $eaten = $leftovers->whereNotNull('cooked_at')->count();

        $dishes = $this->movements($from, $to)
            ->whereNull('ingredient_id')
            ->where(fn ($q) => $q->whereNull('reason')->orWhere('reason', '!=', \App\Services\Stays\StayPacking::REASON))   // emporté en séjour (34.4)
            ->whereIn('type', [MovementType::Consume->value, MovementType::Waste->value])
            ->get(['id', 'type']);
        $finished = $dishes->where('type', MovementType::Consume)->count();
        $wasted = $dishes->where('type', MovementType::Waste)->count();
        $base = $leftovers->count() + $finished + $wasted;

        return [
            'meals' => $leftovers->count(),
            'eaten' => $eaten,
            'dishes' => $finished + $wasted,
            'finished' => $finished,
            'wasted' => $wasted,
            'share' => $base > 0 ? (int) round(($eaten + $finished) / $base * 100) : null,
        ];
    }

    /** « 3 repas de restes mangés sur 4 · plats du stock : 2 finis, 1 jeté » : la base du pourcentage. */
    public static function leftoversBase(array $left): string
    {
        $s = fn (int $n) => $n > 1 ? 's' : '';
        $parts = array_filter([
            $left['meals'] > 0 ? $left['eaten'].' repas de restes mangé'.$s($left['eaten']).' sur '.$left['meals'] : null,
            $left['dishes'] > 0 ? 'plats du stock : '.$left['finished'].' fini'.$s($left['finished']).', '.$left['wasted'].' jeté'.$s($left['wasted']) : null,
        ]);

        return $parts !== [] ? implode(' · ', $parts) : 'aucun reste planifié ni plat préparé en stock';
    }

    /**
     * Ce qui a été jeté (tout le stock), en nombre et en euros d'après les prix relevés.
     *
     * @return array{count: int, priced: int, cost: float}
     */
    public function wasted(Carbon $from, Carbon $to): array
    {
        $movements = $this->movements($from, $to)->where('type', MovementType::Waste->value)
            ->with(['ingredient.referencePriceUnit', 'unit', 'item.ingredient', 'item.unit'])->get();
        $costs = $movements->map(fn (StockMovement $movement) => $this->waste->movementCost($movement))->filter(fn ($cost) => $cost !== null);

        return ['count' => $movements->count(), 'priced' => $costs->count(), 'cost' => round((float) $costs->sum(), 2)];
    }

    /* ================================================================ 35.3 L'année en cuisine */

    /**
     * Bilan d'une année civile, arrêté à aujourd'hui pour l'année en cours ; comparé à la même
     * période de l'année précédente quand elle a des données.
     *
     * @return array<string, mixed>
     */
    public function year(int $year): array
    {
        $from = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->startOfDay();
        $to = $end->copy()->min(Carbon::today());
        $stats = $this->period($from, $to);

        $previousFrom = $from->copy()->subYear();
        $previousTo = $to->copy()->subYear();
        $previousDishes = PlannedMeal::query()->whereNotNull('cooked_at')
            ->whereBetween('date', [$previousFrom->toDateString(), $previousTo->toDateString()])->count();

        $waste = $this->wasted($from, $to);
        $previousWaste = $previousDishes > 0 || $this->movements($previousFrom, $previousTo)->exists()
            ? $this->wasted($previousFrom, $previousTo) : null;

        return $stats + [
            'year' => $year,
            'complete' => $to->equalTo($end),
            'waste' => $waste,
            'previous' => $previousDishes > 0 ? [
                'from' => $previousFrom,
                'to' => $previousTo,
                'dishes' => $previousDishes,
                'waste' => $previousWaste,
            ] : null,
            'months' => $this->months($from, $to),
        ];
    }

    /**
     * Années pour lesquelles il existe des repas mangés, la plus récente d'abord.
     *
     * @return list<int>
     */
    public function years(): array
    {
        $first = PlannedMeal::query()->whereNotNull('cooked_at')->min('date');

        if (! $first) {
            return [(int) Carbon::today()->year];
        }

        return array_reverse(range((int) Carbon::parse($first)->year, (int) Carbon::today()->year));
    }

    /**
     * Plats mangés mois par mois.
     *
     * @return list<array{month: Carbon, label: string, dishes: int}>
     */
    private function months(Carbon $from, Carbon $to): array
    {
        $counts = PlannedMeal::query()->whereNotNull('cooked_at')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get(['date'])->countBy(fn (PlannedMeal $meal) => $meal->date->format('Y-m'));

        $months = [];

        for ($month = $from->copy()->startOfMonth(); $month->lte($to); $month->addMonth()) {
            $months[] = ['month' => $month->copy(), 'label' => ucfirst($month->locale('fr')->isoFormat('MMM')), 'dishes' => (int) $counts->get($month->format('Y-m'), 0)];
        }

        return $months;
    }

    /* ================================================================ Outils */

    /** @return Collection<int, PlannedMeal> */
    private function meals(Carbon $from, Carbon $to): Collection
    {
        $meals = PlannedMeal::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->with(['recipe', 'leftoverOf.recipe'])
            ->orderBy('date')
            ->get();

        // Toutes les recettes d'un coup, avec ce qu'il faut pour les familles et les saisons.
        $recipes = $meals->map(fn (PlannedMeal $meal) => $meal->eatenRecipe())->filter()->unique('id');
        (new \Illuminate\Database\Eloquent\Collection($recipes->values()->all()))
            ->loadMissing(['ingredients.ingredient.aisle', 'ingredients.unit', 'tags']);

        return $meals;
    }

    /** Mouvements de stock de la période, sans ceux qui ont été annulés ni les annulations. */
    private function movements(Carbon $from, Carbon $to): \Illuminate\Database\Eloquent\Builder
    {
        $reverted = StockMovement::query()->whereNotNull('reverts_movement_id')->select('reverts_movement_id');

        return StockMovement::query()
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereNotIn('id', $reverted);
    }

    /** @return list<string> */
    private function familiesOf(Recipe $recipe): array
    {
        return $this->families[$recipe->id] ??= $this->balance->familiesOf($recipe);
    }

    /** « season », « off », « neutral », ou « none » (aucun fruit ni légume de saison connue). */
    private function seasonOf(Recipe $recipe, Carbon $date): string
    {
        return $this->seasons[$recipe->id.'-'.$date->month] ??= (function () use ($recipe, $date) {
            $status = $this->calendar->recipeStatus($recipe, $date);

            if ($status['status'] === SeasonCalendar::NEUTRAL && $status['offenders'] === [] && $status['main'] === null) {
                return 'none';
            }

            return $status['status'];
        })();
    }
}
