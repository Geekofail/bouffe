<?php

namespace App\Http\Controllers;

use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\StockItem;
use App\Services\IngredientLineFormatter;
use App\Services\Planning\OccasionService;
use App\Services\Planning\WeekPlanner;
use App\Services\QuantityScaler;
use App\Services\Shopping\ShoppingListManager;
use App\Services\Stock\FreezerBoard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Pages prévues pour l'impression (lot 12) : une fiche recette (13.9) et le menu de la semaine (14.11).
 * Ce sont des pages simples (pas de Livewire) : mise en page A4, options dans l'adresse.
 */
class PrintController extends Controller
{
    /** /recettes/{recette}/imprimer?portions=6&photo=0&notes=1 */
    public function recipe(Request $request, Recipe $recipe): View
    {
        $servings = (float) str_replace(',', '.', (string) $request->query('portions')) > 0 ? \App\Services\Planning\Appetites::clamp($request->query('portions')) : (float) $recipe->servings;
        $scaler = app(QuantityScaler::class);
        $formatter = app(IngredientLineFormatter::class);

        $lines = $recipe->ingredients()->with('ingredient', 'unit')->get()
            ->map(fn ($line) => [
                'group' => (string) $line->group_name,
                'parts' => $formatter->format($scaler->scale($line->quantity, $recipe->servings, $servings), $line->unit, $line->ingredient),
                'preparation' => $line->preparation,
                'optional' => (bool) $line->is_optional,
            ])
            ->groupBy('group');

        return view('print.recipe', [
            'recipe' => $recipe->load('steps', 'tags'),
            'servings' => $servings,
            'groups' => $lines,
            'withPhoto' => $request->query('photo', '1') !== '0' && $recipe->photo_path,
            // Recette d'un foyer relié (lot 26) : ses notes restent chez lui.
            'withNotes' => ! $recipe->isForeign() && $request->query('notes', '1') !== '0',
            'cookNotes' => $recipe->isForeign() ? collect() : $recipe->cookNotes()->with('user')->limit(3)->get(),
        ]);
    }

    /** /planning/imprimer?semaine=2026-09-21&courses=1 */
    public function menu(Request $request, WeekPlanner $planner, OccasionService $occasions): View
    {
        $weekStart = $planner->weekStart($request->query('semaine') ?: Carbon::today());
        $weekEnd = $weekStart->copy()->addDays(6);
        $slots = MealSlot::query()->active()->ordered()->get();

        $meals = $planner->mealsForWeek($weekStart)
            ->groupBy(fn (PlannedMeal $meal) => $meal->date->toDateString().'|'.$meal->meal_slot_id);

        $withList = $request->query('courses') === '1';
        $list = $withList
            ? ShoppingList::query()->where('period_start', '<=', $weekEnd->toDateString())->where('period_end', '>=', $weekStart->toDateString())
                ->orderByDesc('period_start')->first()
            : null;

        return view('print.menu', [
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'days' => collect(range(0, 6))->map(fn (int $i) => $weekStart->copy()->addDays($i)),
            'slots' => $slots,
            'meals' => $meals,
            'occasions' => $occasions->forRange($weekStart, $weekEnd),
            'list' => $list,
            'grouped' => $list ? app(ShoppingListManager::class)->grouped($list) : null,
        ]);
    }

    /**
     * Étiquettes pour les boîtes du congélateur (16.5) : /stock/etiquettes?ids=1,2,3
     *
     * Chaque étiquette porte le nom, la date de congélation, les portions et un QR code qui
     * ouvre la fiche de l'article dans Bouffe — pratique quand l'étiquette a vécu et que le
     * feutre a pâli.
     */
    public function labels(Request $request): View
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->take(60);

        $board = app(FreezerBoard::class);

        $items = StockItem::query()
            ->whereIn('id', $ids)
            ->with(['ingredient', 'unit', 'location', 'plannedMeal.recipe'])
            ->get()
            ->sortBy(fn (StockItem $item) => $ids->search($item->id))
            ->values();

        return view('print.labels', [
            'items' => $items,
            'board' => $board,
            'today' => Carbon::today(),
        ]);
    }

    /**
     * Gamelles du midi (lot 32, 32.3) : /planning/gamelles?du=…&au=…
     * La liste de ce qu'il faut préparer chaque veille, puis une étiquette par gamelle
     * (les mêmes que celles du congélateur, lot 18).
     */
    public function lunchboxes(Request $request, \App\Services\Planning\Lunchboxes $lunchboxes): View
    {
        $from = $this->dateParam($request->query('du')) ?? Carbon::tomorrow();
        $to = $this->dateParam($request->query('au')) ?? $from->copy()->addDays(6);

        if ($to->lt($from) || $from->diffInDays($to) > 31) {
            $to = $from->copy()->addDays(6);
        }

        return view('print.lunchboxes', [
            'from' => $from,
            'to' => $to,
            'evenings' => $lunchboxes->byEvening($from, $to),
            'meals' => $lunchboxes->between($from, $to),
            'lunchboxes' => $lunchboxes,
        ]);
    }

    private function dateParam(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Carnet familial (26.9) : /carnet-familial/imprimer?recettes=1,2,3&titre=…
     * Une recette par page, avec un sommaire ; seules les recettes lisibles par le foyer sont reprises.
     */
    public function familyBook(Request $request, \App\Services\Linked\FamilyBook $book): View
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->query('recettes')))));
        $recipes = $book->recipes($ids);

        abort_if($recipes->isEmpty(), 404);

        return view('print.family-book', [
            'title' => mb_substr(trim((string) $request->query('titre')) ?: 'Le carnet de la famille', 0, 120),
            'recipes' => $recipes,
            'lines' => $recipes->mapWithKeys(fn ($recipe) => [$recipe->id => $book->lines($recipe)]),
        ]);
    }
}
