<?php

namespace App\Http\Controllers;

use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\Stay;
use App\Models\StayMeal;
use App\Services\IngredientLineFormatter;
use App\Services\Planning\Appetites;
use App\Services\Planning\WeekPlanner;
use App\Services\QuantityScaler;
use App\Services\Recipes\StepTimers;
use App\Services\Recipes\SubRecipes;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Cuisiner sans réseau (lot 41, 41.2).
 *
 * Des pages autonomes, sans Livewire : la liste des recettes de la semaine (ou d'un séjour), et
 * chaque recette aux portions prévues, avec ses étapes et ses minuteurs. « Garder sur cet appareil »
 * les enregistre dans le téléphone (service web du lot 16) : elles s'ouvrent ensuite au chalet sans
 * Wi-Fi comme à la cave sans réseau. Les minuteurs y sont partagés s'il y a du réseau, locaux sinon.
 *
 *   /sans-reseau?semaine=2026-10-19     recettes de la semaine
 *   /sans-reseau/sejour/{stay}          recettes d'un séjour (lot 34)
 *   /sans-reseau/recette/{recipe}?portions=4
 */
class OfflineRecipesController extends Controller
{
    public function week(Request $request, WeekPlanner $planner): View
    {
        $weekStart = $planner->weekStart(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('semaine')) ? $request->query('semaine') : Carbon::today());
        $slots = MealSlot::query()->active()->ordered()->get()->keyBy('id');
        $meals = $planner->mealsForWeek($weekStart)->filter(fn (PlannedMeal $meal) => $slots->has($meal->meal_slot_id));

        $days = collect(range(0, 6))->map(function (int $offset) use ($weekStart, $meals, $slots) {
            $date = $weekStart->copy()->addDays($offset);

            return [
                'date' => $date,
                'rows' => $meals->filter(fn (PlannedMeal $meal) => $meal->date->isSameDay($date))
                    ->sortBy(fn (PlannedMeal $meal) => [$slots[$meal->meal_slot_id]->sort_order ?? 0, $meal->course?->order() ?? 3, $meal->position])
                    ->map(fn (PlannedMeal $meal) => [
                        'slot' => $slots[$meal->meal_slot_id]->name ?? '',
                        'label' => $meal->label(),
                        'recipe' => $meal->isRecipe() ? $meal->recipe : null,
                        'servings' => (float) $meal->servings,
                    ])->values(),
            ];
        });

        return view('offline.list', [
            'title' => 'Semaine du '.$weekStart->locale('fr')->isoFormat('D MMMM'),
            'days' => $days,
            'links' => $this->links($days),
            'previous' => route('offline.week', ['semaine' => $weekStart->copy()->subWeek()->toDateString()]),
            'next' => route('offline.week', ['semaine' => $weekStart->copy()->addWeek()->toDateString()]),
            'stays' => Stay::query()->whereDate('ends_on', '>=', Carbon::today()->toDateString())->orderBy('starts_on')->limit(3)->get(),
            'back' => route('planner.week', ['semaine' => $weekStart->toDateString()]),
        ]);
    }

    public function stay(Stay $stay): View
    {
        $meals = $stay->meals()->with('recipe', 'slot')->orderBy('date')->get();

        $days = collect($stay->days())->map(fn (Carbon $date) => [
            'date' => $date,
            'rows' => $meals->filter(fn (StayMeal $meal) => $meal->date->isSameDay($date))
                ->sortBy(fn (StayMeal $meal) => [$meal->slot?->sort_order ?? 0, $meal->position])
                ->map(fn (StayMeal $meal) => [
                    'slot' => $meal->slot?->name ?? '',
                    'label' => $meal->label(),
                    // Lot 42 : une recette d'un autre foyer du séjour ne se garde pas ici, seul son titre.
                    'recipe' => $meal->recipe && (int) $meal->recipe->household_id === (int) \App\Support\CurrentHousehold::id() ? $meal->recipe : null,
                    'servings' => (float) $meal->servings,
                ])->values(),
        ]);

        return view('offline.list', [
            'title' => 'Séjour « '.$stay->name.' »',
            'days' => $days,
            'links' => $this->links($days),
            'previous' => null,
            'next' => null,
            'stays' => collect(),
            'back' => route('stays.show', ['stay' => $stay, 'onglet' => 'repas']),
        ]);
    }

    public function recipe(Request $request, Recipe $recipe, QuantityScaler $scaler, IngredientLineFormatter $formatter, StepTimers $timers): View
    {
        $servings = (float) str_replace(',', '.', (string) $request->query('portions')) > 0
            ? Appetites::clamp($request->query('portions'))
            : (float) $recipe->servings;

        $lines = app(SubRecipes::class)->lines($recipe)
            ->map(fn ($line) => $formatter->format($scaler->scale($line->quantity, $recipe->servings, $servings), $line->unit, $line->ingredient)
                + ['optional' => (bool) $line->is_optional, 'group' => (string) ($line->via_recipe ?? $line->group_name ?? '')])
            ->groupBy('group');

        return view('offline.recipe', [
            'recipe' => $recipe->load('steps'),
            'servings' => $servings,
            'groups' => $lines,
            'steps' => $recipe->steps->values()->map(fn ($step, $i) => [
                'number' => $i + 1,
                'group' => $step->group_name,
                'text' => $step->instruction,
                'timers' => $timers->extract($step->instruction),
            ]),
            'back' => route('offline.week'),
        ]);
    }

    /**
     * Pages à garder : une par recette et par nombre de portions.
     *
     * @param  Collection<int, array{date: Carbon, rows: Collection}>  $days
     * @return list<string>
     */
    private function links(Collection $days): array
    {
        return $days->flatMap(fn (array $day) => $day['rows'])
            ->filter(fn (array $row) => $row['recipe'] !== null)
            ->map(fn (array $row) => self::recipeUrl($row['recipe'], $row['servings']))
            ->unique()->values()->all();
    }

    public static function recipeUrl(Recipe $recipe, float $servings): string
    {
        return route('offline.recipe', ['recipe' => $recipe, 'portions' => Appetites::format($servings)]);
    }
}
