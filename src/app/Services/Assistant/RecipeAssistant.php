<?php

namespace App\Services\Assistant;

use App\Livewire\Forms\RecipeForm;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeVariant;
use App\Services\IngredientLineFormatter;
use App\Services\Recipes\IngredientLineParser;
use App\Services\Recipes\VariantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Ce que l'assistant culinaire sait faire (lot 33), et **ce qu'on lui envoie** (R34).
 *
 * Seulement : la recette concernée (titre, portions, temps, lignes d'ingrédients, étapes), ou la
 * liste d'ingrédients tapée, ou la contrainte choisie (« sans lactose »). Jamais : un nom de
 * personne, les contraintes nominatives des invités, le stock, les dépenses, les notes de cuisine.
 *
 * Rien n'est enregistré sans relecture : les réponses sont des brouillons, marqués « proposé par
 * l'assistant », que l'on accepte, corrige ou jette.
 */
class RecipeAssistant
{
    /** Transformations proposées (33.1) : clé => [nom de la variante, consigne envoyée]. */
    public const PRESETS = [
        'vegetarien' => ['Version végétarienne', 'une version végétarienne : sans viande ni poisson'],
        'vegetalien' => ['Version végétalienne', 'une version végétalienne : sans aucun produit d\'origine animale'],
        'sans-lactose' => ['Sans lactose', 'une version sans lactose : ni lait, ni crème, ni beurre, ni fromage qui en contiennent'],
        'sans-gluten' => ['Sans gluten', 'une version sans gluten : ni blé, ni orge, ni seigle, ni produits qui en contiennent'],
        'rapide' => ['Plus rapide', 'une version plus rapide à préparer, aux étapes simplifiées'],
        'leger' => ['Plus légère', 'une version plus légère : moins grasse et moins sucrée'],
        'petit-four' => ['Pour 2, petit four', 'une version pour 2 personnes, qui tient dans un petit four'],
    ];

    public const MAX_FREE_TEXT = 150;

    private const SYSTEM = 'Tu es un assistant de cuisine familiale, en français. Tu proposes des recettes simples, '
        .'réalistes et sûres, avec des ingrédients courants au Luxembourg et en France. Quantités en unités '
        .'métriques (g, kg, ml, cl, l, c. à soupe, c. à café, pièces). Tu ne donnes jamais de conseil médical.';

    public function __construct(
        private readonly AssistantService $service,
        private readonly IngredientLineFormatter $formatter,
        private readonly IngredientLineParser $parser,
    ) {}

    /* ================================================================ 33.1 Transformer une recette */

    /**
     * Lignes d'ingrédients de la recette, en texte (« 200 g de lardons, coupés en dés »).
     *
     * @return list<string>
     */
    public function recipeLines(Recipe $recipe): array
    {
        $recipe->loadMissing('ingredients.ingredient', 'ingredients.unit');

        return $recipe->ingredients->map(function ($line) {
            $text = $this->formatter->format($line->quantity === null ? null : (float) $line->quantity, $line->unit, $line->ingredient)['text'];

            return trim($text.($line->preparation ? ', '.$line->preparation : '').($line->is_optional ? ' (facultatif)' : ''));
        })->values()->all();
    }

    /** Consigne envoyée : un choix de la liste, ou un texte libre court. */
    public function instruction(string $preset, ?string $free = null): array
    {
        if (isset(self::PRESETS[$preset])) {
            return ['name' => self::PRESETS[$preset][0], 'text' => self::PRESETS[$preset][1]];
        }

        $free = Str::squish((string) $free);

        if ($free === '' || mb_strlen($free) > self::MAX_FREE_TEXT) {
            throw new InvalidArgumentException('Décrivez la transformation en '.self::MAX_FREE_TEXT.' caractères au plus.');
        }

        return ['name' => Str::ucfirst(Str::limit($free, 60, '')), 'text' => $free];
    }

    /**
     * Propose une transformation (33.1). Le résultat est un brouillon, rien n'est enregistré.
     *
     * @return array{name: string, summary: string, lines: list<array{original: string, action: string, replacement: string|null}>, added: list<string>, steps: list<array{text: string, changed: bool}>, warning: string|null}
     */
    public function transform(Recipe $recipe, string $preset, ?string $free = null): array
    {
        $instruction = $this->instruction($preset, $free);
        $lines = $this->recipeLines($recipe);
        $steps = $recipe->steps()->pluck('instruction')->all();

        $prompt = "Adapte cette recette pour en faire {$instruction['text']}.\n"
            ."Pour chaque ingrédient (repéré par son numéro), dis s'il est gardé (keep), remplacé (replace, avec la nouvelle ligne complète, quantité comprise) ou retiré (remove). "
            ."Ajoute dans added les ingrédients nouveaux seulement s'il le faut. Réécris toutes les étapes, en marquant changed celles qui changent. "
            .'name : nom court de la version (ex. « Version végétarienne »). summary : une ou deux phrases sur ce qui change. '
            ."warning : null, ou une phrase si l'adaptation est impossible ou discutable.\n\n"
            .$this->recipeText($recipe, $lines, $steps);

        $answer = $this->service->run('transform', self::SYSTEM, $prompt, self::transformSchema(), 2500);

        return $this->normalizeTransform($answer->data ?? [], $lines, $steps, $instruction['name']);
    }

    /** @return array<string, mixed> */
    public function normalizeTransform(array $data, array $lines, array $steps, string $defaultName): array
    {
        $byIndex = collect((array) ($data['lines'] ?? []))->filter(fn ($row) => is_array($row))->keyBy(fn ($row) => (int) ($row['index'] ?? -1));

        $result = [
            'name' => Str::limit(self::clean($data['name'] ?? '') ?: $defaultName, 60, ''),
            'summary' => Str::limit(self::clean($data['summary'] ?? ''), 400),
            'lines' => [],
            'added' => collect((array) ($data['added'] ?? []))->map(fn ($line) => Str::limit(self::clean($line), 150, ''))->filter()->take(10)->values()->all(),
            'steps' => [],
            'warning' => self::clean($data['warning'] ?? '') ?: null,
        ];

        foreach ($lines as $i => $original) {
            $row = $byIndex->get($i + 1) ?? $byIndex->get($i) ?? [];
            $action = in_array($row['action'] ?? 'keep', ['keep', 'replace', 'remove'], true) ? ($row['action'] ?? 'keep') : 'keep';
            $replacement = Str::limit(self::clean($row['replacement'] ?? ''), 150, '') ?: null;

            if ($action === 'replace' && $replacement === null) {
                $action = 'keep';
            }

            $result['lines'][] = ['original' => $original, 'action' => $action, 'replacement' => $action === 'replace' ? $replacement : null];
        }

        $newSteps = collect((array) ($data['steps'] ?? []))->filter(fn ($row) => is_array($row))
            ->map(fn ($step) => ['text' => Str::limit(self::clean($step['text'] ?? ''), 2000, ''), 'changed' => (bool) ($step['changed'] ?? false)])
            ->filter(fn ($step) => $step['text'] !== '')->take(40)->values()->all();

        $result['steps'] = $newSteps !== [] ? $newSteps : array_map(fn ($text) => ['text' => $text, 'changed' => false], $steps);

        return $result;
    }

    /** Une variante (lot 19) ne change que des ingrédients : pas d'ajout. */
    public function fitsVariant(array $result): bool
    {
        return $result['added'] === [] && collect($result['lines'])->contains(fn ($line) => $line['action'] !== 'keep');
    }

    /** Accepte la proposition comme variante de la recette (seulement ses ingrédients). */
    public function saveAsVariant(Recipe $recipe, array $result): RecipeVariant
    {
        if (! $this->fitsVariant($result)) {
            throw new InvalidArgumentException('Cette proposition ajoute des ingrédients : enregistrez-la plutôt comme nouvelle recette.');
        }

        $recipe->loadMissing('ingredients');

        return DB::transaction(function () use ($recipe, $result) {
            $name = $result['name'];
            $i = 2;

            while ($recipe->variants()->where('name', $name)->exists()) {
                $name = Str::limit($result['name'], 55, '').' '.$i++;
            }

            $variant = $recipe->variants()->create([
                'name' => $name,
                'note' => Str::limit(trim($result['summary'].' Proposée par l\'assistant.'), 250, ''),
                'sort_order' => (int) $recipe->variants()->max('sort_order') + 1,
            ]);

            $variants = app(VariantService::class);

            foreach ($result['lines'] as $index => $line) {
                $recipeLine = $recipe->ingredients[$index] ?? null;

                if (! $recipeLine || $line['action'] === 'keep') {
                    continue;
                }

                if ($line['action'] === 'remove') {
                    $variants->setSwap($variant, $recipeLine->id, null, null, null);

                    continue;
                }

                // Seul un nom reconnu exactement est repris : « lait d'avoine » ne doit pas devenir « Lait ».
                $parsed = $this->parser->parse((string) $line['replacement']);
                $known = $parsed['confidence'] === 'high' ? $parsed['ingredient'] : null;
                $ingredient = $known ?? ($parsed['name'] !== '' ? (Ingredient::findByName($parsed['name']) ?? Ingredient::create([
                    'name' => Str::ucfirst($parsed['name']),
                    'aisle_id' => RecipeForm::defaultAisleId(),
                    'default_unit_id' => $parsed['unit']?->id,
                ])) : null);

                if ($ingredient) {
                    $variants->setSwap($variant, $recipeLine->id, $ingredient->id, $parsed['quantity'], $parsed['unit']?->id, $parsed['preparation'], 'assistant');
                }
            }

            return $variant;
        });
    }

    /** Brouillon d'une nouvelle recette, relu dans l'écran d'import avant d'être enregistré. */
    public function transformAsDraft(Recipe $recipe, array $result): array
    {
        $lines = [];

        foreach ($result['lines'] as $line) {
            match ($line['action']) {
                'keep' => $lines[] = $line['original'],
                'replace' => $lines[] = $line['replacement'],
                default => null,
            };
        }

        return [
            'label' => 'd\'après « '.$recipe->title.' », '.mb_strtolower($result['name']),
            'title' => Str::limit($recipe->title.' — '.mb_strtolower($result['name']), 200, ''),
            'description' => $result['summary'],
            'servings' => (int) $recipe->servings,
            'prep_minutes' => $recipe->prep_minutes,
            'cook_minutes' => $recipe->cook_minutes,
            'rest_minutes' => $recipe->rest_minutes,
            'lines' => [...$lines, ...$result['added']],
            'steps' => array_column($result['steps'], 'text'),
            'source' => 'Adaptée par l\'assistant, d\'après « '.$recipe->title.' »',
        ];
    }

    /* ================================================================ 33.2 Que faire avec… */

    /** « courgettes, feta et riz » → ['courgettes', 'feta', 'riz'] */
    public function ingredientsFromText(string $text): array
    {
        return collect(preg_split('/\s*(?:,|;|\n|\bet\b|\+)\s*/u', mb_strtolower(Str::limit($text, 300, ''))))
            ->map(fn ($part) => trim((string) $part, " \t.-"))
            ->filter(fn ($part) => mb_strlen($part) >= 2)
            ->unique()->take(12)->values()->all();
    }

    /**
     * Trois idées nouvelles (33.2), à importer comme brouillons. Seule la liste tapée est envoyée.
     *
     * @return list<array{title: string, description: string, servings: int, prep_minutes: int|null, cook_minutes: int|null, lines: list<string>, steps: list<string>}>
     */
    public function ideas(string $text): array
    {
        $ingredients = $this->ingredientsFromText($text);

        if ($ingredients === []) {
            throw new InvalidArgumentException('Indiquez au moins un ingrédient.');
        }

        $prompt = 'J\'ai ces ingrédients : '.implode(', ', $ingredients).".\n"
            .'Propose trois recettes différentes, simples, qui les utilisent (on peut ajouter des ingrédients courants du placard). '
            .'Pour chacune : titre, une phrase de description, nombre de portions, temps de préparation et de cuisson en minutes, '
            .'les lignes d\'ingrédients avec leurs quantités (« 200 g de feta ») et les étapes.';

        $answer = $this->service->run('ideas', self::SYSTEM, $prompt, self::ideasSchema(), 3000);

        return collect((array) ($answer->data['ideas'] ?? []))->filter(fn ($row) => is_array($row))->take(3)->map(fn ($idea) => [
            'title' => Str::limit(self::clean($idea['title'] ?? ''), 150, '') ?: 'Idée de l\'assistant',
            'description' => Str::limit(self::clean($idea['description'] ?? ''), 400),
            'servings' => max(1, min(20, (int) ($idea['servings'] ?? 2))),
            'prep_minutes' => self::minutes($idea['prep_minutes'] ?? null),
            'cook_minutes' => self::minutes($idea['cook_minutes'] ?? null),
            'lines' => collect((array) ($idea['ingredients'] ?? []))->map(fn ($l) => Str::limit(self::clean($l), 150, ''))->filter()->take(30)->values()->all(),
            'steps' => collect((array) ($idea['steps'] ?? []))->map(fn ($s) => Str::limit(self::clean($s), 2000, ''))->filter()->take(30)->values()->all(),
        ])->values()->all();
    }

    /** Brouillon d'une idée, relu dans l'écran d'import. */
    public function ideaAsDraft(array $idea): array
    {
        return [
            'label' => 'idée « '.$idea['title'].' »',
            'title' => $idea['title'],
            'description' => $idea['description'],
            'servings' => $idea['servings'],
            'prep_minutes' => $idea['prep_minutes'],
            'cook_minutes' => $idea['cook_minutes'],
            'rest_minutes' => null,
            'lines' => $idea['lines'],
            'steps' => $idea['steps'],
            'source' => 'Idée de l\'assistant',
        ];
    }

    /* ================================================================ 33.3 Compléter un import */

    /**
     * Temps, difficulté et catégories manquants d'une recette en cours d'import (33.3).
     * Seules des catégories déjà connues du foyer peuvent être proposées.
     *
     * @param  list<string>  $lines
     * @param  list<string>  $steps
     * @param  list<string>  $tagNames
     * @return array{prep_minutes: int|null, cook_minutes: int|null, rest_minutes: int|null, difficulty: string|null, tags: list<string>}
     */
    public function complete(string $title, array $lines, array $steps, array $tagNames): array
    {
        $prompt = "Voici une recette importée. Estime les temps de préparation, de cuisson et de repos (en minutes, 0 s'il n'y en a pas), "
            .'la difficulté (easy, medium ou hard), et choisis les catégories qui conviennent parmi cette liste seulement : '
            .implode(', ', $tagNames).".\n\n"
            .$this->recipeText(null, $lines, $steps, $title);

        $answer = $this->service->run('complete', self::SYSTEM, $prompt, self::completeSchema(), 400);
        $data = $answer->data ?? [];
        $known = collect($tagNames)->mapWithKeys(fn ($name) => [mb_strtolower($name) => $name]);

        return [
            'prep_minutes' => self::minutes($data['prep_minutes'] ?? null),
            'cook_minutes' => self::minutes($data['cook_minutes'] ?? null),
            'rest_minutes' => self::minutes($data['rest_minutes'] ?? null),
            'difficulty' => in_array($data['difficulty'] ?? null, ['easy', 'medium', 'hard'], true) ? $data['difficulty'] : null,
            'tags' => collect((array) ($data['tags'] ?? []))->map(fn ($tag) => $known->get(mb_strtolower(trim((string) $tag))))->filter()->unique()->values()->all(),
        ];
    }

    /* ================================================================ 33.4 Une question sur une étape */

    /** Réponse courte, jamais enregistrée dans la recette (33.4). */
    public function question(Recipe $recipe, int $step, string $question): string
    {
        $question = Str::squish($question);

        if ($question === '' || mb_strlen($question) > 200) {
            throw new InvalidArgumentException('Posez votre question en 200 caractères au plus.');
        }

        $steps = $recipe->steps()->pluck('instruction')->all();
        $current = $steps[$step - 1] ?? null;

        $prompt = $this->recipeText($recipe, $this->recipeLines($recipe), $steps)
            ."\n\n".($current ? "Je suis à l'étape {$step} : {$current}\n" : '')
            ."Ma question : {$question}\n"
            .'Réponds en trois phrases au plus, concrètement.';

        return Str::limit($this->service->run('question', self::SYSTEM, $prompt, null, 300)->text, 1200);
    }

    /* ================================================================ Outils */

    /** La recette en texte : ce qui part chez le service (R34). */
    private function recipeText(?Recipe $recipe, array $lines, array $steps, ?string $title = null): string
    {
        $text = 'Recette : '.($recipe?->title ?? $title ?? 'sans titre')."\n";

        if ($recipe) {
            $text .= 'Portions : '.$recipe->servings."\n";
            $times = array_filter(['préparation' => $recipe->prep_minutes, 'cuisson' => $recipe->cook_minutes, 'repos' => $recipe->rest_minutes]);
            if ($times !== []) {
                $text .= 'Temps : '.collect($times)->map(fn ($m, $k) => "{$k} {$m} min")->join(', ')."\n";
            }
        }

        $text .= "Ingrédients :\n";
        foreach (array_values($lines) as $i => $line) {
            $text .= ($i + 1).'. '.$line."\n";
        }

        $text .= "Étapes :\n";
        foreach (array_values($steps) as $i => $step) {
            $text .= ($i + 1).'. '.Str::squish($step)."\n";
        }

        return $text;
    }

    private static function clean(mixed $value): string
    {
        return is_scalar($value) ? Str::squish(strip_tags((string) $value)) : '';
    }

    private static function minutes(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 && (int) $value <= 2880 ? (int) $value : null;
    }

    /* ================================================================ Schémas (mode strict) */

    public static function transformSchema(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['name', 'summary', 'lines', 'added', 'steps', 'warning'],
            'properties' => [
                'name' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'lines' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['index', 'action', 'replacement'],
                    'properties' => [
                        'index' => ['type' => 'integer'],
                        'action' => ['type' => 'string', 'enum' => ['keep', 'replace', 'remove']],
                        'replacement' => $nullableString,
                    ],
                ]],
                'added' => ['type' => 'array', 'items' => ['type' => 'string']],
                'steps' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['text', 'changed'],
                    'properties' => ['text' => ['type' => 'string'], 'changed' => ['type' => 'boolean']],
                ]],
                'warning' => $nullableString,
            ],
        ];
    }

    public static function ideasSchema(): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['ideas'],
            'properties' => ['ideas' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['title', 'description', 'servings', 'prep_minutes', 'cook_minutes', 'ingredients', 'steps'],
                'properties' => [
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'servings' => ['type' => 'integer'],
                    'prep_minutes' => ['type' => ['integer', 'null']],
                    'cook_minutes' => ['type' => ['integer', 'null']],
                    'ingredients' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'steps' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ]]],
        ];
    }

    public static function completeSchema(): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['prep_minutes', 'cook_minutes', 'rest_minutes', 'difficulty', 'tags'],
            'properties' => [
                'prep_minutes' => ['type' => ['integer', 'null']],
                'cook_minutes' => ['type' => ['integer', 'null']],
                'rest_minutes' => ['type' => ['integer', 'null']],
                'difficulty' => ['type' => ['string', 'null'], 'enum' => ['easy', 'medium', 'hard', null]],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }
}
