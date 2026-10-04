<?php

namespace App\Services\Linked;

use App\Enums\MealType;
use App\Models\PlannedMeal;
use App\Models\Scopes\HouseholdScope;
use Illuminate\Support\Facades\DB;

/**
 * Suppression d'une recette planifiée telle quelle par un foyer relié (26.2) : leurs repas ne
 * disparaissent pas, ils deviennent un repas en texte libre au nom de la recette.
 */
class RecipeRemoval
{
    /** @param  list<int>  $recipeIds */
    public function detachFromOtherHouseholds(array $recipeIds, ?int $ownerHouseholdId = null): int
    {
        if ($recipeIds === []) {
            return 0;
        }

        $titles = DB::table('recipes')->whereIn('id', $recipeIds)->pluck('title', 'id');
        $meals = PlannedMeal::query()->withoutGlobalScope(HouseholdScope::class)
            ->whereIn('recipe_id', $recipeIds)
            ->when($ownerHouseholdId, fn ($q) => $q->where('household_id', '!=', $ownerHouseholdId))
            ->get(['id', 'recipe_id', 'household_id']);

        foreach ($meals as $meal) {
            DB::table('planned_meals')->where('id', $meal->id)->update([
                'type' => MealType::Free->value,
                'recipe_id' => null,
                'free_text' => mb_substr((string) $titles->get($meal->recipe_id).' (recette retirée par ses auteurs)', 0, 200),
            ]);
        }

        // Lot 42 (42.1) : plats prévus dans un séjour co-organisé, rangé chez un autre foyer.
        $stayMeals = DB::table('stay_meals')->join('stays', 'stays.id', '=', 'stay_meals.stay_id')
            ->whereIn('stay_meals.recipe_id', $recipeIds)
            ->when($ownerHouseholdId, fn ($q) => $q->where('stays.household_id', '!=', $ownerHouseholdId))
            ->get(['stay_meals.id', 'stay_meals.recipe_id']);

        foreach ($stayMeals as $meal) {
            DB::table('stay_meals')->where('id', $meal->id)->update([
                'recipe_id' => null,
                'free_text' => mb_substr((string) $titles->get($meal->recipe_id), 0, 150),
            ]);
        }

        return $meals->count() + $stayMeals->count();
    }
}
