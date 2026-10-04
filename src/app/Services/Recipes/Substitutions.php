<?php

namespace App\Services\Recipes;

use App\Enums\RestrictionType;
use App\Models\Ingredient;
use App\Models\IngredientSubstitution;
use App\Models\Recipe;
use App\Support\AllergenGroups;
use App\Support\CurrentHousehold;
use App\Support\Settings;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Remplacements d'ingrédients (lot 40, 40.1, R43).
 *
 * « Pas de crème ? Crème de soja, même quantité. » Un remplacement est proposé, jamais appliqué à
 * la recette. Il vient de la liste commune (Q58), du foyer, ou d'une recette (une variante, un
 * remplacement accepté depuis l'assistant). Jamais un remplacement qui contient l'allergène d'une
 * personne à table (même groupe d'allergène : une allergie au lait écarte la margarine au beurre).
 */
class Substitutions
{
    /** Réglage : remplacements communs que le foyer ne veut pas voir. */
    public const HIDDEN = 'substitutions.hidden';

    /** @var array<string, Collection<int, IngredientSubstitution>> */
    private array $cache = [];

    /**
     * Remplacements d'un ingrédient : ceux de la recette d'abord, puis ceux du foyer, puis la liste
     * commune ; un même remplaçant n'apparaît qu'une fois.
     *
     * @param  iterable<object>  $eaters  personnes à table (restrictions chargées) : R43
     * @return Collection<int, IngredientSubstitution>
     */
    public function for(int $ingredientId, ?Recipe $recipe = null, iterable $eaters = []): Collection
    {
        $all = $this->visible()->where('ingredient_id', $ingredientId)
            ->filter(fn (IngredientSubstitution $s) => $s->recipe_id === null || ($recipe && $s->recipe_id === $recipe->id))
            ->sortBy(fn (IngredientSubstitution $s) => [$s->recipe_id === null ? 1 : 0, $s->household_id === null ? 1 : 0, $s->id]);

        $blocked = $this->blocked($eaters);

        return $all->unique('substitute_id')
            ->reject(fn (IngredientSubstitution $s) => $this->isBlocked($s->substitute, $blocked))
            ->values();
    }

    /**
     * Remplacements par ingrédient, pour une liste d'ingrédients (une recette, une liste de courses).
     *
     * @param  list<int>  $ingredientIds
     * @return Collection<int, Collection<int, IngredientSubstitution>>
     */
    public function forMany(array $ingredientIds, ?Recipe $recipe = null, iterable $eaters = []): Collection
    {
        $eaters = collect($eaters);

        return collect($ingredientIds)->unique()->mapWithKeys(fn (int $id) => [$id => $this->for($id, $recipe, $eaters)])
            ->filter(fn (Collection $list) => $list->isNotEmpty());
    }

    /** Quantité du remplaçant (R43 : rapport, arrondi comme une quantité de recette). */
    public function quantity(?float $quantity, IngredientSubstitution $substitution): ?float
    {
        if ($quantity === null) {
            return null;
        }

        $value = $quantity * (float) $substitution->ratio;

        return match (true) {
            $value >= 100 => round($value / 5) * 5,
            $value >= 10 => round($value),
            default => round($value * 4) / 4,
        };
    }

    /** @return Collection<int, IngredientSubstitution> tout ce que le foyer voit (Paramètres) */
    public function visible(): Collection
    {
        $key = (string) CurrentHousehold::id();

        if (! isset($this->cache[$key])) {
            $hidden = array_map('intval', (array) Settings::get(self::HIDDEN, []));
            $this->cache[$key] = IngredientSubstitution::query()->with('ingredient', 'substitute', 'recipe:id,title')->get()
                ->reject(fn (IngredientSubstitution $s) => $s->isCommon() && in_array($s->id, $hidden, true))
                ->filter(fn (IngredientSubstitution $s) => $s->ingredient && $s->substitute)
                ->values();
        }

        return $this->cache[$key];
    }

    /** @return Collection<int, IngredientSubstitution> remplacements communs masqués par le foyer */
    public function hiddenCommons(): Collection
    {
        $hidden = array_map('intval', (array) Settings::get(self::HIDDEN, []));

        return $hidden === [] ? collect() : IngredientSubstitution::query()->whereNull('household_id')->whereIn('id', $hidden)->with('ingredient', 'substitute')->get();
    }

    /* ================================================================ Écriture */

    public function add(int $ingredientId, int $substituteId, float|string|null $ratio = 1, ?int $recipeId = null, ?string $note = null, string $source = 'manual'): IngredientSubstitution
    {
        $ratio = (float) str_replace(',', '.', (string) ($ratio ?? 1));

        if ($ingredientId === $substituteId) {
            throw new InvalidArgumentException('Un ingrédient ne se remplace pas par lui-même.');
        }

        if (! Ingredient::query()->whereKey($ingredientId)->exists() || ! Ingredient::query()->whereKey($substituteId)->exists()) {
            throw new InvalidArgumentException('Choisissez les deux ingrédients.');
        }

        if ($ratio < 0.05 || $ratio > 20) {
            throw new InvalidArgumentException('Le rapport va de 0,05 à 20 (1 = même quantité).');
        }

        if ($recipeId !== null && ! Recipe::query()->whereKey($recipeId)->exists()) {
            throw new InvalidArgumentException('Recette introuvable.');
        }

        $this->forget();

        $existing = IngredientSubstitution::query()->where('household_id', CurrentHousehold::id())
            ->where('ingredient_id', $ingredientId)->where('substitute_id', $substituteId)
            ->where('recipe_id', $recipeId)->first();

        $values = ['ratio' => round($ratio, 3), 'note' => mb_substr(trim((string) $note), 0, 150) ?: null, 'source' => $source];

        if ($existing) {
            $existing->update($values);

            return $existing;
        }

        return IngredientSubstitution::create([
            'household_id' => CurrentHousehold::id(),
            'ingredient_id' => $ingredientId,
            'substitute_id' => $substituteId,
            'recipe_id' => $recipeId,
            ...$values,
        ]);
    }

    /** Retire un remplacement du foyer ; un remplacement commun est seulement masqué pour ce foyer. */
    public function remove(IngredientSubstitution $substitution): void
    {
        $this->forget();

        if ($substitution->isCommon()) {
            $hidden = array_map('intval', (array) Settings::get(self::HIDDEN, []));
            Settings::set(self::HIDDEN, array_values(array_unique([...$hidden, $substitution->id])));

            return;
        }

        $substitution->delete();
    }

    public function restoreCommon(int $id): void
    {
        $this->forget();
        Settings::set(self::HIDDEN, array_values(array_diff(array_map('intval', (array) Settings::get(self::HIDDEN, [])), [$id])));
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /* ================================================================ R43 : allergies */

    /**
     * Ingrédients et groupes d'allergènes interdits par les personnes à table.
     *
     * @return array{ingredients: list<int>, groups: list<string>}
     */
    public function blocked(iterable $eaters): array
    {
        $ingredients = [];
        $groups = [];

        foreach ($eaters as $eater) {
            foreach ($eater->restrictions ?? [] as $restriction) {
                if (! $restriction->ingredient_id || ! in_array($restriction->type, [RestrictionType::Allergy, RestrictionType::Dislike], true)) {
                    continue;
                }

                $ingredients[] = (int) $restriction->ingredient_id;

                // Une allergie s'étend à tout le groupe (allergie au lait : ni beurre, ni crème).
                if ($restriction->type === RestrictionType::Allergy) {
                    array_push($groups, ...AllergenGroups::ofName($restriction->ingredient?->name));
                }
            }
        }

        return ['ingredients' => array_values(array_unique($ingredients)), 'groups' => array_values(array_unique($groups))];
    }

    /** @param  array{ingredients: list<int>, groups: list<string>}  $blocked */
    private function isBlocked(?Ingredient $substitute, array $blocked): bool
    {
        if (! $substitute) {
            return true;
        }

        return in_array($substitute->id, $blocked['ingredients'], true)
            || ($blocked['groups'] !== [] && array_intersect(AllergenGroups::ofName($substitute->name), $blocked['groups']) !== []);
    }
}
