<?php

namespace App\Services\Stock;

use App\Enums\RestrictionType;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\StockItem;
use App\Services\Planning\GuestCompatibility;
use App\Support\AllergenGroups;
use Illuminate\Support\Collection;

/**
 * Allergènes des produits du stock (lot 40, 40.4, R44).
 *
 * D'après Open Food Facts, donc **indicatif** : « contient » et « peut contenir des traces » sont
 * distingués, et seule une allergie déclenche une alerte pour les traces. Un produit sans
 * information n'est jamais présenté comme sûr.
 *
 * Une allergie notée sur un ingrédient (« Lait demi-écrémé ») vaut pour tout son groupe (lait) :
 * le produit « contient du lait » est signalé.
 */
class ProductAllergens
{
    /** @var array<int, Collection<int, StockItem>>|null produits en stock, par ingrédient */
    private ?array $stock = null;

    /**
     * Ce que le produit pose comme problème à ces personnes.
     *
     * @param  iterable<object>  $eaters  personnes, invités (restrictions chargées)
     * @return list<array{level: string, guest: string, group: string, traces: bool, message: string}>
     */
    public function problems(Product $product, iterable $eaters): array
    {
        $problems = [];
        $contains = (array) $product->allergens;
        $traces = (array) $product->traces;

        foreach ($eaters as $eater) {
            foreach ($eater->restrictions ?? [] as $restriction) {
                if (! $restriction->ingredient_id || ! in_array($restriction->type, [RestrictionType::Allergy, RestrictionType::Dislike], true)) {
                    continue;
                }

                $allergy = $restriction->type === RestrictionType::Allergy;

                foreach (AllergenGroups::ofName($restriction->ingredient?->name) as $group) {
                    $inTraces = ! in_array($group, $contains, true) && in_array($group, $traces, true);

                    if (! in_array($group, $contains, true) && ! ($allergy && $inTraces)) {
                        continue;
                    }

                    $label = AllergenGroups::label($group);
                    $problems[] = [
                        'level' => $allergy && ! $inTraces ? GuestCompatibility::DANGER : GuestCompatibility::WARNING,
                        'guest' => (string) $eater->name,
                        'group' => $group,
                        'traces' => $inTraces,
                        'message' => ($inTraces ? 'Peut contenir des traces de '.$label : 'Contient : '.$label)
                            .' — '.($allergy ? 'allergie de ' : 'n\'aime pas : ').$eater->name,
                    ];
                }
            }
        }

        return collect($problems)->unique(fn ($p) => $p['guest'].$p['group'])->values()->all();
    }

    /** « lait, gluten » ou null si rien n'est connu (R44 : « allergènes inconnus »). */
    public function summary(Product $product): ?string
    {
        if ($product->allergens === null) {
            return null;
        }

        $text = $product->allergens === [] ? 'aucun allergène déclaré' : 'contient : '.AllergenGroups::labels($product->allergens);

        if ($product->traces) {
            $text .= ' · traces possibles : '.AllergenGroups::labels($product->traces);
        }

        return $text;
    }

    /**
     * Au moment de prévoir une recette : les produits du stock qu'elle utiliserait et qui heurtent
     * quelqu'un à table. Même forme que GuestCompatibility::conflicts.
     *
     * @return list<array{level: string, guest: string, type: RestrictionType, subject: string, optional: bool, message: string, ingredient_id: int|null}>
     */
    public function recipeConflicts(Recipe $recipe, iterable $eaters): array
    {
        $eaters = collect($eaters)->filter(fn ($eater) => collect($eater->restrictions ?? [])->contains(fn ($r) => $r->ingredient_id !== null));

        if ($eaters->isEmpty()) {
            return [];
        }

        $stock = $this->stock();
        $recipe->loadMissing('ingredients');
        $conflicts = [];

        foreach ($recipe->ingredients->pluck('ingredient_id')->filter()->unique() as $ingredientId) {
            foreach ($stock[(int) $ingredientId] ?? [] as $item) {
                foreach ($this->problems($item->product, $eaters) as $problem) {
                    $conflicts[] = [
                        'level' => $problem['level'],
                        'guest' => $problem['guest'],
                        'type' => RestrictionType::Allergy,
                        'subject' => $item->product->label,
                        'optional' => false,
                        'ingredient_id' => (int) $ingredientId,
                        'source' => 'stock',   // lot 42 : le stock d'un foyer ne regarde que lui
                        'message' => '« '.$item->product->fullName().' » en stock : '.mb_strtolower(mb_substr($problem['message'], 0, 1)).mb_substr($problem['message'], 1).' (d\'après Open Food Facts)',
                    ];
                }
            }
        }

        return collect($conflicts)->unique('message')->values()->all();
    }

    /** @return array<int, Collection<int, StockItem>> */
    private function stock(): array
    {
        return $this->stock ??= StockItem::query()->active()->whereNotNull('product_id')->whereNotNull('ingredient_id')
            ->with('product')->get()
            ->filter(fn (StockItem $item) => $item->product && ($item->product->allergens || $item->product->traces))
            ->unique(fn (StockItem $item) => $item->ingredient_id.'-'.$item->product_id)
            ->groupBy('ingredient_id')->all();
    }
}
