<?php

namespace App\Services\Linked;

use App\Models\Household;
use App\Models\HouseholdShare;
use App\Models\Recipe;
use App\Models\Scopes\HouseholdScope;
use App\Support\CurrentHousehold;
use Illuminate\Database\Eloquent\Builder;

/**
 * Recettes des proches (26.1) : ce qu'un foyer peut lire chez les autres.
 *
 * Une recette d'un autre foyer est visible si elle n'est pas archivée et :
 *  - elle est ouverte à **toute l'installation** ;
 *  - ou les deux foyers sont reliés **et** elle est ouverte aux foyers reliés, ou ce foyer a ouvert
 *    **tout son carnet** au foyer qui regarde.
 *
 * La lecture ne donne aucun droit de modification : on la planifie, on la copie (R31), on la note.
 */
class SharedRecipes
{
    public function __construct(private readonly HouseholdLinks $links) {}

    /** @return Builder<Recipe> recettes des autres foyers visibles de $viewer */
    public function visibleQuery(?int $viewer = null): Builder
    {
        $viewer ??= CurrentHousehold::id() ?? 0;
        $linked = $this->links->linkedIds($viewer);
        $openCarnets = $linked === [] ? [] : HouseholdShare::query()
            ->where('target_household_id', $viewer)->whereIn('household_id', $linked)->where('recipes_all', true)
            ->pluck('household_id')->all();
        $active = Household::query()->whereNull('disabled_at')->whereNull('deletion_requested_at')->select('id');

        return Recipe::query()->withoutGlobalScope(HouseholdScope::class)
            ->where('recipes.household_id', '!=', $viewer)
            ->whereIn('recipes.household_id', $active)
            ->whereNull('recipes.archived_at')
            ->where(function (Builder $q) use ($linked, $openCarnets) {
                $q->where('recipes.visibility', 'instance');

                if ($linked !== []) {
                    $q->orWhere(fn (Builder $w) => $w->whereIn('recipes.household_id', $linked)->where('recipes.visibility', 'linked'));
                }

                if ($openCarnets !== []) {
                    $q->orWhereIn('recipes.household_id', $openCarnets);
                }
            });
    }

    public function findVisible(int $recipeId, ?int $viewer = null): ?Recipe
    {
        return $this->visibleQuery($viewer)->whereKey($recipeId)->first();
    }

    /**
     * Lisible par le foyer actif : visible aujourd'hui, sous-recette d'une recette visible, ou déjà
     * planifiée par lui telle quelle (un repas prévu reste consultable si l'autre foyer referme son carnet).
     */
    public function findReadable(int $recipeId): ?Recipe
    {
        if ($recipe = $this->findVisible($recipeId)) {
            return $recipe;
        }

        $planned = \App\Models\PlannedMeal::query()->where('recipe_id', $recipeId)->exists()
            // Sous-recette (pâte, sauce) d'une recette visible : elle se lit avec elle.
            || \App\Models\RecipeComponent::query()->where('component_recipe_id', $recipeId)
                ->whereIn('recipe_id', $this->visibleQuery()->select('recipes.id'))->exists();

        return $planned ? Recipe::query()->withoutGlobalScope(HouseholdScope::class)->find($recipeId) : null;
    }

    public function canView(Recipe $recipe, ?int $viewer = null): bool
    {
        $viewer ??= CurrentHousehold::id();

        return (int) $recipe->household_id === (int) $viewer || $this->visibleQuery($viewer)->whereKey($recipe->id)->exists();
    }

    public function count(?int $viewer = null): int
    {
        return $this->visibleQuery($viewer)->count();
    }
}
