<?php

namespace App\Services\Planning;

use App\Models\MealReaction;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Réactions aux repas (18.4) : 👍 / 👎 en un clic, une fois le repas mangé.
 *
 * C'est volontairement plus léger qu'une note sur 5 (lot 2) : on réagit sur le vif, et le
 * remplissage automatique s'en sert pour reproposer ce qui a plu et espacer ce qui a déplu.
 */
class MealFeedback
{
    /** Repose sur toute la mémoire des réactions, pas seulement la semaine en cours. */
    public function react(PlannedMeal $meal, User $user, int $value, ?string $comment = null): MealReaction
    {
        if (! $meal->cooked_at) {
            throw new InvalidArgumentException('Marquez d\'abord le repas comme mangé.');
        }

        if (! $meal->eatenRecipe()) {
            throw new InvalidArgumentException('Seul un repas avec une recette peut recevoir une réaction.');
        }

        return MealReaction::updateOrCreate(
            ['planned_meal_id' => $meal->id, 'user_id' => $user->id],
            ['value' => $value >= 0 ? MealReaction::LIKE : MealReaction::DISLIKE, 'comment' => $comment],
        );
    }

    /** Un deuxième clic sur la même réaction la retire. */
    public function toggle(PlannedMeal $meal, User $user, int $value): ?MealReaction
    {
        $existing = MealReaction::query()->where('planned_meal_id', $meal->id)->where('user_id', $user->id)->first();
        $normalized = $value >= 0 ? MealReaction::LIKE : MealReaction::DISLIKE;

        if ($existing && $existing->value === $normalized) {
            $existing->delete();

            return null;
        }

        return $this->react($meal, $user, $normalized);
    }

    /**
     * Bilan d'une recette : nombre de 👍 et de 👎 de tout le foyer.
     *
     * @return array{likes: int, dislikes: int, balance: int}
     */
    public function forRecipe(Recipe|int $recipe): array
    {
        $counts = $this->balances([$recipe instanceof Recipe ? $recipe->id : $recipe]);

        return $counts[$recipe instanceof Recipe ? $recipe->id : $recipe] ?? ['likes' => 0, 'dislikes' => 0, 'balance' => 0];
    }

    /**
     * Bilan de plusieurs recettes en une requête (remplissage automatique, listes).
     *
     * @param  list<int>|null  $recipeIds  null = toutes
     * @return array<int, array{likes: int, dislikes: int, balance: int}>
     */
    public function balances(?array $recipeIds = null): array
    {
        $rows = DB::table('meal_reactions as r')
            ->join('planned_meals as pm', 'pm.id', '=', 'r.planned_meal_id')
            // Les restes comptent pour la recette d'origine.
            ->leftJoin('planned_meals as src', 'src.id', '=', 'pm.leftover_of_id')
            ->selectRaw('COALESCE(pm.recipe_id, src.recipe_id) as recipe_id')
            ->selectRaw('SUM(CASE WHEN r.value > 0 THEN 1 ELSE 0 END) as likes')
            ->selectRaw('SUM(CASE WHEN r.value < 0 THEN 1 ELSE 0 END) as dislikes')
            ->where('pm.household_id', \App\Support\CurrentHousehold::id() ?? 0)   // R29
            ->whereNotNull(DB::raw('COALESCE(pm.recipe_id, src.recipe_id)'))
            ->when($recipeIds !== null, fn ($q) => $q->whereIn(DB::raw('COALESCE(pm.recipe_id, src.recipe_id)'), $recipeIds ?: [0]))
            ->groupBy(DB::raw('COALESCE(pm.recipe_id, src.recipe_id)'))
            ->get();

        return $rows->mapWithKeys(fn ($row) => [(int) $row->recipe_id => [
            'likes' => (int) $row->likes,
            'dislikes' => (int) $row->dislikes,
            'balance' => (int) $row->likes - (int) $row->dislikes,
        ]])->all();
    }

    /**
     * Repas mangés récemment sans réaction de cette personne : « alors, c'était bien ? »
     *
     * @return Collection<int, PlannedMeal>
     */
    public function awaiting(User $user, int $days = 3): Collection
    {
        return PlannedMeal::query()
            ->whereNotNull('cooked_at')
            ->where('cooked_at', '>=', now()->subDays($days))
            ->whereDoesntHave('reactions', fn ($q) => $q->where('user_id', $user->id))
            ->with('recipe', 'slot', 'leftoverOf.recipe')
            ->orderByDesc('cooked_at')
            ->get()
            ->filter(fn (PlannedMeal $meal) => $meal->eatenRecipe() !== null)
            ->values();
    }
}
