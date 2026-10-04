<?php

namespace App\Services\Recipes;

use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\RecipeIngredient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sous-recettes (13.8) : une recette en utilise une autre — pâte brisée, béchamel, vinaigrette.
 *
 * La sous-recette n'est pas recopiée : la recette dit seulement « 1 × Pâte brisée ». Tout ce qui
 * a besoin des ingrédients réels (liste de courses, coût, nutrition, retrait du stock, allergies des
 * invités) passe par `lines()`, qui déplie les sous-recettes à la bonne échelle.
 *
 * Règles d'intégrité : une recette ne peut pas s'utiliser elle-même, même indirectement ; une
 * recette utilisée comme sous-recette ne peut pas être supprimée (on l'archive).
 */
class SubRecipes
{
    /** Profondeur maximale (une pâte dans une tarte dans un menu : trois niveaux suffisent largement). */
    public const MAX_DEPTH = 3;

    /** Relations chargées d'un coup pour éviter une requête par ligne. */
    public const EAGER = [
        'components.component.ingredients.ingredient.defaultUnit',
        'components.component.ingredients.ingredient.aisle',
        'components.component.ingredients.ingredient.referencePriceUnit',
        'components.component.ingredients.unit',
    ];

    /**
     * Toutes les lignes d'ingrédients de la recette, sous-recettes dépliées.
     *
     * Les lignes directes sont renvoyées telles quelles ; celles des sous-recettes sont des copies
     * (jamais enregistrées) dont la quantité est déjà multipliée, exprimées pour les portions de la
     * recette principale. L'attribut `via_recipe` porte le nom de la sous-recette.
     *
     * @return Collection<int, RecipeIngredient>
     */
    public function lines(Recipe $recipe): Collection
    {
        $recipe->loadMissing('ingredients.ingredient', 'ingredients.unit');

        if (! $this->hasComponents($recipe)) {
            return $recipe->ingredients;
        }

        return $recipe->ingredients->concat($this->componentLines($recipe, 1.0, 1, [$recipe->id]))->values();
    }

    public function hasComponents(Recipe $recipe): bool
    {
        if ($recipe->relationLoaded('components')) {
            return $recipe->components->isNotEmpty();
        }

        if (! $recipe->exists) {
            return false;
        }

        $recipe->load('components');

        return $recipe->components->isNotEmpty();
    }

    /**
     * @param  list<int>  $path  recettes déjà traversées (garde-fou contre une boucle en base)
     * @return Collection<int, RecipeIngredient>
     */
    private function componentLines(Recipe $recipe, float $factor, int $depth, array $path): Collection
    {
        if ($depth > self::MAX_DEPTH) {
            return collect();
        }

        $recipe->loadMissing(self::EAGER);
        $lines = collect();

        foreach ($recipe->components as $component) {
            $sub = $component->component;

            if (! $sub || in_array($sub->id, $path, true)) {
                continue;
            }

            $subFactor = $factor * $component->factor();

            foreach ($sub->ingredients as $line) {
                $lines->push($this->scaledCopy($line, $subFactor, $sub->title));
            }

            if ($this->hasComponents($sub)) {
                $lines = $lines->concat($this->componentLines($sub, $subFactor, $depth + 1, [...$path, $sub->id]));
            }
        }

        return $lines;
    }

    private function scaledCopy(RecipeIngredient $line, float $factor, string $via): RecipeIngredient
    {
        $copy = $line->replicate();
        $copy->quantity = $line->quantity === null ? null : round((float) $line->quantity * $factor, 3);
        $copy->setRelation('ingredient', $line->ingredient);
        $copy->setRelation('unit', $line->unit);
        $copy->setAttribute('via_recipe', $via);

        return $copy;
    }

    /* ================================================================ Écriture */

    /**
     * Raison pour laquelle `$candidate` ne peut pas être utilisée dans `$recipe`, ou null.
     */
    public function refusal(Recipe $recipe, Recipe $candidate): ?string
    {
        if ($recipe->exists && $candidate->id === $recipe->id) {
            return 'Une recette ne peut pas s\'utiliser elle-même.';
        }

        if ($recipe->exists && in_array($recipe->id, $this->descendants($candidate), true)) {
            return "« {$candidate->title} » utilise déjà « {$recipe->title} » : ce serait une boucle.";
        }

        return null;
    }

    /**
     * Toutes les recettes utilisées par `$recipe`, directement ou non.
     *
     * @return list<int>
     */
    public function descendants(Recipe $recipe, int $depth = 0): array
    {
        if ($depth > 10) {
            return [];
        }

        $ids = RecipeComponent::query()->where('recipe_id', $recipe->id)->pluck('component_recipe_id')->all();
        $all = $ids;

        foreach ($ids as $id) {
            if ($child = Recipe::find($id)) {
                $all = [...$all, ...$this->descendants($child, $depth + 1)];
            }
        }

        return array_values(array_unique($all));
    }

    /**
     * Remplace les sous-recettes d'une recette.
     *
     * @param  list<array{recipe_id: int|string, quantity?: float|string|null, note?: string|null}>  $rows
     */
    public function sync(Recipe $recipe, array $rows): void
    {
        $clean = [];

        foreach ($rows as $index => $row) {
            $candidate = Recipe::find((int) ($row['recipe_id'] ?? 0));

            if (! $candidate || isset($clean[$candidate->id])) {
                continue;
            }

            if ($reason = $this->refusal($recipe, $candidate)) {
                throw new InvalidArgumentException($reason);
            }

            $quantity = (float) str_replace(',', '.', (string) ($row['quantity'] ?? 1));

            if ($quantity <= 0 || $quantity > 20) {
                throw new InvalidArgumentException("Quantité invalide pour « {$candidate->title} » (entre 0,1 et 20 fois la recette).");
            }

            $clean[$candidate->id] = [
                'component_recipe_id' => $candidate->id,
                'quantity' => round($quantity, 3),
                'note' => mb_substr(trim((string) ($row['note'] ?? '')), 0, 150) ?: null,
                'sort_order' => $index,
            ];
        }

        DB::transaction(function () use ($recipe, $clean) {
            $recipe->components()->whereNotIn('component_recipe_id', array_keys($clean))->delete();

            foreach ($clean as $id => $data) {
                RecipeComponent::updateOrCreate(['recipe_id' => $recipe->id, 'component_recipe_id' => $id], $data);
            }
        });

        $recipe->unsetRelation('components');
    }

    /** Recettes qui utilisent celle-ci (pour empêcher sa suppression). @return Collection<int, Recipe> */
    public function parents(Recipe $recipe): Collection
    {
        return Recipe::query()
            ->whereIn('id', RecipeComponent::query()->where('component_recipe_id', $recipe->id)->select('recipe_id'))
            ->orderBy('title')
            ->get();
    }

    /** L'étape en HTML échappé, avec un lien vers chaque sous-recette citée. */
    public function linkifyHtml(string $text, Collection $components): \Illuminate\Support\HtmlString
    {
        $html = '';

        foreach ($this->linkify($text, $components) as $part) {
            $html .= $part['recipe']
                ? '<a href="'.e(route('recipes.show', $part['recipe'])).'" wire:navigate class="font-medium text-brand-700 underline">'.e($part['text']).'</a>'
                : e($part['text']);
        }

        return new \Illuminate\Support\HtmlString($html);
    }

    /**
     * Découpe une étape en morceaux, en repérant le nom des sous-recettes pour en faire des liens.
     *
     * @return list<array{text: string, recipe: Recipe|null}>
     */
    public function linkify(string $text, Collection $components): array
    {
        $parts = [['text' => $text, 'recipe' => null]];

        foreach ($components as $component) {
            $sub = $component instanceof RecipeComponent ? $component->component : $component;

            if (! $sub || mb_strlen($sub->title) < 4) {
                continue;
            }

            $next = [];

            foreach ($parts as $part) {
                if ($part['recipe'] !== null) {
                    $next[] = $part;

                    continue;
                }

                $pieces = preg_split('/('.preg_quote($sub->title, '/').')/iu', $part['text'], -1, PREG_SPLIT_DELIM_CAPTURE);

                foreach ($pieces as $i => $piece) {
                    if ($piece === '') {
                        continue;
                    }

                    $next[] = ['text' => $piece, 'recipe' => $i % 2 === 1 ? $sub : null];
                }
            }

            $parts = $next;
        }

        return $parts;
    }
}
