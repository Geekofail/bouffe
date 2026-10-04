<?php

namespace App\Services\Recipes;

use App\Models\Recipe;
use App\Support\NameNormalizer;
use Illuminate\Support\Str;

/**
 * Analyse d'une recette collée en texte libre (règle R14, lot 13.2).
 *
 * On reconnaît le titre, les portions, les temps, puis on sépare les lignes d'ingrédients
 * des étapes — grâce aux titres de sections quand il y en a, sinon à l'allure des lignes.
 * Le résultat a exactement la même forme qu'un import depuis une URL : une ébauche relue
 * dans l'écran d'import, rien n'est enregistré.
 */
class RecipeTextParser
{
    /** Titres qui ouvrent la liste des ingrédients. */
    private const INGREDIENT_HEADINGS = ['ingredients', 'ingredient', 'liste des ingredients', 'il vous faut', 'materiel et ingredients'];

    /** Titres qui ouvrent les étapes. */
    private const STEP_HEADINGS = ['preparation', 'preparations', 'instructions', 'instruction', 'etapes', 'etape', 'realisation', 'realisations', 'recette', 'methode', 'marche a suivre'];

    /** Titres à ignorer complètement (le contenu qui suit est traité normalement). */
    private const NOISE_HEADINGS = ['conseils', 'conseil', 'astuce', 'astuces', 'notes', 'note', 'variantes', 'variante', 'accompagnement', 'conservation', 'materiel'];

    public function __construct(private readonly IngredientLineParser $lines) {}

    /**
     * @return array{
     *   title: string, description: string, servings: int, prep_minutes: int|null, cook_minutes: int|null,
     *   rest_minutes: int|null, source: string, image: string|null, tagIds: list<int>, tagNames: list<string>,
     *   ingredients: list<array>, steps: list<array{instruction: string, group: string|null}>, existing: Recipe|null
     * }
     */
    public function parse(string $text): array
    {
        $lines = $this->split($text);

        $title = '';
        $servings = null;
        $times = ['prep' => null, 'cook' => null, 'rest' => null];

        $section = 'header';          // header · ingredients · steps
        $group = null;                 // « Pour la pâte », « Pour la sauce »
        $ingredients = [];
        $steps = [];

        foreach ($lines as $line) {
            if ($heading = $this->headingType($line)) {
                if ($heading !== 'noise') {
                    $section = $heading;
                    $group = null;
                }

                continue;
            }

            if ($subGroup = $this->groupHeading($line)) {
                $group = $subGroup;

                if ($section === 'header') {
                    $section = 'ingredients';
                }

                continue;
            }

            if ($found = $this->extractServings($line)) {
                $servings = $servings ?? $found;

                if ($this->isOnlyMetadata($line)) {
                    continue;
                }
            }

            if ($this->extractTimes($line, $times) && $this->isOnlyMetadata($line)) {
                continue;
            }

            if ($section === 'header' && $title === '' && $this->looksLikeTitle($line)) {
                $title = Str::limit(trim($line, " \t.:-–—"), 200, '');

                continue;
            }

            $target = $section === 'header'
                ? ($this->lines->looksLikeIngredient($line) ? 'ingredients' : 'steps')
                : $section;

            if ($target === 'ingredients') {
                $parsed = $this->lines->parse($line);

                if ($parsed['name'] !== '') {
                    $ingredients[] = [...$parsed, 'group' => $group];
                }

                continue;
            }

            $steps[] = ['instruction' => Str::squish($this->stripStepNumber($line)), 'group' => $group];
        }

        // Rien n'a été reconnu comme ingrédient : les lignes courtes du début en sont probablement.
        if ($ingredients === [] && $steps !== []) {
            [$ingredients, $steps] = $this->rescueIngredients($steps);
        }

        $title = $title ?: 'Recette collée';

        return [
            'title' => $title,
            'description' => '',
            'servings' => $servings ?: (int) config('bouffe.default_servings', 2),
            'prep_minutes' => $times['prep'],
            'cook_minutes' => $times['cook'],
            'rest_minutes' => $times['rest'],
            'source' => '',
            'image' => null,
            'tagIds' => [],
            'tagNames' => [],
            'ingredients' => array_values($ingredients),
            'steps' => array_values(array_filter($steps, fn (array $step) => mb_strlen($step['instruction']) > 2)),
            'existing' => Recipe::query()->where('search_title', NameNormalizer::normalize($title))->first(),
        ];
    }

    /* ================================================================ Découpage */

    /** @return list<string> */
    private function split(string $text): array
    {
        return collect(preg_split('/\r\n|\r|\n/u', $text) ?: [])
            ->map(fn (string $line) => Str::squish(str_replace(["\u{00A0}", "\u{202F}"], ' ', $line)))
            ->filter(fn (string $line) => $line !== '')
            ->values()->all();
    }

    /** « Ingrédients » / « Préparation » : renvoie ingredients · steps · noise · null. */
    private function headingType(string $line): ?string
    {
        $normalized = NameNormalizer::normalize(trim($line, " \t:.-–—*#"));

        if ($normalized === '' || mb_strlen($normalized) > 30) {
            return null;
        }

        // « Ingrédients (pour 4 personnes) » reste un titre de section.
        $normalized = trim(preg_replace('/\s*\(.*\)$/u', '', $normalized) ?: $normalized);
        $normalized = trim(preg_replace('/\s+pour\s+\d+.*$/u', '', $normalized) ?: $normalized);

        return match (true) {
            in_array($normalized, self::INGREDIENT_HEADINGS, true) => 'ingredients',
            in_array($normalized, self::STEP_HEADINGS, true) => 'steps',
            in_array($normalized, self::NOISE_HEADINGS, true) => 'noise',
            default => null,
        };
    }

    /** « Pour la pâte : » / « Garniture : » → nom de groupe. */
    private function groupHeading(string $line): ?string
    {
        if (! str_ends_with(trim($line), ':') || mb_strlen($line) > 60) {
            return null;
        }

        $label = trim($line, " \t:");

        if ($label === '' || $this->headingType($label)) {
            return null;
        }

        // « 200 g de farine : » n'est pas un titre de groupe.
        return preg_match('/^\s*\d/u', $label) ? null : Str::ucfirst($label);
    }

    private function looksLikeTitle(string $line): bool
    {
        return mb_strlen($line) <= 120
            && ! str_ends_with($line, '.')
            && ! preg_match('/^\s*\d/u', $line)
            && ! $this->extractServings($line);
    }

    /* ================================================================ Métadonnées */

    /** Minuscules sans accents — contrairement à NameNormalizer, les pluriels sont conservés (« repos » ≠ « repo »). */
    private function flatten(string $text): string
    {
        return Str::lower(Str::ascii($text));
    }

    private function extractServings(string $line): ?int
    {
        $normalized = $this->flatten($line);

        if (preg_match('/\b(?:pour|portions?|parts?|personnes?|convives?)\D{0,12}(\d{1,2})\b/u', $normalized, $m)
            || preg_match('/\b(\d{1,2})\s*(?:personnes?|parts?|portions?|convives?)\b/u', $normalized, $m)) {
            $value = (int) $m[1];

            return $value >= 1 && $value <= 50 ? $value : null;
        }

        return null;
    }

    /**
     * « Préparation : 20 min · Cuisson : 30 min · Repos : 1 h »
     *
     * @param  array{prep: int|null, cook: int|null, rest: int|null}  $times
     */
    private function extractTimes(string $line, array &$times): bool
    {
        $found = false;

        $duration = '(\d+\s*h(?:\s*\d+)?|\d+\s*heures?(?:\s*\d+)?|\d+\s*min(?:utes?)?|\d+\s*secondes?)';

        foreach (['prep' => 'preparation|prep', 'cook' => 'cuisson|four', 'rest' => 'repos|attente|refrigeration|levee|pousse'] as $key => $words) {
            if (preg_match('/\b(?:'.$words.')\b[^0-9]{0,12}'.$duration.'/iu', $this->flatten($line), $m)) {
                $minutes = $this->minutes($m[1]);

                if ($minutes !== null) {
                    $times[$key] = $times[$key] ?? $minutes;
                    $found = true;
                }
            }
        }

        return $found;
    }

    private function minutes(string $text): ?int
    {
        $total = 0;

        if (preg_match('/(\d+)\s*(?:h|heures?)\s*(\d+)?/iu', $text, $m)) {
            $total += (int) $m[1] * 60 + (int) ($m[2] ?? 0);
        } elseif (preg_match('/(\d+)\s*min/iu', $text, $m)) {
            $total += (int) $m[1];
        } elseif (preg_match('/^\s*(\d+)\s*$/u', $text, $m)) {
            $total += (int) $m[1];
        }

        return $total > 0 ? min($total, 10080) : null;
    }

    /** La ligne ne porte que des métadonnées (temps, portions) et rien à conserver. */
    private function isOnlyMetadata(string $line): bool
    {
        $rest = preg_replace(
            ['/\b(?:préparation|prep|cuisson|four|repos|attente|réfrigération|levée|total|pour|portions?|parts?|personnes?|convives?|temps|de|environ)\b/iu', '/\d+\s*(?:h|heures?|min(?:utes?)?|s)?/iu', '/[\s:·•|,.\-–—()]/u'],
            '',
            $line
        );

        return trim((string) $rest) === '';
    }

    private function stripStepNumber(string $line): string
    {
        return preg_replace('/^\s*(?:étape\s*)?\d{1,2}\s*[).:\-–—]\s+/iu', '', $line) ?: $line;
    }

    /**
     * Repli : aucune ligne n'a été vue comme ingrédient (pas de titre de section).
     * Les lignes courtes qui précèdent la première vraie phrase le sont sûrement.
     *
     * @param  list<array{instruction: string, group: string|null}>  $steps
     * @return array{0: list<array>, 1: list<array{instruction: string, group: string|null}>}
     */
    private function rescueIngredients(array $steps): array
    {
        $ingredients = [];

        foreach ($steps as $index => $step) {
            if (! $this->lines->looksLikeIngredient($step['instruction'])) {
                return [$ingredients, array_slice($steps, $index)];
            }

            $parsed = $this->lines->parse($step['instruction']);

            if ($parsed['name'] !== '') {
                $ingredients[] = [...$parsed, 'group' => $step['group']];
            }
        }

        return [$ingredients, []];
    }
}
