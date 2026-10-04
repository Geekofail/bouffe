<?php

namespace App\Services\Recipes;

use App\Enums\Difficulty;
use App\Livewire\Forms\RecipeForm;
use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\Unit;
use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Export et import des recettes au format JSON (lot 13.10).
 *
 * Sert à emporter son carnet ailleurs, à le partager, ou à le remettre en place après
 * une réinstallation. Les photos ne sont pas incluses (c'est le rôle des sauvegardes).
 */
class RecipeArchive
{
    public const FORMAT = 'bouffe-recettes';

    public const VERSION = 1;

    /* ================================================================ Export */

    /** @param Collection<int, Recipe>|null $recipes toutes les recettes actives par défaut */
    public function export(?Collection $recipes = null): array
    {
        $recipes ??= Recipe::query()->whereNull('archived_at')->orderBy('title')->get();
        $recipes->load(['ingredients.ingredient', 'ingredients.unit', 'steps', 'tags']);

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'recipes' => $recipes->map(fn (Recipe $recipe) => $this->recipeToArray($recipe))->values()->all(),
        ];
    }

    public function filename(): string
    {
        return 'bouffe-recettes-'.now()->format('Y-m-d').'.json';
    }

    private function recipeToArray(Recipe $recipe): array
    {
        return [
            'title' => $recipe->title,
            'description' => $recipe->description,
            'servings' => $recipe->servings,
            'prep_minutes' => $recipe->prep_minutes,
            'cook_minutes' => $recipe->cook_minutes,
            'rest_minutes' => $recipe->rest_minutes,
            'difficulty' => $recipe->difficulty?->value,
            'source' => $recipe->source,
            'notes' => $recipe->notes,
            'is_favorite' => $recipe->is_favorite,
            'tags' => $recipe->tags->pluck('name')->values()->all(),
            'ingredients' => $recipe->ingredients->map(fn ($line) => [
                'name' => $line->ingredient->name,
                'quantity' => $line->quantity === null ? null : (float) $line->quantity,
                'unit' => $line->unit?->code,
                'preparation' => $line->preparation,
                'group' => $line->group_name,
                'optional' => (bool) $line->is_optional,
            ])->values()->all(),
            'steps' => $recipe->steps->map(fn ($step) => [
                'group' => $step->group_name,
                'instruction' => $step->instruction,
            ])->values()->all(),
            // Sous-recettes (13.8), retrouvées par leur titre à l'import.
            'components' => $recipe->components()->with('component')->get()->filter(fn ($c) => $c->component)->map(fn ($c) => [
                'title' => $c->component->title,
                'quantity' => (float) $c->quantity,
                'note' => $c->note,
            ])->values()->all(),
        ];
    }

    /* ================================================================ Import */

    /**
     * @return array{created: list<string>, skipped: list<string>}
     *
     * @throws InvalidArgumentException fichier illisible ou d'un autre format
     */
    public function import(string $json): array
    {
        $payload = json_decode($json, true);

        if (! is_array($payload) || ($payload['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException('Ce fichier n\'est pas un export de recettes Bouffe.');
        }

        if ((int) ($payload['version'] ?? 0) > self::VERSION) {
            throw new InvalidArgumentException('Ce fichier vient d\'une version plus récente de Bouffe.');
        }

        $recipes = $payload['recipes'] ?? null;

        if (! is_array($recipes) || $recipes === []) {
            throw new InvalidArgumentException('Ce fichier ne contient aucune recette.');
        }

        $created = [];
        $skipped = [];
        $pending = [];

        foreach ($recipes as $data) {
            if (! is_array($data) || ! filled($data['title'] ?? null)) {
                continue;
            }

            $title = Str::limit(trim((string) $data['title']), 200, '');

            if (Recipe::query()->where('search_title', NameNormalizer::normalize($title))->exists()) {
                $skipped[] = $title;

                continue;
            }

            $recipe = DB::transaction(fn () => $this->createRecipe($title, $data));
            $created[] = $title;

            if (! empty($data['components']) && is_array($data['components'])) {
                $pending[] = [$recipe, $data['components']];
            }
        }

        // Sous-recettes : reliées une fois toutes les recettes créées (l'ordre du fichier n'importe pas).
        foreach ($pending as [$recipe, $components]) {
            $rows = [];

            foreach ($components as $component) {
                $sub = is_array($component) ? Recipe::query()->where('search_title', NameNormalizer::normalize((string) ($component['title'] ?? '')))->first() : null;

                if ($sub && ! app(SubRecipes::class)->refusal($recipe, $sub)) {
                    $rows[] = ['recipe_id' => $sub->id, 'quantity' => (float) ($component['quantity'] ?? 1) ?: 1, 'note' => (string) ($component['note'] ?? '')];
                }
            }

            try {
                app(SubRecipes::class)->sync($recipe, $rows);
            } catch (InvalidArgumentException) {
                // sous-recette incohérente dans le fichier : la recette reste importée sans elle
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    private function createRecipe(string $title, array $data): Recipe
    {
        $recipe = Recipe::create([
            'title' => $title,
            'description' => $this->string($data['description'] ?? null, 2000),
            'servings' => max(1, min(50, (int) ($data['servings'] ?? 2))),
            'prep_minutes' => $this->minutes($data['prep_minutes'] ?? null, 2880),
            'cook_minutes' => $this->minutes($data['cook_minutes'] ?? null, 2880),
            'rest_minutes' => $this->minutes($data['rest_minutes'] ?? null, 10080),
            'difficulty' => Difficulty::tryFrom((string) ($data['difficulty'] ?? ''))?->value,
            'source' => $this->string($data['source'] ?? null, 500),
            'notes' => $this->string($data['notes'] ?? null, 5000),
            'is_favorite' => (bool) ($data['is_favorite'] ?? false),
            'is_to_test' => true,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]);

        $order = 1;

        foreach ($data['ingredients'] ?? [] as $line) {
            $name = trim((string) ($line['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $recipe->ingredients()->create([
                'ingredient_id' => $this->ingredient($name)->id,
                'quantity' => isset($line['quantity']) && is_numeric($line['quantity']) ? (float) $line['quantity'] : null,
                'unit_id' => $this->unit($line['unit'] ?? null)?->id,
                'preparation' => $this->string($line['preparation'] ?? null, 150),
                'group_name' => $this->string($line['group'] ?? null, 80),
                'is_optional' => (bool) ($line['optional'] ?? false),
                'sort_order' => $order++,
            ]);
        }

        $position = 1;

        foreach ($data['steps'] ?? [] as $step) {
            $instruction = trim((string) (is_array($step) ? ($step['instruction'] ?? '') : $step));

            if ($instruction === '') {
                continue;
            }

            $recipe->steps()->create([
                'position' => $position++,
                'group_name' => is_array($step) ? $this->string($step['group'] ?? null, 80) : null,
                'instruction' => Str::limit($instruction, 5000, ''),
            ]);
        }

        $tagIds = collect($data['tags'] ?? [])
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->map(fn (string $name) => $this->tag($name)->id)
            ->unique()->values()->all();

        $recipe->tags()->sync($tagIds);

        return $recipe;
    }

    private function tag(string $name): Tag
    {
        $normalized = NameNormalizer::normalize($name);
        $existing = Tag::query()->get(['id', 'name'])->first(fn (Tag $tag) => NameNormalizer::normalize($tag->name) === $normalized);

        return $existing ?? Tag::create(['name' => Str::limit(trim($name), 60, '')]);
    }

    private function ingredient(string $name): Ingredient
    {
        return Ingredient::findByName($name) ?? Ingredient::create([
            'name' => Str::ucfirst(Str::limit($name, 150, '')),
            'aisle_id' => RecipeForm::defaultAisleId() ?? Aisle::query()->value('id'),
        ]);
    }

    private function unit(mixed $code): ?Unit
    {
        return is_string($code) && $code !== '' ? Unit::query()->where('code', $code)->first() : null;
    }

    private function string(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : Str::limit($value, $max, '');
    }

    private function minutes(mixed $value, int $max): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? min((int) $value, $max) : null;
    }
}
