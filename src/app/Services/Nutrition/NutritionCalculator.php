<?php

namespace App\Services\Nutrition;

use App\Enums\UnitType;
use App\Models\Ingredient;
use App\Models\NutritionFood;
use App\Models\Recipe;
use App\Services\QuantityScaler;
use App\Services\UnitConverter;
use Illuminate\Support\Collection;

/**
 * Valeurs nutritionnelles indicatives (17.4, règle R19).
 *
 * Trois précautions, qui sont le cœur de la règle :
 *  1. une ligne qu'on ne sait pas peser (« 1 boîte de… ») n'est **pas** comptée ;
 *  2. la **couverture** dit quelle part du poids de la recette a pu être chiffrée ;
 *  3. en dessous de 70 %, les valeurs sont **masquées** plutôt qu'affichées à la louche.
 *
 * C'est indicatif : aucune recommandation médicale, aucun apport journalier, aucun jugement.
 */
class NutritionCalculator
{
    /** En dessous de cette couverture, on n'affiche rien (R19). */
    public const MIN_COVERAGE = 70;

    public function __construct(
        private readonly UnitConverter $converter,
        private readonly QuantityScaler $scaler,
    ) {}

    /** La table Ciqual a-t-elle été importée ? */
    public function hasTable(): bool
    {
        return NutritionFood::query()->exists();
    }

    /**
     * Poids d'une ligne en grammes, ou null si on ne sait pas la peser honnêtement.
     */
    public function grams(?float $quantity, ?object $unit, ?Ingredient $ingredient): ?float
    {
        if ($quantity === null || $quantity <= 0 || ! $ingredient) {
            return null;
        }

        $unit ??= $ingredient->defaultUnit;

        // Sans unité : on compte en pièces, si le poids d'une pièce est connu (lot 1).
        if ($unit === null) {
            return $ingredient->piece_weight_g ? $quantity * (float) $ingredient->piece_weight_g : null;
        }

        return match ($unit->type) {
            UnitType::Mass => $this->converter->toBase($quantity, $unit),
            // Volume : × densité de l'ingrédient (1 g/ml par défaut, modifiable).
            UnitType::Volume => ($ml = $this->converter->toBase($quantity, $unit)) === null
                ? null
                : $ml * (float) ($ingredient->density ?? 1),
            UnitType::Piece => $ingredient->piece_weight_g ? $quantity * (float) $ingredient->piece_weight_g : null,
            default => null,
        };
    }

    /**
     * Valeurs par portion d'une recette.
     *
     * @return array{known: bool, values: array<string, float>, coverage: int, counted: int, weighed: int, lines: int, unweighable: int, servings: int, missing: list<string>}
     */
    public function recipe(Recipe $recipe, int|float|null $servings = null): array
    {
        $servings = max(0.5, (float) ($servings ?? (int) $recipe->servings));
        $base = max(1, (int) $recipe->servings);

        $recipe->loadMissing(['ingredients.ingredient', 'ingredients.unit']);

        $all = app(\App\Services\Recipes\SubRecipes::class)->lines($recipe);
        $foods = $this->foodsFor($all->pluck('ingredient')->filter());

        $totals = array_fill_keys(array_keys(NutritionFood::NUTRIENTS), 0.0);
        $weighed = 0.0;      // grammes de lignes pesées
        $counted = 0.0;      // grammes de lignes pesées ET chiffrées
        $lines = 0;
        $countedLines = 0;
        $unweighable = 0;
        $missing = [];

        foreach ($all as $line) {
            if ($line->is_optional || ! $line->ingredient) {
                continue;
            }

            $lines++;
            $quantity = $this->scaler->scale($line->quantity, $base, $servings);
            $grams = $this->grams($quantity, $line->unit, $line->ingredient);

            if ($grams === null) {
                $unweighable++;

                continue;
            }

            $weighed += $grams;
            $food = $foods->get($line->ingredient->ciqual_code ?? '');

            if (! $food) {
                $missing[] = (string) $line->ingredient->name;

                continue;
            }

            $counted += $grams;
            $countedLines++;

            foreach (array_keys(NutritionFood::NUTRIENTS) as $nutrient) {
                $totals[$nutrient] += $grams * (float) ($food->{$nutrient} ?? 0) / 100;
            }
        }

        $coverage = $weighed > 0 ? (int) round($counted / $weighed * 100) : 0;
        $known = $countedLines > 0 && $coverage >= self::MIN_COVERAGE && $unweighable === 0;

        return [
            'known' => $known,
            'values' => collect($totals)->map(fn (float $total) => round($total / $servings, 1))->all(),
            'coverage' => $coverage,
            'counted' => $countedLines,
            'weighed' => (int) round($weighed),
            'lines' => $lines,
            'unweighable' => $unweighable,
            'servings' => $servings,
            'missing' => array_values(array_unique($missing)),
        ];
    }

    /**
     * Pourquoi les valeurs ne sont pas affichées, en une phrase.
     */
    public function explain(array $result): ?string
    {
        if ($result['known']) {
            return null;
        }

        if (! $this->hasTable()) {
            return 'La table Ciqual n\'est pas encore importée (Paramètres → Nutrition).';
        }

        if ($result['counted'] === 0) {
            return 'Aucun ingrédient de cette recette n\'est rattaché à un aliment de la table.';
        }

        if ($result['unweighable'] > 0) {
            return $result['unweighable'].' ingrédient(s) sans quantité chiffrable : données insuffisantes.';
        }

        return 'Données insuffisantes : '.$result['coverage'].' % du poids seulement est rattaché à un aliment connu'
            .($result['missing'] !== [] ? ' (manque : '.collect($result['missing'])->take(3)->join(', ').')' : '').'.';
    }

    /** Libellé d'une valeur : « 512 kcal », « 12,4 g ». */
    public function format(string $nutrient, float $value): string
    {
        [$label, $unit] = NutritionFood::NUTRIENTS[$nutrient] ?? ['', ''];
        unset($label);

        return $unit === 'kcal'
            ? number_format($value, 0, ',', "\u{202F}").' kcal'
            : number_format($value, 1, ',', "\u{202F}").' '.$unit;
    }

    /**
     * Aliments Ciqual des ingrédients donnés, indexés par code.
     *
     * @param  Collection<int, Ingredient>  $ingredients
     * @return Collection<string, NutritionFood>
     */
    private function foodsFor(Collection $ingredients): Collection
    {
        $codes = $ingredients->pluck('ciqual_code')->filter()->unique();

        return $codes->isEmpty()
            ? collect()
            : NutritionFood::query()->whereIn('ciqual_code', $codes)->get()->keyBy('ciqual_code');
    }
}
