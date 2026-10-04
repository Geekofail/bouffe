<?php

namespace App\Services\Planning;

use App\Enums\MealType;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\Wish;
use App\Services\Stock\RecipeSuggester;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Remplissage automatique d'une semaine (14.1, 14.2 — règle R15).
 *
 * Le service ne touche à rien : il **propose**. L'écran affiche les propositions, chacune
 * pouvant être relancée, verrouillée ou retirée, et seule la validation les enregistre.
 *
 * Une proposition est un tableau de valeurs simples (pas d'objet Eloquent) pour pouvoir vivre
 * dans l'état d'un composant Livewire.
 */
class WeekFiller
{
    /** Une recette mangée dans les 14 derniers jours n'est pas reproposée. */
    public const RECENT_DAYS = 14;

    /** « Ça fait longtemps » : plafond du bonus d'ancienneté. */
    public const LONG_TIME_CAP = 60;

    /** Dernière date à laquelle chaque recette a été planifiée (rempli au début du calcul). @var array<int, string> */
    private array $lastPlanned = [];

    /** Bilan 👍 / 👎 par recette (18.4), rempli au début du calcul. @var array<int, array> */
    private array $feedback = [];

    /** @var array<int, list<string>> familles des recettes, calculées une fois (cantine, lot 39) */
    private array $recipeFamilies = [];

    public function __construct(
        private readonly WeekPlanner $planner,
        private readonly OccasionService $occasions,
        private readonly GuestCompatibility $compatibility,
        private readonly PlanningRules $rules,
        private readonly RecipeSuggester $suggester,
        private readonly MealFeedback $reactions,
    ) {}

    /**
     * @param  array{useStock?: bool, useWishes?: bool, slotIds?: list<int>, pickFrom?: int, keep?: list<array>, avoid?: array<string, list<int>>}  $options
     *                                                                                                                                                        keep : propositions à conserver telles quelles (cases verrouillées)
     *                                                                                                                                                        avoid : par case, recettes à ne pas reproposer (bouton 🎲)
     * @return list<array{key: string, date: string, slot_id: int, slot: string, day: string, type: string, recipe_id: int|null, title: string, servings: int, leftover_key: string|null, leftover_meal_id: int|null, score: float, reasons: list<string>, warnings: list<string>, locked: bool, wish_id: int|null}>
     */
    public function propose(Carbon $weekStart, array $options = []): array
    {
        $weekStart = $this->planner->weekStart($weekStart);
        $useStock = (bool) ($options['useStock'] ?? true);
        $useWishes = (bool) ($options['useWishes'] ?? true);
        $useLeftovers = (bool) ($options['useLeftovers'] ?? true);
        $pickFrom = max(1, (int) ($options['pickFrom'] ?? config('bouffe.planning.fill_pick_from', 5)));
        $keep = collect($options['keep'] ?? [])->keyBy('key');
        $avoid = $options['avoid'] ?? [];

        $slots = MealSlot::query()->active()->ordered()->get();
        $slotIds = $options['slotIds'] ?? $slots->pluck('id')->all();
        $slots = $slots->whereIn('id', $slotIds)->values();

        $recipes = $this->candidates($useStock);
        $existing = $this->planner->mealsForWeek($weekStart);
        $this->lastPlanned = $this->lastPlannedDates();
        $this->feedback = $this->reactions->balances();
        $wishes = $useWishes ? Wish::query()->open()->whereNotNull('recipe_id')->with('recipe')->get()->keyBy('recipe_id') : collect();
        $eaters = $this->eatersByCell($weekStart);

        $used = $existing->map(fn (PlannedMeal $m) => $m->eatenRecipe()?->id)->filter()->unique()->flip()->all();
        $tagCounts = $this->rules->countByTag($existing);
        $byDay = $existing->groupBy(fn (PlannedMeal $m) => $m->date->toDateString());

        $pool = [];

        if ($useStock) {
            $pool = $this->suggester->pool();
            $this->suggester->reserve($pool);
        }

        $proposals = [];
        $leftovers = [];   // cases déjà réservées aux restes : clé => proposition

        foreach ($this->planner->days($weekStart) as $day) {
            foreach ($slots as $slot) {
                $key = $day->toDateString().'|'.$slot->id;

                if ($this->isTaken($existing, $day, $slot)) {
                    continue;
                }

                // Case verrouillée : la proposition précédente est conservée telle quelle.
                if ($kept = $keep->get($key)) {
                    $proposals[$key] = $kept;
                    $this->remember($kept, $used, $tagCounts, $byDay, $pool, $useStock, $recipes);

                    continue;
                }

                // Case déjà réservée aux restes d'un repas proposé plus tôt.
                if (isset($leftovers[$key])) {
                    $proposals[$key] = $leftovers[$key];

                    continue;
                }

                $servings = $this->occasions->servingsAt($day, $slot);
                $choice = $this->choose($recipes, [
                    'date' => $day,
                    'slot' => $slot,
                    'servings' => $servings,
                    'used' => $used,
                    'tagCounts' => $tagCounts,
                    'previousDay' => $byDay->get($day->copy()->subDay()->toDateString(), collect()),
                    'guests' => $eaters['cells'][$key] ?? $eaters['default'],
                    'wishes' => $wishes,
                    'pool' => $pool,
                    'useStock' => $useStock,
                    'avoid' => $avoid[$key] ?? [],
                    'pickFrom' => $pickFrom,
                    'useLeftovers' => $useLeftovers,
                    'canteen' => $this->canteenFor($day, $slot),
                ]);

                if (! $choice) {
                    continue;
                }

                $proposals[$key] = $choice;
                $this->remember($choice, $used, $tagCounts, $byDay, $pool, $useStock, $recipes);

                // Une recette qui laisse au moins deux portions occupe la case libre du lendemain (R15).
                if ($useLeftovers && ($leftover = $this->leftoverFor($choice, $recipes, $slots, $existing, $proposals, $leftovers, $weekStart))) {
                    $leftovers[$leftover['key']] = $leftover;
                }
            }
        }

        return array_values($proposals);
    }

    /**
     * Enregistre les propositions (bouton « Valider »).
     *
     * @param  list<array>  $proposals
     * @return int nombre de repas ajoutés
     */
    public function apply(array $proposals): int
    {
        return DB::transaction(function () use ($proposals) {
            $created = [];

            // Les recettes d'abord : les restes ont besoin de l'identifiant du repas d'origine.
            foreach ($proposals as $proposal) {
                if (($proposal['type'] ?? '') !== MealType::Recipe->value || empty($proposal['recipe_id'])) {
                    continue;
                }

                $meal = $this->planner->addRecipe($proposal['date'], (int) $proposal['slot_id'], (int) $proposal['recipe_id'], (float) $proposal['servings']);
                $created[$proposal['key']] = $meal;

                if (! empty($proposal['wish_id'])) {
                    Wish::query()->whereKey($proposal['wish_id'])->update(['planned_meal_id' => $meal->id, 'planned_at' => now()]);
                }
            }

            foreach ($proposals as $proposal) {
                if (($proposal['type'] ?? '') !== MealType::Leftover->value) {
                    continue;
                }

                $source = $created[$proposal['leftover_key'] ?? ''] ?? PlannedMeal::find($proposal['leftover_meal_id'] ?? 0);

                if ($source) {
                    $this->planner->addLeftover($proposal['date'], (int) $proposal['slot_id'], $source, (float) $proposal['servings']);
                    $created[$proposal['key']] = $source;
                }
            }

            return count(array_filter($proposals, fn ($p) => isset($created[$p['key']])));
        });
    }

    /* ================================================================ Choix d'une case */

    /**
     * @param  Collection<int, Recipe>  $recipes
     * @return array<string, mixed>|null
     */
    private function choose(Collection $recipes, array $context): ?array
    {
        $scored = $this->scoreAll($recipes, $context);

        if ($scored === []) {
            return null;
        }

        return $this->toProposal($context, $this->weightedPick(array_slice($scored, 0, $context['pickFrom'])));
    }

    /**
     * Recettes possibles pour une case, de la meilleure à la moins bonne.
     *
     * @param  Collection<int, Recipe>  $recipes
     * @return list<array{recipe: Recipe, score: float, reasons: list<string>, warnings: list<string>, wish_id: int|null}>
     */
    private function scoreAll(Collection $recipes, array $context): array
    {
        $scored = [];

        foreach ($recipes as $recipe) {
            if ((isset($context['used'][$recipe->id]) && empty($context['allowUsed'])) || in_array($recipe->id, $context['avoid'], true)) {
                continue;
            }

            if (empty($context['allowRecent']) && $this->recentlyEaten($recipe, $context['date'])) {
                continue;
            }

            if ($this->rules->exceedsTime($recipe, $context['date'], $context['slot'])) {
                continue;
            }

            $conflicts = $context['guests']->isEmpty() ? [] : $this->compatibility->conflicts($recipe, $context['guests']);

            if ($this->compatibility->worstLevel($conflicts) === GuestCompatibility::DANGER) {
                continue;
            }

            $scored[] = $this->score($recipe, $context, $conflicts);
        }

        usort($scored, fn ($a, $b) => [$b['score'], $a['recipe']->search_title] <=> [$a['score'], $b['recipe']->search_title]);

        return $scored;
    }

    /** @param  array{recipe: Recipe, score: float, reasons: list<string>, warnings: list<string>, wish_id: int|null}  $pick */
    private function toProposal(array $context, array $pick): array
    {
        $servings = (float) $context['servings'];

        // Une recette prévue pour nettement plus de convives se cuisine en entier : les portions
        // en trop deviennent les restes du lendemain (R15).
        if ($context['useLeftovers'] && (int) $pick['recipe']->servings >= $servings + 2) {
            $servings = (float) $pick['recipe']->servings;
        }

        return [
            'key' => $context['date']->toDateString().'|'.$context['slot']->id,
            'date' => $context['date']->toDateString(),
            'slot_id' => (int) $context['slot']->id,
            'slot' => $context['slot']->name,
            'day' => $context['date']->locale('fr')->isoFormat('ddd D MMM'),
            'type' => MealType::Recipe->value,
            'recipe_id' => (int) $pick['recipe']->id,
            'title' => $pick['recipe']->title,
            'servings' => $servings,
            'leftover_key' => null,
            'leftover_meal_id' => null,
            'score' => round($pick['score'], 1),
            'reasons' => $pick['reasons'],
            'warnings' => $pick['warnings'],
            'locked' => false,
            'wish_id' => $pick['wish_id'],
        ];
    }

    /* ================================================================ Une seule case (37.4) */

    /**
     * « Autre idée » (lot 37, 37.4) : quelques propositions pour **une** case, avec le même calcul
     * que « Remplir la semaine » — règles de la semaine, saison, stock, envies, avis, convives — sans
     * rien enregistrer. Le tirage se fait parmi les meilleures : deux appels ne donnent pas la même liste.
     *
     * @param  list<int>  $avoid  recettes déjà montrées (« Autre idée ») ou à remplacer
     * @param  int|null  $replacing  repas remplacé : il ne compte plus dans la semaine
     * @return list<array<string, mixed>> propositions (même forme que propose())
     */
    public function ideasFor(Carbon|string $date, MealSlot|int $slot, int $count = 3, array $avoid = [], ?int $replacing = null, ?\App\Enums\Course $course = null): array
    {
        $date = Carbon::parse($date)->startOfDay();
        $slot = $slot instanceof MealSlot ? $slot : MealSlot::query()->findOrFail($slot);
        $weekStart = $this->planner->weekStart($date);
        $course ??= \App\Enums\Course::Main;

        // Une case attend un plat (ou le dessert qu'on remplace) : pas une pâte brisée ni une tarte tatin au dîner.
        $recipes = $this->candidates(true)->loadCount('usedIn')
            ->filter(fn (Recipe $recipe) => $this->fitsCourse($recipe, $course))->values();
        $existing = $this->planner->mealsForWeek($weekStart)->reject(fn (PlannedMeal $m) => $replacing !== null && (int) $m->id === $replacing)->values();
        $this->lastPlanned = $this->lastPlannedDates();
        $this->feedback = $this->reactions->balances();
        $eaters = $this->eatersByCell($weekStart);
        $key = $date->toDateString().'|'.$slot->id;

        $pool = $this->suggester->pool();
        $this->suggester->reserve($pool);

        $context = [
            'date' => $date,
            'slot' => $slot,
            'servings' => $this->occasions->servingsAt($date, $slot),
            'used' => $existing->map(fn (PlannedMeal $m) => $m->eatenRecipe()?->id)->filter()->unique()->flip()->all(),
            'tagCounts' => $this->rules->countByTag($existing),
            'previousDay' => $existing->filter(fn (PlannedMeal $m) => $m->date->isSameDay($date->copy()->subDay()))->values(),
            'guests' => $eaters['cells'][$key] ?? $eaters['default'],
            'wishes' => Wish::query()->open()->whereNotNull('recipe_id')->with('recipe')->get()->keyBy('recipe_id'),
            'pool' => $pool,
            'useStock' => true,
            'avoid' => array_values(array_map('intval', $avoid)),
            'pickFrom' => max($count, (int) config('bouffe.planning.fill_pick_from', 5)),
            'useLeftovers' => false,
            'canteen' => $this->canteenFor($date, $slot),
        ];

        $scored = $this->scoreAll($recipes, $context);

        // Petit carnet : on accepte aussi ce qui a été mangé ces deux dernières semaines, puis ce qui
        // est déjà au menu de la semaine — en le disant.
        foreach ([['allowRecent' => true], ['allowRecent' => true, 'allowUsed' => true]] as $relax) {
            if (count($scored) >= $count) {
                break;
            }

            $known = array_map(fn (array $row) => $row['recipe']->id, $scored);
            $more = array_filter($this->scoreAll($recipes, [...$context, ...$relax]), fn (array $row) => ! in_array($row['recipe']->id, $known, true));
            array_push($scored, ...array_map(function (array $row) use ($context) {
                $row['warnings'][] = isset($context['used'][$row['recipe']->id]) ? 'déjà au menu cette semaine' : 'mangée il y a peu';

                return $row;
            }, array_values($more)));
        }

        // Tirage sans remise parmi les meilleures (le triple de ce qu'on montre).
        $top = array_slice($scored, 0, $count * 3);
        $picks = [];

        while ($top !== [] && count($picks) < $count) {
            $pick = $this->weightedPick($top);
            $picks[] = $pick;
            $top = array_values(array_filter($top, fn (array $row) => $row['recipe']->id !== $pick['recipe']->id));
        }

        usort($picks, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_map(fn (array $pick) => $this->toProposal($context, $pick), $picks);
    }

    /** @return array{recipe: Recipe, score: float, reasons: list<string>, warnings: list<string>, wish_id: int|null} */
    private function score(Recipe $recipe, array $context, array $conflicts): array
    {
        $reasons = [];
        $warnings = [];
        $score = 0.0;

        $wish = $context['wishes']->get($recipe->id);

        if ($wish) {
            $score += 30;
            $reasons[] = 'envie'.($wish->user?->name ? ' de '.$wish->user->name : '');
        }

        $days = $this->daysSinceLastPlanned($recipe, $context['date']);
        $score += $days === null ? self::LONG_TIME_CAP : min(self::LONG_TIME_CAP, max(0, $days));

        if ($days === null) {
            $reasons[] = 'jamais cuisinée';
        } elseif ($days >= self::LONG_TIME_CAP) {
            $reasons[] = 'ça fait longtemps';
        }

        if ($context['useStock']) {
            $evaluation = $this->suggester->evaluate($recipe, $context['servings'], $context['pool']);
            $stockScore = $evaluation['counted'] > 0 ? 100 * $evaluation['available'] / $evaluation['counted'] : 0;
            $score += 0.4 * $stockScore;

            if ($evaluation['missing'] === []) {
                $reasons[] = 'faisable avec le stock';
            } elseif ($evaluation['urgent'] > 0) {
                $reasons[] = 'utilise '.$evaluation['urgent'].' produit'.($evaluation['urgent'] > 1 ? 's' : '').' à consommer';
            }
        }

        if ($recipe->is_favorite) {
            $score += 10;
            $reasons[] = 'favorite';
        }

        // Ce que le foyer en a pensé la dernière fois (18.4).
        $balance = $this->feedback[$recipe->id]['balance'] ?? 0;

        if ($balance > 0) {
            $score += 8;
            $reasons[] = 'a plu';
        } elseif ($balance < 0) {
            $score -= 25;
            $warnings[] = 'n\'avait pas plu';
        }

        if ((float) $recipe->ratings_avg_rating >= 4) {
            $score += 5;
            $reasons[] = 'bien notée';
        }

        // Saisons (lot 19, R18) : évalué au mois du repas, pas au mois d'aujourd'hui.
        $season = app(\App\Services\Seasons\SeasonCalendar::class)->recipeStatus($recipe, $context['date']);

        if ($season['status'] === \App\Services\Seasons\SeasonCalendar::IN_SEASON) {
            $score += \App\Services\Seasons\SeasonCalendar::FILLER_BONUS;
            $reasons[] = 'de saison';
        } elseif ($season['status'] === \App\Services\Seasons\SeasonCalendar::OFF_SEASON) {
            $score += \App\Services\Seasons\SeasonCalendar::FILLER_PENALTY;
            $warnings[] = 'hors saison ('.mb_strtolower((string) $season['main']).')';
        }

        // Deux fois la même catégorie deux jours de suite : à éviter.
        if ($this->rules->all()['avoid_repeat_category'] && $this->sharesCategory($recipe, $context['previousDay'])) {
            $score -= 15;
        }

        if ($this->rules->wouldExceedQuota($recipe, $context['tagCounts'])) {
            $score -= 25;
            $warnings[] = 'dépasse une règle de la semaine';
        }

        // Ajout : une catégorie encore attendue dans la semaine est privilégiée.
        $missing = $this->rules->missingTagIds($context['tagCounts']);

        if ($missing !== [] && $recipe->tags->pluck('id')->intersect($missing)->isNotEmpty()) {
            $score += 15;
            $reasons[] = 'catégorie attendue';
        }

        foreach ($conflicts as $conflict) {
            $warnings[] = $conflict['guest'].' : '.mb_strtolower($conflict['subject']);
        }

        // Lot 39 (R41) : pas le même plat ni la même famille que la cantine ce midi.
        if (! empty($context['canteen'])) {
            [$score, $warnings] = $this->avoidCanteen($recipe, $context['canteen'], $score, $warnings);
        }

        return [
            'recipe' => $recipe,
            'score' => $score,
            'reasons' => array_values(array_unique($reasons)),
            'warnings' => array_values(array_unique($warnings)),
            'wish_id' => $wish?->id,
        ];
    }

    /**
     * Tirage pondéré parmi les meilleures propositions : la semaine proposée n'est jamais
     * exactement la même, tout en restant guidée par le score.
     *
     * @param  list<array>  $top
     */
    private function weightedPick(array $top): array
    {
        if (count($top) === 1) {
            return $top[0];
        }

        $weights = [];
        $total = 0;

        foreach ($top as $i => $candidate) {
            $weight = count($top) - $i;      // 5, 4, 3, 2, 1
            $weights[$i] = $weight;
            $total += $weight;
        }

        $draw = random_int(1, $total);

        foreach ($weights as $i => $weight) {
            $draw -= $weight;

            if ($draw <= 0) {
                return $top[$i];
            }
        }

        return $top[0];
    }

    /** Mots des catégories qui désignent autre chose qu'un plat principal. */
    private const NOT_MAIN = ['dessert', 'entree', 'aperitif', 'accompagnement', 'petit-dejeuner', 'petit dejeuner', 'gouter', 'sauce', 'boisson', 'fromage'];

    /**
     * La recette convient-elle à cette place du menu (37.4) ? D'après ses catégories : « Dessert »
     * pour un dessert ; pour un plat, rien qui la classe ailleurs (sauf si elle est aussi « Plat »),
     * et pas une sous-recette (pâte, sauce) qui n'est jamais servie seule.
     */
    private function fitsCourse(Recipe $recipe, \App\Enums\Course $course): bool
    {
        $tags = $recipe->tags->map(fn ($tag) => \App\Support\NameNormalizer::normalize($tag->name))->all();
        $wanted = \App\Support\NameNormalizer::normalize($course->label());

        if ($course !== \App\Enums\Course::Main) {
            return in_array($wanted, $tags, true);
        }

        if (in_array('plat', $tags, true)) {
            return true;
        }

        $notMain = array_map(fn (string $word) => \App\Support\NameNormalizer::normalize($word), self::NOT_MAIN);

        return array_intersect($tags, $notMain) === [] && (int) ($recipe->used_in_count ?? 0) === 0;
    }

    /* ================================================================ Restes */

    /**
     * Si la recette proposée laisse au moins deux portions, la case libre suivante (lendemain)
     * accueille les restes plutôt qu'une nouvelle recette.
     *
     * @return array<string, mixed>|null
     */
    private function leftoverFor(array $proposal, Collection $recipes, Collection $slots, Collection $existing, array $proposals, array $leftovers, Carbon $weekStart): ?array
    {
        $recipe = $recipes->firstWhere('id', $proposal['recipe_id'] ?? 0);

        if (! $recipe) {
            return null;
        }

        $date = Carbon::parse($proposal['date']);
        $next = $date->copy()->addDay();

        if ($next->gt($weekStart->copy()->addDays(6))) {
            return null;
        }

        $servings = (float) $proposal['servings'];
        $remaining = $servings - min($servings, $this->occasions->dinersAt($date, (int) $proposal['slot_id']));

        if ($remaining < 2) {
            return null;
        }

        foreach ($slots as $slot) {
            $key = $next->toDateString().'|'.$slot->id;

            if (isset($proposals[$key]) || isset($leftovers[$key]) || $this->isTaken($existing, $next, $slot)) {
                continue;
            }

            $wanted = $this->occasions->servingsAt($next, $slot);

            return [
                'key' => $key,
                'date' => $next->toDateString(),
                'slot_id' => (int) $slot->id,
                'slot' => $slot->name,
                'day' => $next->locale('fr')->isoFormat('ddd D MMM'),
                'type' => MealType::Leftover->value,
                'recipe_id' => null,
                'title' => 'Restes : '.$recipe->title,
                'servings' => max(1, min($remaining, $wanted)),
                'leftover_key' => $proposal['key'],
                'leftover_meal_id' => null,
                'score' => 0.0,
                'reasons' => ['restes de la veille'],
                'warnings' => [],
                'locked' => false,
                'wish_id' => null,
            ];
        }

        return null;
    }

    /* ================================================================ Données */

    /** @return Collection<int, Recipe> */
    private function candidates(bool $useStock): Collection
    {
        $query = Recipe::query()->active()->with('tags', 'steps')->withAvg('ratings', 'rating');

        if ($useStock) {
            $query->with('ingredients.ingredient.defaultUnit', 'ingredients.unit');
        }

        // Lot 40 (40.3) : pas une recette qui demande ce que la cuisine n'a pas.
        $equipment = app(\App\Services\Recipes\KitchenEquipment::class);

        return $query->get()->filter(fn (Recipe $recipe) => $equipment->possible($recipe))->values();
    }

    /** @return array<int, string> recette => dernière date planifiée */
    private function lastPlannedDates(): array
    {
        return PlannedMeal::query()
            ->where('type', MealType::Recipe->value)
            ->whereNotNull('recipe_id')
            ->selectRaw('recipe_id, MAX(date) as last_date')
            ->groupBy('recipe_id')
            ->pluck('last_date', 'recipe_id')
            ->map(fn ($date) => (string) $date)
            ->all();
    }

    /** Jours écoulés depuis la dernière fois que la recette a été planifiée ; null = jamais. */
    private function daysSinceLastPlanned(Recipe $recipe, Carbon $date): ?int
    {
        $last = $this->lastPlanned[$recipe->id] ?? null;

        return $last === null ? null : (int) Carbon::parse($last)->startOfDay()->diffInDays($date->copy()->startOfDay(), false);
    }

    private function recentlyEaten(Recipe $recipe, Carbon $date): bool
    {
        $days = $this->daysSinceLastPlanned($recipe, $date);

        return $days !== null && $days >= 0 && $days < self::RECENT_DAYS;
    }

    /**
     * À table, par case : membres du foyer présents avec une contrainte (18.1) et invités (lot 6).
     *
     * @return array{cells: array<string, Collection>, default: Collection}
     */
    private function eatersByCell(Carbon $weekStart): array
    {
        $household = app(HouseholdService::class);
        $occasions = $this->occasions->forRange($weekStart, $weekStart->copy()->addDays(6));
        $cells = [];

        foreach ($occasions as $cellKey => $occasion) {
            $eaters = $household->eaters($occasion);

            if ($eaters->isNotEmpty()) {
                $cells[$cellKey] = $eaters;
            }
        }

        return ['cells' => $cells, 'default' => $household->hasRestrictions() ? $household->eaters(null) : collect()];
    }

    /**
     * Ce que la cantine a servi le midi, pour un repas du soir (pas pour le déjeuner lui-même).
     *
     * @return array{labels: list<string>, families: list<string>}|null
     */
    private function canteenFor(Carbon $date, MealSlot $slot): ?array
    {
        $canteen = app(\App\Services\People\CanteenCalendar::class);

        if (! $canteen->anyone() || $canteen->isLunch($slot) || ! $canteen->lunchSlotId()) {
            return null;
        }

        $lunch = MealSlot::query()->find($canteen->lunchSlotId());

        // Seulement les créneaux qui viennent après le midi (le petit-déjeuner n'est pas concerné).
        if ($lunch && $slot->sort_order <= $lunch->sort_order) {
            return null;
        }

        $served = $canteen->servedOn($date);

        return $served['labels'] === [] ? null : $served;
    }

    /**
     * @param  array{labels: list<string>, families: list<string>}  $canteen
     * @param  list<string>  $warnings
     * @return array{0: float, 1: list<string>}
     */
    private function avoidCanteen(Recipe $recipe, array $canteen, float $score, array $warnings): array
    {
        $served = ' '.\App\Support\NameNormalizer::normalize(implode(' ', $canteen['labels'])).' ';
        $words = array_filter(
            preg_split('/[^a-z0-9]+/', \App\Support\NameNormalizer::normalize($recipe->title)) ?: [],
            fn (string $word) => mb_strlen($word) >= 5 && ! in_array($word, ['legumes', 'maison', 'facon', 'sauce', 'creme', 'salade', 'grand', 'petit'], true),
        );

        foreach ($words as $word) {
            if (str_contains($served, ' '.$word)) {
                return [$score - 30, [...$warnings, 'déjà servi à la cantine ce midi']];
            }
        }

        $this->recipeFamilies[$recipe->id] ??= app(WeekBalance::class)->familiesOf($recipe);
        $same = array_intersect(['poisson', 'viande'], $this->recipeFamilies[$recipe->id], $canteen['families']);

        if ($same !== []) {
            $label = WeekBalance::FAMILIES[reset($same)][0];

            return [$score - 12, [...$warnings, mb_strtolower($label).' à la cantine ce midi']];
        }

        return [$score, $warnings];
    }

    /** @param  Collection<int, PlannedMeal>  $meals */
    private function isTaken(Collection $meals, Carbon $day, MealSlot $slot): bool
    {
        return $meals->contains(fn (PlannedMeal $m) => $m->date->toDateString() === $day->toDateString() && (int) $m->meal_slot_id === (int) $slot->id);
    }

    /** @param  Collection<int, PlannedMeal>  $previousDay */
    private function sharesCategory(Recipe $recipe, Collection $previousDay): bool
    {
        $tags = $recipe->tags->pluck('id');

        foreach ($previousDay as $meal) {
            if ($meal->eatenRecipe()?->tags->pluck('id')->intersect($tags)->isNotEmpty()) {
                return true;
            }
        }

        return false;
    }

    /** Tient à jour l'état du remplissage après une proposition (recettes utilisées, quotas, stock). */
    private function remember(array $proposal, array &$used, array &$tagCounts, Collection &$byDay, array &$pool, bool $useStock, Collection $recipes): void
    {
        if (empty($proposal['recipe_id'])) {
            return;
        }

        $recipe = $recipes->firstWhere('id', $proposal['recipe_id']);

        if (! $recipe) {
            return;
        }

        $used[$recipe->id] = true;

        foreach ($recipe->tags as $tag) {
            $tagCounts[$tag->id] = ($tagCounts[$tag->id] ?? 0) + 1;
        }

        // Le jour est enrichi d'un repas « virtuel » pour la pénalité « même catégorie deux jours de suite ».
        $virtual = new PlannedMeal(['date' => $proposal['date'], 'meal_slot_id' => $proposal['slot_id'], 'type' => MealType::Recipe, 'recipe_id' => $recipe->id, 'servings' => $proposal['servings']]);
        $virtual->setRelation('recipe', $recipe);
        $byDay->put($proposal['date'], ($byDay->get($proposal['date']) ?? collect())->push($virtual));

        if ($useStock) {
            $this->suggester->consume($pool, $recipe, (float) $proposal['servings']);
        }
    }
}
