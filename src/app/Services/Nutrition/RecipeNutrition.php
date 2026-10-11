<?php

namespace App\Services\Nutrition;

use App\Models\Household;
use App\Models\Recipe;
use App\Support\CurrentHousehold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le calcul nutritionnel d'une recette, mis de côté dans la recette (lot 43).
 *
 *   recipes.nutri_score   la lettre estimée (A–E), ou null quand les données sont insuffisantes ;
 *   recipes.nutrition     le détail : valeurs par portion et pour 100 g, couverture, repères ;
 *   recipes.nutrition_at  quand ; null = à recalculer.
 *
 * Une recette est marquée « à recalculer » quand ses lignes changent ou quand un ingrédient change
 * d'aliment Ciqual. Le recalcul se fait à l'enregistrement de la recette, à l'affichage de sa fiche,
 * et par les tâches régulières (TaskRunner) pour tout ce qui reste.
 */
class RecipeNutrition
{
    public function __construct(
        private readonly NutritionCalculator $calculator,
        private readonly NutriScore $nutriScore,
        private readonly MealBenchmarks $benchmarks,
    ) {}

    /**
     * Calcul complet d'une recette, pour ses portions de base.
     *
     * @return array<string, mixed>
     */
    public function compute(Recipe $recipe): array
    {
        $result = $this->calculator->recipe($recipe);
        $score = null;

        if ($result['known']) {
            $score = $this->nutriScore->compute($result['per100'], $result['fruit_vegetable']);
        }

        return [
            'known' => $result['known'],
            'coverage' => $result['coverage'],
            'per_portion' => $result['values'],
            'per_100g' => collect($result['per100'])->map(fn ($value) => round((float) $value, 1))->all(),
            'fruit_vegetable' => $result['fruit_vegetable'],
            'score' => $score,
            'flags' => $result['known'] ? $this->benchmarks->flags($result['values']) : [],
            'uncounted' => $result['uncounted'],
            'servings' => $result['servings'],
        ];
    }

    /** Recalcule et enregistre (sans toucher à updated_at). */
    public function refresh(Recipe $recipe): array
    {
        $data = $this->compute($recipe);

        DB::table('recipes')->where('id', $recipe->id)->update([
            'nutri_score' => $data['score']['letter'] ?? null,
            'nutrition' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'nutrition_at' => now(),
        ]);

        $recipe->forceFill([
            'nutri_score' => $data['score']['letter'] ?? null,
            'nutrition' => $data,
            'nutrition_at' => now(),
        ])->syncOriginal();

        return $data;
    }

    /** Lettre et détail à jour d'une recette : le calcul mis de côté, refait s'il est périmé. */
    public function current(Recipe $recipe): array
    {
        if ($recipe->nutrition_at === null || ! is_array($recipe->nutrition)) {
            return $this->refresh($recipe);
        }

        return $recipe->nutrition;
    }

    /** Les colonnes du lot 43 existent-elles ? Retenu seulement une fois vrai. */
    private static bool $ready = false;

    /**
     * Pendant `bouffe:deploy`, les migrations des lots précédents créent ou modifient des
     * ingrédients et des recettes avant que la migration du lot 43 ne passe : les écritures
     * automatiques de ce lot (aliment Ciqual, calcul périmé) attendent alors leurs colonnes.
     */
    public static function ready(): bool
    {
        return self::$ready = self::$ready
            || (Schema::hasColumn('recipes', 'nutrition_at') && Schema::hasColumn('ingredients', 'ciqual_source'));
    }

    /** Recettes dont le calcul est à refaire, et celles qui les utilisent comme sous-recettes. */
    public static function markStale(array $recipeIds): void
    {
        if (! self::ready()) {
            return;   // migrations d'un lot plus ancien en cours : les colonnes du lot 43 n'existent pas encore
        }

        $ids = array_values(array_unique(array_map('intval', $recipeIds)));
        $level = $ids;

        for ($depth = 0; $depth < 3 && $level !== []; $depth++) {
            $level = array_values(array_diff(
                DB::table('recipe_components')->whereIn('component_recipe_id', $level)->distinct()->pluck('recipe_id')->map(fn ($id) => (int) $id)->all(),
                $ids,
            ));
            $ids = [...$ids, ...$level];
        }

        if ($ids !== []) {
            DB::table('recipes')->whereIn('id', $ids)->update(['nutrition_at' => null]);
        }
    }

    /** Toutes les recettes qui utilisent ces ingrédients (aliment Ciqual changé). */
    public static function markStaleForIngredients(array $ingredientIds): void
    {
        if ($ingredientIds === []) {
            return;
        }

        self::markStale(DB::table('recipe_ingredients')->whereIn('ingredient_id', $ingredientIds)->distinct()->pluck('recipe_id')->all());
    }

    /**
     * Recalcule les recettes périmées, foyer par foyer (R29), au plus $limit à la fois.
     *
     * @return int nombre de recettes recalculées
     */
    public function refreshStale(int $limit = 300, bool $all = false): int
    {
        $done = 0;

        foreach (Household::query()->pluck('id') as $householdId) {
            $done += CurrentHousehold::run((int) $householdId, function () use ($limit, $all, $done) {
                $count = 0;

                Recipe::query()
                    ->when(! $all, fn ($query) => $query->whereNull('nutrition_at'))
                    ->orderBy('id')
                    ->limit(max(0, $limit - $done))
                    ->get()
                    ->each(function (Recipe $recipe) use (&$count) {
                        $this->refresh($recipe);
                        $count++;
                    });

                return $count;
            });

            if ($done >= $limit) {
                break;
            }
        }

        return $done;
    }
}
