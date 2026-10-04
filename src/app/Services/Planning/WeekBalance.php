<?php

namespace App\Services\Planning;

use App\Enums\MealType;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Équilibre de la semaine (17.5).
 *
 * Des jauges, pas des notes. Bouffe ne sait pas ce qui est « bien » pour quelqu'un, et ne
 * prétend pas le savoir : la page montre **ce qui a été planifié**, avec un repère indicatif
 * par famille, et laisse la conclusion à la maison. Aucune recommandation médicale.
 *
 * La classification se fait sur les ingrédients les plus lourds de chaque recette (leur rayon
 * et leur nom), ce qui suffit pour dire « cette semaine, trois repas avec du poisson ».
 */
class WeekBalance
{
    /**
     * Les familles suivies : libellé, repère indicatif par semaine (sur 7 dîners), explication.
     */
    public const FAMILIES = [
        'legumes' => ['Légumes', 5, 'Repas où un légume tient une vraie place.'],
        'feculents' => ['Féculents', 4, 'Pâtes, riz, pommes de terre, pain, légumineuses.'],
        'viande' => ['Viande', 3, 'Bœuf, porc, volaille, charcuterie.'],
        'poisson' => ['Poisson', 1, 'Poisson et fruits de mer.'],
        'vegetarien' => ['Sans viande ni poisson', 2, 'Repas végétariens, œufs et légumineuses compris.'],
        'sucre' => ['Sucré', 2, 'Desserts et plats sucrés.'],
    ];

    /** Mots qui rattachent un ingrédient à une famille, en plus de son rayon. */
    private const KEYWORDS = [
        'poisson' => ['saumon', 'cabillaud', 'thon', 'truite', 'colin', 'crevette', 'moule', 'poisson', 'sardine', 'maquereau', 'lieu'],
        'viande' => ['boeuf', 'porc', 'poulet', 'dinde', 'veau', 'agneau', 'lardon', 'jambon', 'saucisse', 'hache', 'canard', 'chorizo'],
        'feculents' => ['pate', 'spaghetti', 'riz', 'pomme de terre', 'semoule', 'quinoa', 'boulgour', 'lentille', 'pois chiche', 'haricot blanc', 'pain', 'farine', 'polenta'],
        'legumes' => ['tomate', 'carotte', 'courgette', 'poireau', 'oignon', 'epinard', 'brocoli', 'chou', 'poivron', 'aubergine', 'salade', 'potiron', 'courge', 'haricot vert', 'petits pois', 'champignon', 'celeri', 'navet', 'panais', 'fenouil', 'concombre'],
        'sucre' => ['sucre', 'chocolat', 'miel', 'confiture', 'creme dessert'],
    ];

    /** Rayons qui rattachent directement à une famille. */
    private const AISLES = [
        'Fruits & légumes' => 'legumes',
        'Boucherie & volaille' => 'viande',
        'Charcuterie & traiteur' => 'viande',
        'Poissonnerie' => 'poisson',
        'Épicerie sucrée' => 'sucre',
    ];

    /**
     * Bilan d'une semaine.
     *
     * @return array{meals: int, canteen: int, families: list<array{key: string, label: string, count: int, target: int, share: int, help: string}>, without: int}
     */
    public function week(Carbon $from, Carbon $to): array
    {
        $meals = PlannedMeal::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('type', [MealType::Recipe->value, MealType::Leftover->value])
            ->with(['recipe.ingredients.ingredient.aisle', 'recipe.ingredients.unit', 'recipe.tags', 'leftoverOf.recipe.ingredients.ingredient.aisle', 'leftoverOf.recipe.tags'])
            ->get();

        $counts = array_fill_keys(array_keys(self::FAMILIES), 0);
        $classified = 0;

        foreach ($meals as $meal) {
            $recipe = $meal->eatenRecipe();

            if (! $recipe) {
                continue;
            }

            $families = $this->familiesOf($recipe);

            if ($families === []) {
                continue;
            }

            $classified++;

            foreach ($families as $family) {
                $counts[$family]++;
            }
        }

        // Lot 39 (R41) : les midis de cantine dont le menu est connu comptent aussi.
        $canteen = app(\App\Services\People\CanteenCalendar::class)->servedBetween($from, $to);

        foreach ($canteen as $served) {
            if ($served['families'] !== []) {
                $classified++;
            }

            foreach ($served['families'] as $family) {
                if (isset($counts[$family])) {
                    $counts[$family]++;
                }
            }
        }

        $count = $meals->count() + $canteen->count();
        $total = max(1, $count);

        return [
            'meals' => $count,
            'canteen' => $canteen->count(),
            'without' => $count - $classified,
            'families' => collect(self::FAMILIES)
                ->map(fn (array $definition, string $key) => [
                    'key' => $key,
                    'label' => $definition[0],
                    'count' => $counts[$key],
                    'target' => $definition[1],
                    'share' => (int) round($counts[$key] / $total * 100),
                    'help' => $definition[2],
                ])
                ->values()->all(),
        ];
    }

    /**
     * Familles auxquelles une recette appartient.
     *
     * @return list<string>
     */
    public function familiesOf(Recipe $recipe): array
    {
        $recipe->loadMissing(['ingredients.ingredient.aisle', 'ingredients.unit', 'tags']);

        // Les trois ingrédients les plus lourds décident : c'est ce qu'on a vraiment dans l'assiette.
        $main = $recipe->ingredients
            ->reject(fn (RecipeIngredient $line) => $line->is_optional || ! $line->ingredient)
            ->sortByDesc(fn (RecipeIngredient $line) => $this->weight($line))
            ->take(3);

        $families = [];

        foreach ($main as $line) {
            foreach ($this->familiesOfIngredient($line) as $family) {
                $families[$family] = true;
            }
        }

        $tags = $recipe->tags->pluck('name')->map(fn ($name) => Str::lower(Str::ascii($name)));

        if ($tags->contains(fn (string $tag) => str_contains($tag, 'dessert')) || $tags->contains('sucre')) {
            $families['sucre'] = true;
        }

        // Végétarien : ni viande ni poisson parmi les ingrédients principaux.
        if ($main->isNotEmpty() && ! isset($families['viande']) && ! isset($families['poisson'])) {
            $families['vegetarien'] = true;
        }

        return array_keys($families);
    }

    /**
     * Familles d'un plat décrit en quelques mots : le menu de la cantine (lot 39, R41).
     * « Poisson pané, purée, yaourt » → poisson, féculents.
     *
     * @return list<string>
     */
    public function familiesOfText(string $text): array
    {
        $name = ' '.Str::lower(Str::ascii($text)).' ';
        $families = [];
        $words = self::KEYWORDS;
        $words['feculents'] = [...$words['feculents'], 'puree', 'frites', 'gratin dauphinois', 'gnocchi', 'lasagne', 'couscous', 'macaroni', 'tagliatelle'];
        $words['viande'] = [...$words['viande'], 'volaille', 'steak', 'cordon bleu', 'nugget', 'merguez', 'boulette', 'bolognaise', 'roti'];
        $words['poisson'] = [...$words['poisson'], 'colin', 'merlu', 'hoki', 'calamar', 'surimi', 'fruits de mer'];
        $words['sucre'] = [...$words['sucre'], 'gateau', 'tarte aux', 'mousse', 'compote', 'creme', 'flan', 'beignet', 'crepe'];

        foreach ($words as $family => $list) {
            foreach ($list as $word) {
                if (preg_match('/\b'.preg_quote($word, '/').'/', $name)) {
                    $families[$family] = true;

                    break;
                }
            }
        }

        // « Omelette », « lentilles », un plat sans viande ni poisson nommé : végétarien.
        if (! isset($families['viande']) && ! isset($families['poisson']) && preg_match('/\b(omelette|oeuf|vegetarien|veggie|falafel|tofu|lentille|pois chiche|quiche aux legumes)/', $name)) {
            $families['vegetarien'] = true;
        }

        return array_keys($families);
    }

    /** @return list<string> */
    private function familiesOfIngredient(RecipeIngredient $line): array
    {
        $families = [];
        $name = Str::lower(Str::ascii((string) $line->ingredient->name));
        $aisle = $line->ingredient->aisle?->name;

        if ($aisle && isset(self::AISLES[$aisle])) {
            $families[] = self::AISLES[$aisle];
        }

        foreach (self::KEYWORDS as $family => $words) {
            foreach ($words as $word) {
                if (str_contains($name, $word)) {
                    $families[] = $family;

                    break;
                }
            }
        }

        return array_values(array_unique($families));
    }

    /** Poids approximatif d'une ligne, pour désigner les ingrédients principaux. */
    private function weight(RecipeIngredient $line): float
    {
        $quantity = $line->quantity === null ? 0.0 : (float) $line->quantity;

        if ($quantity <= 0) {
            return 0.0;
        }

        $unit = $line->unit;

        return match (true) {
            $unit === null => $quantity * (float) ($line->ingredient->piece_weight_g ?? 100),
            $unit->type->isConvertible() && $unit->factor_to_base !== null => $quantity * (float) $unit->factor_to_base,
            default => $quantity * (float) ($line->ingredient->piece_weight_g ?? 100),
        };
    }

    /** @return Collection<int, Recipe> recettes de la semaine, pour l'affichage */
    public function recipesOf(Carbon $from, Carbon $to): Collection
    {
        return PlannedMeal::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('type', MealType::Recipe->value)
            ->with('recipe')
            ->get()
            ->map(fn (PlannedMeal $meal) => $meal->recipe)
            ->filter()
            ->unique('id')
            ->values();
    }
}
