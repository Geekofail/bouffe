<?php

namespace App\Services\Recipes;

use App\Models\Recipe;
use App\Models\Tag;
use App\Support\NameNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Import d'une recette depuis une URL (règle R13).
 *
 * La page est lue côté serveur, on y cherche les données structurées `schema.org/Recipe`
 * (JSON-LD, puis microdonnées). Rien n'est enregistré : le résultat est une « ébauche »
 * relue et corrigée dans l'écran d'import.
 */
class RecipeImporter
{
    public const TIMEOUT = 10;

    public const MAX_BYTES = 5_000_000;

    public const MAX_IMAGE_BYTES = 8_000_000;

    public function __construct(
        private readonly IngredientLineParser $lines,
        private readonly RecipeTextParser $texts,
    ) {}

    /**
     * @return array{
     *   title: string, description: string, servings: int, prep_minutes: int|null, cook_minutes: int|null,
     *   rest_minutes: int|null, source: string, image: string|null, tagIds: list<int>, tagNames: list<string>,
     *   ingredients: list<array>, steps: list<array{instruction: string, group: string|null}>, existing: Recipe|null
     * }
     *
     * @throws InvalidArgumentException adresse invalide, page illisible ou sans recette
     */
    public function fromUrl(string $url): array
    {
        return $this->draftFromSchema($this->fetchRecipeData($url), trim($url));
    }

    /**
     * Données de recette (schema.org) trouvées sur une page : ce que garde « À trier » (lot 38), qui
     * reconstruit l'ébauche au moment de la relecture.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException adresse invalide, page illisible ou sans recette
     */
    public function fetchRecipeData(string $url): array
    {
        $url = trim($url);
        $this->ensureSafeUrl($url);

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withHeaders(['User-Agent' => $this->userAgent(), 'Accept' => 'text/html,application/xhtml+xml'])
                ->withOptions(['allow_redirects' => ['max' => 3, 'strict' => true]])
                ->get($url);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Page injoignable : vérifiez l\'adresse et la connexion Internet.');
        }

        if (! $response->successful()) {
            throw new InvalidArgumentException("Le site a répondu « {$response->status()} » : la page n'a pas pu être lue.");
        }

        $html = substr($response->body(), 0, self::MAX_BYTES);
        $data = $this->findRecipeData($html);

        if (! $data) {
            throw new InvalidArgumentException('Aucune recette reconnue sur cette page. Copiez le texte de la recette et utilisez « Coller du texte ».');
        }

        return $data;
    }

    /** Ébauche construite à partir d'un texte collé (13.2, règle R14). */
    public function fromText(string $text): array
    {
        return $this->texts->parse($text);
    }

    /**
     * Ébauche à partir d'un brouillon proposé par l'assistant (lot 33) : mêmes lignes analysées que
     * pour un texte collé, relues dans le même écran avant enregistrement.
     *
     * @param  array{title?: string, description?: string, servings?: int, prep_minutes?: int|null, cook_minutes?: int|null, rest_minutes?: int|null, lines?: list<string>, steps?: list<string>, source?: string}  $pending
     */
    public function fromAssistant(array $pending): array
    {
        $title = Str::limit(Str::squish((string) ($pending['title'] ?? '')), 200, '') ?: 'Recette proposée par l\'assistant';

        return [
            'title' => $title,
            'description' => Str::limit(Str::squish((string) ($pending['description'] ?? '')), 2000, ''),
            'servings' => max(1, min(50, (int) ($pending['servings'] ?? config('bouffe.default_servings', 2)))),
            'prep_minutes' => $pending['prep_minutes'] ?? null,
            'cook_minutes' => $pending['cook_minutes'] ?? null,
            'rest_minutes' => $pending['rest_minutes'] ?? null,
            'source' => Str::limit((string) ($pending['source'] ?? ''), 250, ''),
            'image' => null,
            'tagIds' => [],
            'tagNames' => [],
            'ingredients' => collect($pending['lines'] ?? [])
                ->map(fn ($line) => [...$this->lines->parse((string) $line), 'group' => null])
                ->filter(fn (array $line) => $line['name'] !== '')
                ->values()->all(),
            'steps' => collect($pending['steps'] ?? [])
                ->map(fn ($step) => Str::squish((string) $step))->filter()
                ->map(fn ($step) => ['instruction' => $step, 'group' => null])
                ->values()->all(),
            'existing' => Recipe::query()->where('search_title', NameNormalizer::normalize($title))->first(),
        ];
    }

    /**
     * Télécharge la photo de la recette dans un fichier temporaire, prêt pour RecipePhotoService.
     * Renvoie null si l'image est injoignable, trop grosse ou d'un autre type : une recette sans
     * photo vaut mieux qu'un import raté.
     */
    public function downloadImage(?string $url): ?UploadedFile
    {
        if (! $url) {
            return null;
        }

        try {
            $this->ensureSafeUrl($url);
            $response = Http::timeout(self::TIMEOUT)->withHeaders(['User-Agent' => $this->userAgent()])->get($url);

            if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
                return null;
            }

            $body = $response->body();

            if (strlen($body) < 1024 || strlen($body) > self::MAX_IMAGE_BYTES || @imagecreatefromstring($body) === false) {
                return null;
            }

            $path = tempnam(sys_get_temp_dir(), 'bouffe-photo-');
            file_put_contents($path, $body);

            return new UploadedFile($path, 'photo.jpg', $response->header('Content-Type'), null, true);
        } catch (\Throwable) {
            return null;
        }
    }

    /* ================================================================ Lecture de la page */

    /** @return array<string, mixed>|null objet schema.org/Recipe trouvé dans la page */
    public function findRecipeData(string $html): ?array
    {
        foreach ($this->jsonLdBlocks($html) as $block) {
            if ($recipe = $this->findRecipeNode($block)) {
                return $recipe;
            }
        }

        return $this->fromMicrodata($html);
    }

    /** @return list<mixed> */
    private function jsonLdBlocks(string $html): array
    {
        preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $json) => json_decode(trim(html_entity_decode($json, ENT_QUOTES | ENT_HTML5)), true))
            ->filter()
            ->values()->all();
    }

    private function findRecipeNode(mixed $node): ?array
    {
        if (! is_array($node)) {
            return null;
        }

        $types = Arr::wrap($node['@type'] ?? []);

        if (in_array('Recipe', array_map(fn ($t) => is_string($t) ? $t : '', $types), true)) {
            return $node;
        }

        foreach ($node as $child) {
            if (is_array($child) && ($found = $this->findRecipeNode($child))) {
                return $found;
            }
        }

        return null;
    }

    /** Microdonnées (itemprop) : repli minimal quand il n'y a pas de JSON-LD. */
    private function fromMicrodata(string $html): ?array
    {
        if (! preg_match('#itemtype=["\']https?://schema.org/Recipe["\']#i', $html)) {
            return null;
        }

        $value = function (string $property) use ($html): ?string {
            if (preg_match('#<meta[^>]*itemprop=["\']'.$property.'["\'][^>]*content=["\']([^"\']*)["\']#i', $html, $m)) {
                return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
            }

            if (preg_match('#itemprop=["\']'.$property.'["\'][^>]*>(.*?)</#is', $html, $m)) {
                return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
            }

            return null;
        };

        $all = function (string $property) use ($html): array {
            preg_match_all('#itemprop=["\']'.$property.'["\'][^>]*>(.*?)</#is', $html, $m);

            return collect($m[1] ?? [])->map(fn ($t) => trim(html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5)))->filter()->values()->all();
        };

        return array_filter([
            'name' => $value('name'),
            'recipeYield' => $value('recipeYield'),
            'prepTime' => $value('prepTime'),
            'cookTime' => $value('cookTime'),
            'totalTime' => $value('totalTime'),
            'recipeIngredient' => $all('recipeIngredient') ?: $all('ingredients'),
            'recipeInstructions' => $all('recipeInstructions'),
            'image' => $value('image'),
            'description' => $value('description'),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /* ================================================================ Correspondances (R13) */

    /** @param array<string, mixed> $data */
    public function draftFromSchema(array $data, string $url): array
    {
        $prep = $this->minutes($data['prepTime'] ?? null);
        $cook = $this->minutes($data['cookTime'] ?? null);
        $total = $this->minutes($data['totalTime'] ?? null);
        $rest = $total !== null ? max(0, $total - (int) $prep - (int) $cook) : null;

        $ingredients = collect(Arr::wrap($data['recipeIngredient'] ?? $data['ingredients'] ?? []))
            ->map(fn ($line) => is_string($line) ? $this->text($line) : '')
            ->filter()
            ->map(fn (string $line) => [...$this->lines->parse($line), 'group' => null])
            ->filter(fn (array $line) => $line['name'] !== '')
            ->values()->all();

        $tagNames = collect([$data['recipeCategory'] ?? [], $data['recipeCuisine'] ?? [], $data['keywords'] ?? []])
            ->flatMap(fn ($value) => is_string($value) ? explode(',', $value) : Arr::wrap($value))
            ->map(fn ($name) => is_string($name) ? trim($name) : '')
            ->filter()->unique()->take(12)->values()->all();

        $title = $this->text($data['name'] ?? '') ?: 'Recette importée';

        return [
            'title' => Str::limit($title, 200, ''),
            'description' => Str::limit($this->text($data['description'] ?? ''), 2000, ''),
            'servings' => $this->servings($data['recipeYield'] ?? null),
            'prep_minutes' => $prep,
            'cook_minutes' => $cook,
            'rest_minutes' => $rest ?: null,
            'source' => $this->text($data['url'] ?? '') ?: $url,
            'image' => $this->image($data['image'] ?? null, $url),
            'tagIds' => $this->matchTags($tagNames),
            'tagNames' => $tagNames,
            'ingredients' => $ingredients,
            'steps' => $this->instructions($data['recipeInstructions'] ?? []),
            'existing' => $this->findExisting($url, $title),
        ];
    }

    /** Durée ISO 8601 (« PT1H15M ») ou texte (« 15 min ») en minutes. */
    public function minutes(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = Arr::first($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        if (preg_match('/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?/i', trim($value), $m) && (isset($m[1]) || isset($m[2]) || isset($m[3]))) {
            $minutes = ((int) ($m[1] ?? 0)) * 1440 + ((int) ($m[2] ?? 0)) * 60 + (int) ($m[3] ?? 0);

            if ($minutes > 0) {
                return min($minutes, 10080);
            }
        }

        if (preg_match('/(\d+)\s*h(?:\s*(\d+))?/i', $value, $m)) {
            return (int) $m[1] * 60 + (int) ($m[2] ?? 0);
        }

        if (preg_match('/(\d+)\s*(?:min|m\b)/i', $value, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    public function servings(mixed $value): int
    {
        foreach (Arr::wrap($value) as $candidate) {
            if (is_numeric($candidate)) {
                return max(1, min(50, (int) $candidate));
            }

            if (is_string($candidate) && preg_match('/(\d+)/', $candidate, $m)) {
                return max(1, min(50, (int) $m[1]));
            }
        }

        return (int) config('bouffe.default_servings', 2);
    }

    /** @return list<array{instruction: string, group: string|null}> */
    public function instructions(mixed $value, ?string $group = null): array
    {
        if (is_string($value)) {
            // Les blocs en un seul texte séparent leurs étapes par des retours à la ligne ou des balises.
            $text = html_entity_decode(strip_tags(preg_replace('#</(?:li|p|div)>|<br\s*/?>#i', "\n", $value) ?? $value), ENT_QUOTES | ENT_HTML5);

            return collect(preg_split('/\n+|(?<=[.!?])\s{2,}/u', $text))
                ->map(fn ($step) => Str::squish($step))
                ->filter(fn ($step) => mb_strlen($step) > 2)
                ->map(fn ($step) => ['instruction' => $step, 'group' => $group])
                ->values()->all();
        }

        $steps = [];

        foreach (Arr::wrap($value) as $item) {
            if (is_string($item)) {
                $steps = [...$steps, ...$this->instructions($item, $group)];

                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            $type = Arr::wrap($item['@type'] ?? []);

            if (in_array('HowToSection', $type, true)) {
                $steps = [...$steps, ...$this->instructions($item['itemListElement'] ?? [], $this->text($item['name'] ?? '') ?: null)];

                continue;
            }

            $text = $this->text($item['text'] ?? $item['name'] ?? '');

            if ($text !== '') {
                $steps[] = ['instruction' => $text, 'group' => $group];
            }
        }

        return $steps;
    }

    /** @param list<string> $names */
    public function matchTags(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $normalized = array_map(fn (string $name) => NameNormalizer::normalize($name), $names);

        return Tag::query()->get(['id', 'name'])
            ->filter(fn (Tag $tag) => in_array(NameNormalizer::normalize($tag->name), $normalized, true))
            ->pluck('id')->values()->all();
    }

    private function image(mixed $value, string $baseUrl): ?string
    {
        $candidates = collect(Arr::wrap($value))
            ->map(fn ($item) => is_array($item) ? ($item['url'] ?? Arr::first($item)) : $item)
            ->filter(fn ($item) => is_string($item) && $item !== '')
            ->values();

        $image = $candidates->first();

        if (! is_string($image)) {
            return null;
        }

        if (str_starts_with($image, '//')) {
            $image = 'https:'.$image;
        } elseif (str_starts_with($image, '/')) {
            $image = rtrim(parse_url($baseUrl, PHP_URL_SCHEME).'://'.parse_url($baseUrl, PHP_URL_HOST), '/').$image;
        }

        return filter_var($image, FILTER_VALIDATE_URL) ? $image : null;
    }

    private function findExisting(string $url, string $title): ?Recipe
    {
        return Recipe::query()->where('source', $url)->first()
            ?? Recipe::query()->where('search_title', NameNormalizer::normalize($title))->first();
    }

    private function userAgent(): string
    {
        return 'Bouffe/'.config('bouffe.version', '1.0').' (application personnelle)';
    }

    private function text(mixed $value): string
    {
        if (is_array($value)) {
            $value = Arr::first($value);
        }

        return Str::squish(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5));
    }

    /** Adresse publique en http(s) uniquement : pas de fichier local ni de machine du réseau. */
    private function ensureSafeUrl(string $url): void
    {
        $parts = parse_url($url);

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Adresse invalide : elle doit commencer par https://');
        }

        $host = $parts['host'] ?? '';
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if ($host === '' || in_array(strtolower($host), ['localhost'], true)
            || (filter_var($ip, FILTER_VALIDATE_IP) && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            throw new InvalidArgumentException('Seules les adresses publiques peuvent être importées.');
        }
    }
}
