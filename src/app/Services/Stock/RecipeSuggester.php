<?php

namespace App\Services\Stock;

use App\Enums\ExpiryLevel;
use App\Enums\MealType;
use App\Enums\StockMode;
use App\Models\Ingredient;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\StockItem;
use App\Models\Unit;
use App\Services\QuantityFormatter;
use App\Services\QuantityScaler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * « Que cuisiner ? » (module 10, règle R10) : recettes faisables avec le stock, en priorité
 * celles qui utilisent ce qui périme bientôt.
 *
 * Le stock est lu une fois (« réserve ») : quantités restantes par article, en retirant ce qui est
 * promis aux repas planifiés des 7 prochains jours (10.3), puis chaque recette y est confrontée.
 */
class RecipeSuggester
{
    public const URGENT_DAYS = 2;

    public const SOON_DAYS = 5;

    public const RESERVATION_DAYS = 7;

    public const RECENT_DAYS = 14;

    public const BONUS_CAP = 40;

    /** Personnes à table d'habitude (allergies) : pour ne pas proposer un remplacement qui les heurte. */
    private ?Collection $householdEaters = null;

    public function __construct(
        private readonly StockAvailability $availability,
        private readonly ExpiryCalculator $expiry,
        private readonly QuantityScaler $scaler,
        private readonly QuantityFormatter $formatter,
    ) {}

    /**
     * @param  array{servings?: int, tagIds?: list<int>, maxMinutes?: int|null, mustUse?: list<int>, ignorePlanning?: bool, excludeMealId?: int|null}  $options
     * @return array{feasible: Collection<int, array>, almost: Collection<int, array>, others: Collection<int, array>, reserved: int}
     */
    public function suggest(array $options = []): array
    {
        $servings = max(0.5, (float) ($options['servings'] ?? 2));
        $mustUse = array_values(array_unique(array_map('intval', $options['mustUse'] ?? [])));
        $pool = $this->pool();
        $reserved = 0;

        if (empty($options['ignorePlanning'])) {
            $reserved = $this->reserve($pool, $options['excludeMealId'] ?? null);
        }

        $query = Recipe::query()->active()
            ->with(['ingredients.ingredient.defaultUnit', 'ingredients.unit', 'tags'])
            ->withAvg('ratings', 'rating');

        foreach ($options['tagIds'] ?? [] as $tagId) {
            $query->whereHas('tags', fn ($q) => $q->whereKey($tagId));
        }

        if (! empty($options['maxMinutes'])) {
            $query->maxTotalMinutes((int) $options['maxMinutes']);
        }

        if ($mustUse !== []) {
            $query->whereHas('ingredients', fn ($q) => $q->whereIn('ingredient_id', $mustUse));
        }

        $recent = $this->recentlyEaten();

        $results = $query->get()
            ->map(fn (Recipe $recipe) => $this->score($recipe, $this->evaluate($recipe, $servings, $pool), $mustUse, $recent))
            ->filter(fn (array $r) => $r['counted'] > 0)
            ->sortBy([['score', 'desc'], fn ($a, $b) => strcmp($a['recipe']->search_title, $b['recipe']->search_title)])
            ->values();

        return [
            'feasible' => $results->where('group', 'feasible')->values(),
            'almost' => $results->where('group', 'almost')->values(),
            'others' => $results->where('group', 'others')->values(),
            'reserved' => $reserved,
        ];
    }

    /**
     * Disponibilité de chaque ingrédient d'une recette (fiche recette, 10.2).
     *
     * @param  array<int, list<array>>|null  $pool  réserve déjà calculée ; sinon le stock actuel, sans réservation
     * @return array{lines: array<int, array>, counted: int, available: float, missing: list<array>, urgent: int}
     */
    public function evaluate(Recipe $recipe, int|float $servings, ?array $pool = null): array
    {
        $pool ??= $this->pool();
        $recipe->loadMissing('ingredients.ingredient.defaultUnit', 'ingredients.unit');
        $lines = [];

        foreach ($recipe->ingredients->groupBy('ingredient_id') as $ingredientId => $group) {
            $ingredient = $group->first()->ingredient;

            if (! $ingredient) {
                continue;
            }

            $optional = $group->every(fn ($l) => $l->is_optional);
            [$need, $unit] = $this->need($optional ? $group : $group->reject(fn ($l) => $l->is_optional), $recipe->servings, $servings, $ingredient);
            $line = [...$this->line($ingredient, $need, $unit, $pool[(int) $ingredientId] ?? [], isset($pool['tracked'][(int) $ingredientId])), 'optional' => $optional];

            // Lot 40 (40.1) : un ingrédient qui manque, mais dont un remplacement est en stock.
            if (! $optional && in_array($line['status'], ['missing', 'partial'], true)) {
                $line = $this->withSubstitute($line, $recipe, $need, $unit, $pool);
            }

            $lines[(int) $ingredientId] = $line;
        }

        $counted = collect($lines)->where('optional', false);
        $available = $counted->sum(fn ($l) => match ($l['status']) {
            'ok', 'check', 'assumed', 'substitute' => 1.0,
            'partial' => 0.5,
            default => 0.0,
        });

        return [
            'lines' => $lines,
            'counted' => $counted->count(),
            'available' => $available,
            'missing' => $counted->whereIn('status', ['missing', 'partial'])->values()->all(),
            'urgent' => $counted->sum('urgent'),
        ];
    }

    /**
     * Stock utilisable : articles actifs non périmés (DLC dépassée exclue), quantités restantes.
     *
     * @return array<int|string, mixed> [ingredient_id => list<{item, left, days}>, 'tracked' => [ingredient_id => true]]
     */
    public function pool(): array
    {
        $today = Carbon::today();
        $pool = ['tracked' => StockItem::query()->whereNotNull('ingredient_id')->distinct()->pluck('ingredient_id')->flip()->map(fn () => true)->all()];

        $items = StockItem::query()->active()->whereNotNull('ingredient_id')->with('unit', 'ingredient.defaultUnit')->get()
            ->reject(fn (StockItem $item) => $this->expiry->level($item, $today) === ExpiryLevel::Expired)
            ->sortBy(fn (StockItem $item) => $this->expiry->effective($item)['date']?->timestamp ?? PHP_INT_MAX);

        foreach ($items as $item) {
            $pool[$item->ingredient_id][] = [
                'item' => $item,
                'left' => $item->quantity === null ? null : (float) $item->quantity,
                'days' => $item->isFrozen() ? null : $this->expiry->daysLeft($item, $today),
            ];
        }

        return $pool;
    }

    /**
     * Retire de la réserve ce que les repas planifiés (aujourd'hui + 6 jours, pas encore mangés) vont utiliser.
     *
     * @return int nombre de repas pris en compte
     */
    public function reserve(array &$pool, ?int $excludeMealId = null): int
    {
        $today = Carbon::today();
        $meals = PlannedMeal::query()
            ->where('type', MealType::Recipe->value)
            ->whereNull('cooked_at')
            ->whereBetween('date', [$today->toDateString(), $today->copy()->addDays(self::RESERVATION_DAYS - 1)->toDateString()])
            ->when($excludeMealId, fn ($q) => $q->whereKeyNot($excludeMealId))
            ->with('recipe.ingredients.ingredient.defaultUnit', 'recipe.ingredients.unit')
            ->orderBy('date')
            ->get();

        foreach ($meals as $meal) {
            if ($meal->recipe) {
                $this->consume($pool, $meal->recipe, $meal->servings);
            }
        }

        return $meals->count();
    }

    /**
     * Retire de la réserve ce qu'une recette consommerait pour $servings portions.
     * Sert au remplissage automatique d'une semaine (R15), qui déduit le stock case après case.
     *
     * @param  array<int|string, mixed>  $pool
     */
    public function consume(array &$pool, Recipe $recipe, int|float $servings): void
    {
        $recipe->loadMissing('ingredients.ingredient.defaultUnit', 'ingredients.unit');

        foreach ($recipe->ingredients->reject(fn ($l) => $l->is_optional)->groupBy('ingredient_id') as $ingredientId => $group) {
            $ingredient = $group->first()->ingredient;
            [$need, $unit] = $ingredient ? $this->need($group, $recipe->servings, $servings, $ingredient) : [null, null];

            if ($need === null || empty($pool[$ingredientId])) {
                continue;
            }

            foreach ($pool[$ingredientId] as &$entry) {
                if ($need <= 0.0005) {
                    break;
                }

                $amount = $entry['left'] === null ? null : $this->availability->convert($entry['left'], $entry['item']->unit, $unit, $ingredient);

                if ($amount === null || $amount <= 0) {
                    continue;
                }

                $take = min($amount, $need);
                $need -= $take;
                $entry['left'] = $take >= $amount - 0.0005 ? 0.0 : $entry['left'] - ($this->availability->convert($take, $unit, $entry['item']->unit, $ingredient) ?? $entry['left']);
            }
            unset($entry);
        }
    }

    /* ================================================================ Outils */

    /**
     * @param  list<array{item: StockItem, left: float|null, days: int|null}>  $entries
     */
    private function line(Ingredient $ingredient, ?float $need, ?Unit $unit, array $entries, bool $tracked): array
    {
        $base = ['ingredient_id' => $ingredient->id, 'name' => $ingredient->name, 'need' => $need, 'unit_id' => $unit?->id, 'missing' => null, 'urgent' => 0, 'item_ids' => []];
        $needText = $need === null ? null : $this->formatter->format($need, $unit);
        $entries = array_values(array_filter($entries, fn ($e) => $e['left'] === null || $e['left'] > 0.0005));

        // Non suivi (lessive, eau…) ou produit de base jamais mis en stock : réputé disponible.
        if ($ingredient->stock_mode === StockMode::None || ($ingredient->is_staple && ! $tracked && $entries === [])) {
            return [...$base, 'status' => 'assumed', 'text' => 'produit de base'];
        }

        if ($entries === []) {
            return [...$base, 'status' => 'missing', 'missing' => $need, 'text' => 'manquant'.($needText ? ' ('.$needText.')' : '')];
        }

        $urgent = fn (array $used) => count(array_filter($used, fn ($e) => $e['days'] !== null && $e['days'] <= self::SOON_DAYS));

        if ($ingredient->stock_mode === StockMode::Presence || $need === null) {
            $used = [$entries[0]];

            return [...$base, 'status' => 'ok', 'text' => 'en stock', 'urgent' => $urgent($used), 'item_ids' => [$entries[0]['item']->id], 'bonus_days' => array_column($used, 'days')];
        }

        $amount = 0.0;
        $used = [];
        $unknown = false;

        foreach ($entries as $entry) {
            if ($amount >= $need - 0.0005) {
                break;
            }

            $value = $entry['left'] === null ? null : $this->availability->convert($entry['left'], $entry['item']->unit, $unit, $ingredient);

            if ($value === null) {
                $unknown = true;
                $used[] = $entry;

                continue;
            }

            $amount += $value;
            $used[] = $entry;
        }

        $result = [...$base, 'urgent' => $urgent($used), 'item_ids' => array_map(fn ($e) => $e['item']->id, $used), 'bonus_days' => array_column($used, 'days')];

        if ($amount >= $need - 0.0005) {
            return [...$result, 'status' => 'ok', 'text' => 'en stock'];
        }

        if ($unknown) {
            return [...$result, 'status' => 'check', 'text' => 'à vérifier (quantité inconnue)'];
        }

        if ($amount > 0.0005) {
            $missing = $need - $amount;

            return [...$result, 'status' => 'partial', 'missing' => round($missing, 3), 'text' => 'manque '.$this->formatter->format($missing, $unit)];
        }

        return [...$result, 'status' => 'missing', 'missing' => $need, 'text' => 'manquant ('.$needText.')'];
    }

    /**
     * Remplace une ligne manquante par un remplacement disponible en stock (R43 : jamais un
     * remplacement qui heurte l'allergie de quelqu'un du foyer).
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function withSubstitute(array $line, Recipe $recipe, ?float $need, ?Unit $unit, array $pool): array
    {
        $substitutions = app(\App\Services\Recipes\Substitutions::class);
        $this->householdEaters ??= app(\App\Services\Planning\HouseholdService::class)->people();

        foreach ($substitutions->for((int) $line['ingredient_id'], $recipe, $this->householdEaters) as $substitution) {
            $entries = $pool[$substitution->substitute_id] ?? [];

            if ($entries === []) {
                continue;
            }

            $candidate = $this->line($substitution->substitute, $need === null ? null : $need * (float) $substitution->ratio, $unit, $entries, true);

            if (in_array($candidate['status'], ['ok', 'check'], true)) {
                $name = mb_strtolower($substitution->substitute->name);

                return [
                    ...$line,
                    'status' => 'substitute',
                    'missing' => null,
                    'text' => 'avec '.$name.' à la place',
                    'substitute' => $substitution->substitute->name,
                    'substitute_id' => $substitution->substitute_id,
                    'item_ids' => $candidate['item_ids'],
                    'urgent' => $candidate['urgent'],
                    'bonus_days' => $candidate['bonus_days'] ?? [],
                ];
            }
        }

        return $line;
    }

    /** @return array<int, true> recettes mangées dans les 14 derniers jours */
    private function recentlyEaten(): array
    {
        $today = Carbon::today();

        return PlannedMeal::query()
            ->where('type', MealType::Recipe->value)
            ->where(fn ($q) => $q
                ->whereBetween('date', [$today->copy()->subDays(self::RECENT_DAYS)->toDateString(), $today->copy()->subDay()->toDateString()])
                ->orWhere(fn ($q) => $q->whereDate('date', $today->toDateString())->whereNotNull('cooked_at')))
            ->pluck('recipe_id')->filter()->unique()->flip()->map(fn () => true)->all();
    }

    private function score(Recipe $recipe, array $evaluation, array $mustUse, array $recent): array
    {
        $counted = $evaluation['counted'];
        $coverage = $counted > 0 ? $evaluation['available'] / $counted : 0.0;
        $gaps = count($evaluation['missing']);
        $lines = collect($evaluation['lines']);

        $bonus = 0;
        $seen = [];

        foreach ($lines->where('optional', false) as $line) {
            foreach ($line['item_ids'] as $i => $itemId) {
                $days = $line['bonus_days'][$i] ?? null;

                if (isset($seen[$itemId]) || $days === null) {
                    continue;
                }

                $seen[$itemId] = true;
                $bonus += match (true) {
                    $days <= self::URGENT_DAYS => 15,
                    $days <= self::SOON_DAYS => 8,
                    default => 0,
                };
            }
        }

        $usesMustUse = $mustUse !== [] && $lines->keys()->intersect($mustUse)->isNotEmpty();
        $bonus = min(self::BONUS_CAP, $bonus + ($usesMustUse ? 20 : 0));

        $score = 100 * $coverage + $bonus
            - (isset($recent[$recipe->id]) ? 20 : 0)
            + ($recipe->is_favorite ? 5 : 0)
            + ((float) $recipe->ratings_avg_rating >= 4 ? 5 : 0);

        return [
            'recipe' => $recipe,
            'score' => round($score, 1),
            'coverage' => $coverage,
            'counted' => $counted,
            'available' => (int) $lines->where('optional', false)->whereIn('status', ['ok', 'check', 'assumed', 'substitute'])->count(),
            // Lot 40 : « avec crème de soja à la place de crème liquide ».
            'substitutes_text' => $lines->where('status', 'substitute')->map(fn ($l) => mb_strtolower($l['substitute']).' à la place de '.mb_strtolower($l['name']))->join(', '),
            'lines' => $evaluation['lines'],
            'missing' => $evaluation['missing'],
            'missing_text' => collect($evaluation['missing'])->map(fn ($l) => mb_strtolower($l['name']).($l['missing'] !== null ? ' '.$this->formatter->format($l['missing'], $l['unit_id'] ? Unit::find($l['unit_id']) : null) : ''))->join(', '),
            'urgent' => $evaluation['urgent'],
            'recent' => isset($recent[$recipe->id]),
            'group' => match (true) {
                $gaps === 0 => 'feasible',
                $gaps <= 2 && $coverage >= 0.5 => 'almost',   // au moins la moitié disponible : « 0/2 » n'est pas « presque »
                default => 'others',
            },
        ];
    }

    /**
     * Besoin total d'un ingrédient pour $servings portions, dans l'unité de la première ligne chiffrée.
     *
     * @return array{0: float|null, 1: Unit|null}
     */
    private function need(Collection $lines, int $recipeServings, int|float $servings, Ingredient $ingredient): array
    {
        $unit = $lines->first(fn ($l) => $l->quantity !== null)?->unit ?? $lines->first()?->unit;
        $total = null;

        foreach ($lines as $line) {
            $quantity = $this->scaler->scale($line->quantity, max(1, $recipeServings), $servings);

            if ($quantity === null) {
                continue;
            }

            $converted = $this->availability->convert($quantity, $line->unit, $unit, $ingredient);
            $total = ($total ?? 0.0) + ($converted ?? 0.0);
        }

        return [$total, $unit];
    }
}
