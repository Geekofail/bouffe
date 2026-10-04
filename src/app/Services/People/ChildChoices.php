<?php

namespace App\Services\People;

use App\Enums\MealType;
use App\Models\ChildChoice;
use App\Models\HouseholdPerson;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\Wish;
use App\Services\Planning\GuestCompatibility;
use App\Services\Planning\HouseholdService;
use App\Services\Planning\WeekFiller;
use App\Services\Planning\WeekPlanner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Le choix des enfants (lot 39, 39.3, R42).
 *
 * Un adulte prépare trois recettes pour un repas (« Choisis ton dîner de mercredi ») ; elles sont
 * vérifiées pour les goûts et allergies de **tous** ceux qui mangent ce jour-là. L'enfant choisit
 * sur l'écran de cuisine : son choix devient une **envie** (14.5), et s'inscrit au planning
 * seulement si l'adulte l'a permis pour ce repas.
 */
class ChildChoices
{
    public const MIN = 2;

    public const MAX = 3;

    public function __construct(
        private readonly GuestCompatibility $compatibility,
        private readonly HouseholdService $household,
    ) {}

    /** @return Collection<int, ChildChoice> choix encore à faire, du plus proche au plus lointain */
    public function open(): Collection
    {
        return ChildChoice::query()->open()->with('slot', 'person')->orderBy('date')->orderBy('meal_slot_id')->get();
    }

    /** @return Collection<int, ChildChoice> derniers choix faits (pour l'adulte) */
    public function recent(int $limit = 5): Collection
    {
        return ChildChoice::query()->whereNotNull('chosen_at')->with('slot', 'person', 'chosenRecipe')->latest('chosen_at')->limit($limit)->get();
    }

    /**
     * Ce qui empêche une recette d'être proposée ce jour-là (allergie, régime, « n'aime pas » de quelqu'un à table).
     *
     * @return list<string>
     */
    public function problems(Recipe $recipe, Carbon|string $date, MealSlot|int $slot): array
    {
        $eaters = $this->household->eatersAt($date, $slot);

        return array_column($this->compatibility->conflicts($recipe, $eaters), 'message');
    }

    /**
     * Trois idées compatibles avec tout le monde (le calcul de « Autre idée », 37.4).
     *
     * @param  list<int>  $avoid
     * @return list<array{recipe_id: int, title: string}>
     */
    public function ideas(Carbon|string $date, MealSlot|int $slot, array $avoid = []): array
    {
        $ideas = [];

        foreach (app(WeekFiller::class)->ideasFor($date, $slot, self::MAX * 2, $avoid) as $idea) {
            $recipe = $idea['recipe_id'] ? Recipe::query()->find($idea['recipe_id']) : null;

            if ($recipe && $this->problems($recipe, $date, $slot) === []) {
                $ideas[] = ['recipe_id' => $recipe->id, 'title' => $recipe->title];
            }

            if (count($ideas) >= self::MAX) {
                break;
            }
        }

        return $ideas;
    }

    /**
     * Prépare (ou remplace) le choix d'un repas.
     *
     * @param  list<int|string>  $recipeIds
     */
    public function propose(Carbon|string $date, MealSlot|int $slot, array $recipeIds, ?int $personId = null, bool $allowPlan = false): ChildChoice
    {
        $date = Carbon::parse($date)->startOfDay();
        $slot = $slot instanceof MealSlot ? $slot : MealSlot::query()->findOrFail($slot);

        if ($date->lt(Carbon::today())) {
            throw new InvalidArgumentException('Ce repas est déjà passé.');
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $recipeIds))));
        $recipes = Recipe::query()->whereIn('id', $ids)->whereNull('archived_at')->get()->keyBy('id');
        $ids = array_values(array_filter($ids, fn (int $id) => $recipes->has($id)));

        if (count($ids) < self::MIN || count($ids) > self::MAX) {
            throw new InvalidArgumentException('Proposez deux ou trois recettes différentes.');
        }

        // R42 : rien qui pose problème à quelqu'un qui mange ce jour-là.
        foreach ($ids as $id) {
            if (($problems = $this->problems($recipes[$id], $date, $slot)) !== []) {
                throw new InvalidArgumentException("« {$recipes[$id]->title} » ne convient pas à tout le monde : ".mb_strtolower($problems[0]).'.');
            }
        }

        $person = $personId ? HouseholdPerson::query()->findOrFail($personId) : null;

        return DB::transaction(function () use ($date, $slot, $ids, $person, $allowPlan) {
            ChildChoice::query()->whereDate('date', $date->toDateString())->where('meal_slot_id', $slot->id)->whereNull('chosen_at')->delete();

            return ChildChoice::create([
                'person_id' => $person?->id,
                'date' => $date->toDateString(),
                'meal_slot_id' => $slot->id,
                'recipe_ids' => $ids,
                'allow_plan' => $allowPlan,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * Le choix de l'enfant : une envie, et le repas prévu si un adulte l'a permis.
     */
    public function choose(ChildChoice $choice, int $recipeId): ChildChoice
    {
        if ($choice->chosen_at) {
            throw new InvalidArgumentException('Ce choix a déjà été fait.');
        }

        if (! in_array($recipeId, array_map('intval', $choice->recipe_ids), true)) {
            throw new InvalidArgumentException('Cette recette ne faisait pas partie des propositions.');
        }

        $recipe = Recipe::query()->findOrFail($recipeId);
        $who = $choice->person?->name;

        return DB::transaction(function () use ($choice, $recipe, $who) {
            $wish = Wish::create([
                'user_id' => auth()->id(),
                'recipe_id' => $recipe->id,
                'text' => mb_substr('Choix '.($who ? (preg_match('/^[aeiouyhàâéèêîôù]/iu', $who) ? 'd\''.$who : 'de '.$who) : 'des enfants').' pour le '.$choice->dayLabel(), 0, 200),
            ]);

            $meal = $choice->allow_plan ? $this->plan($choice, $recipe) : null;

            if ($meal) {
                $wish->update(['planned_meal_id' => $meal->id, 'planned_at' => now()]);
            }

            $choice->update([
                'chosen_recipe_id' => $recipe->id,
                'chosen_at' => now(),
                'wish_id' => $wish->id,
                'planned_meal_id' => $meal?->id,
            ]);

            app(\App\Services\Activity\ActivityLog::class)->record('planning',
                'a noté le choix '.($who ? 'de '.$who : 'des enfants').' : « '.$recipe->title.' » pour le '.$choice->dayLabel(), $meal ?? $wish);

            return $choice->fresh(['chosenRecipe', 'slot', 'person']);
        });
    }

    public function cancel(ChildChoice $choice): void
    {
        if (! $choice->chosen_at) {
            $choice->delete();
        }
    }

    /** R42 : remplace le plat principal prévu, ou l'ajoute si la case est vide. */
    private function plan(ChildChoice $choice, Recipe $recipe): PlannedMeal
    {
        $planner = app(WeekPlanner::class);
        $main = PlannedMeal::query()->whereDate('date', $choice->date->toDateString())->where('meal_slot_id', $choice->meal_slot_id)
            ->where('type', MealType::Recipe->value)->whereNull('cooked_at')->whereNull('prepared_at')
            ->where(fn ($q) => $q->whereNull('course')->orWhere('course', \App\Enums\Course::Main->value))
            ->orderBy('position')->first();

        return $main ? $planner->replaceRecipe($main, $recipe) : $planner->addRecipe($choice->date, $choice->meal_slot_id, $recipe);
    }
}
