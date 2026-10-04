<?php

namespace App\Services\Recipes;

use App\Enums\RestrictionType;
use App\Models\Guest;
use App\Models\MealOccasion;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeVariant;
use App\Models\RecipeVariantSwap;
use Illuminate\Support\Collection;

/**
 * Variantes d'une recette (13.7).
 *
 * Une variante ne duplique pas la recette : elle dit seulement ce qui change. « Version
 * végétarienne » = les lardons deviennent des dés de tofu ; « sans lactose » = la crème
 * devient de la crème de soja. Les étapes, le temps, la photo restent ceux de la recette.
 *
 * L'intérêt arrive au moment où on en a besoin : quand un convive a une contrainte que la
 * recette heurte, la variante qui la lève est **proposée d'elle-même**.
 */
class VariantService
{
    /**
     * Lignes d'ingrédients de la recette une fois la variante appliquée.
     *
     * Les lignes remplacées gardent leur place : on lit la recette dans le même ordre,
     * avec un ingrédient différent.
     *
     * @return Collection<int, array{line: RecipeIngredient, ingredient: \App\Models\Ingredient|null, quantity: float|null, unit: \App\Models\Unit|null, swapped: bool, removed: bool, note: string|null}>
     */
    public function lines(Recipe $recipe, ?RecipeVariant $variant = null): Collection
    {
        $recipe->loadMissing(['ingredients.ingredient', 'ingredients.unit']);
        $swaps = $variant
            ? $variant->swaps()->with(['ingredient', 'unit'])->get()->keyBy('recipe_ingredient_id')
            : collect();

        return $recipe->ingredients->map(function (RecipeIngredient $line) use ($swaps) {
            /** @var RecipeVariantSwap|null $swap */
            $swap = $swaps->get($line->id);

            if (! $swap) {
                return [
                    'line' => $line,
                    'ingredient' => $line->ingredient,
                    'quantity' => $line->quantity === null ? null : (float) $line->quantity,
                    'unit' => $line->unit,
                    'swapped' => false,
                    'removed' => false,
                    'note' => null,
                ];
            }

            return [
                'line' => $line,
                'ingredient' => $swap->ingredient,
                'quantity' => $swap->quantity !== null ? (float) $swap->quantity : ($line->quantity === null ? null : (float) $line->quantity),
                'unit' => $swap->unit ?? $line->unit,
                'swapped' => ! $swap->removesLine(),
                'removed' => $swap->removesLine(),
                'note' => $swap->note,
            ];
        });
    }

    /**
     * Variantes d'une recette qui lèvent une contrainte donnée.
     *
     * @return Collection<int, RecipeVariant>
     */
    public function solving(Recipe $recipe, RestrictionType $type, ?int $ingredientId, ?int $tagId): Collection
    {
        return $recipe->variants()
            ->where(function ($query) use ($type, $ingredientId, $tagId) {
                $query->where(fn ($q) => $q->where('solves_type', $type->value)
                    ->when($ingredientId, fn ($q2) => $q2->where('solves_ingredient_id', $ingredientId))
                    ->when($tagId, fn ($q2) => $q2->where('solves_tag_id', $tagId)));
            })
            ->get();
    }

    /**
     * Variante à proposer pour un repas, d'après les contraintes des convives (13.7).
     *
     * Ne propose rien quand la recette convient déjà : une suggestion qui tombe à côté est pire
     * qu'une absence de suggestion.
     *
     * @return array{variant: RecipeVariant, reason: string}|null
     */
    public function suggestFor(Recipe $recipe, ?MealOccasion $occasion): ?array
    {
        if (! $occasion) {
            return null;
        }

        $recipe->loadMissing(['variants.solvesIngredient', 'variants.solvesTag', 'ingredients.ingredient', 'tags']);

        if ($recipe->variants->isEmpty()) {
            return null;
        }

        $ingredientIds = $recipe->ingredients->pluck('ingredient_id')->filter()->all();
        $tagIds = $recipe->tags->pluck('id')->all();

        foreach ($occasion->guests as $guest) {
            foreach ($this->restrictionsOf($guest) as $restriction) {
                $variant = $recipe->variants->first(function (RecipeVariant $candidate) use ($restriction, $ingredientIds, $tagIds) {
                    if ($candidate->solves_type !== $restriction->type) {
                        return false;
                    }

                    // Contrainte sur un ingrédient réellement présent dans la recette ?
                    if ($restriction->ingredient_id) {
                        return $candidate->solves_ingredient_id === $restriction->ingredient_id
                            && in_array($restriction->ingredient_id, $ingredientIds, true);
                    }

                    // Contrainte de régime : la recette n'a pas déjà la catégorie demandée.
                    return $candidate->solves_tag_id === $restriction->tag_id
                        && ! in_array($restriction->tag_id, $tagIds, true);
                });

                if ($variant) {
                    return [
                        'variant' => $variant,
                        'reason' => $this->reason($guest->name, $restriction->type, $variant->solvesLabel()),
                    ];
                }
            }
        }

        return null;
    }

    /** « Pour Julie (allergie aux noix) : version sans noix. » */
    private function reason(string $who, RestrictionType $type, ?string $what): string
    {
        return match ($type) {
            RestrictionType::Allergy => "{$who} est allergique".($what ? " ({$what})" : '').' : cette variante l\'évite.',
            RestrictionType::Dislike => "{$who} n'aime pas".($what ? " {$what}" : '').' : cette variante l\'évite.',
            RestrictionType::Diet => "{$who} suit un régime".($what ? " {$what}" : '').' : cette variante convient.',
        };
    }

    /** @return Collection<int, \App\Models\GuestRestriction> */
    private function restrictionsOf(Guest $guest): Collection
    {
        return $guest->relationLoaded('restrictions') ? $guest->restrictions : $guest->restrictions()->get();
    }

    /**
     * Enregistre un remplacement dans une variante ; `$ingredientId` à null retire la ligne.
     */
    public function setSwap(RecipeVariant $variant, int $lineId, ?int $ingredientId, ?float $quantity, ?int $unitId, ?string $note = null, string $source = 'variant'): RecipeVariantSwap
    {
        $swap = RecipeVariantSwap::updateOrCreate(
            ['recipe_variant_id' => $variant->id, 'recipe_ingredient_id' => $lineId],
            ['ingredient_id' => $ingredientId, 'quantity' => $quantity, 'unit_id' => $unitId, 'note' => $note],
        );

        $this->rememberSubstitution($variant, $lineId, $ingredientId, $quantity, $unitId, $source);

        return $swap;
    }

    /**
     * Lot 40 (40.1) : un remplacement d'une variante (ou accepté depuis l'assistant) devient un
     * remplacement de la recette, proposé aussi en cuisine et sur la liste de courses.
     */
    private function rememberSubstitution(RecipeVariant $variant, int $lineId, ?int $ingredientId, ?float $quantity, ?int $unitId, string $source): void
    {
        $line = RecipeIngredient::query()->find($lineId);

        if (! $ingredientId || ! $line?->ingredient_id || $line->ingredient_id === $ingredientId || $variant->recipe?->isForeign()) {
            return;
        }

        $ratio = $quantity !== null && $line->quantity !== null && (float) $line->quantity > 0 && (int) $unitId === (int) $line->unit_id
            ? $quantity / (float) $line->quantity
            : 1.0;

        try {
            app(Substitutions::class)->add($line->ingredient_id, $ingredientId, max(0.05, min(20, round($ratio, 3))), $variant->recipe_id, 'variante « '.$variant->name.' »', $source);
        } catch (\InvalidArgumentException) {
            // un remplacement incohérent n'empêche pas la variante
        }
    }

    public function removeSwap(RecipeVariant $variant, int $lineId): void
    {
        $variant->swaps()->where('recipe_ingredient_id', $lineId)->delete();
    }
}
