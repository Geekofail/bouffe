<?php

namespace App\Services\Recipes;

use App\Livewire\Forms\RecipeForm;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\Tag;
use App\Models\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Historique d'une recette (lot 31, 31.5) : qui a modifié quoi et quand ; revenir à une version.
 *
 * Chaque enregistrement depuis « Modifier » garde le **contenu complet** de la recette après la
 * modification (titre, textes, temps, catégories, ingrédients, étapes, sous-recettes). La première
 * fois qu'une recette déjà existante est modifiée, son état d'avant est gardé comme « version
 * d'origine ». Les photos, les favoris et le partage ne font pas partie des versions.
 *
 * Revenir à une version réécrit la recette et ajoute une nouvelle version : rien n'est perdu.
 */
class RecipeRevisions
{
    /** Versions gardées par recette (les plus anciennes partent au-delà, sauf la version d'origine). */
    public const KEEP = 50;

    public const FIELDS = ['title', 'description', 'servings', 'prep_minutes', 'cook_minutes', 'rest_minutes', 'difficulty', 'source', 'notes'];

    /** @return array<string, mixed> */
    public function snapshot(Recipe $recipe): array
    {
        $recipe->unsetRelation('ingredients')->unsetRelation('steps')->unsetRelation('tags');
        $recipe->load(['ingredients.ingredient', 'ingredients.unit', 'steps', 'tags']);

        return [
            'title' => $recipe->title,
            'description' => $recipe->description,
            'servings' => (int) $recipe->servings,
            'prep_minutes' => $recipe->prep_minutes,
            'cook_minutes' => $recipe->cook_minutes,
            'rest_minutes' => $recipe->rest_minutes,
            'difficulty' => $recipe->difficulty?->value,
            'source' => $recipe->source,
            'notes' => $recipe->notes,
            'tags' => $recipe->tags->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name])->values()->all(),
            'ingredients' => $recipe->ingredients->map(fn ($line) => [
                'ingredient_id' => $line->ingredient_id,
                'name' => (string) $line->ingredient?->name,
                'quantity' => $this->decimal($line->quantity),
                'unit_id' => $line->unit_id,
                'unit' => $line->unit?->label,
                'preparation' => $line->preparation,
                'group_name' => $line->group_name,
                'is_optional' => (bool) $line->is_optional,
            ])->values()->all(),
            'steps' => $recipe->steps->map(fn ($step) => [
                'group_name' => $step->group_name,
                'instruction' => $step->instruction,
            ])->values()->all(),
            'components' => $recipe->components()->with('component:id,title')->get()->map(fn ($component) => [
                'recipe_id' => $component->component_recipe_id,
                'title' => (string) $component->component?->title,
                'quantity' => $component->quantity === null ? null : (float) $component->quantity,
                'note' => $component->note,
            ])->values()->all(),
        ];
    }

    /**
     * À appeler après un enregistrement depuis « Modifier » : $before est le contenu d'avant (null
     * pour une recette nouvelle).
     */
    public function record(Recipe $recipe, ?array $before, string $action = 'edit', ?string $summary = null, ?Carbon $beforeAt = null): ?RecipeRevision
    {
        $after = $this->snapshot($recipe);

        if ($before !== null && ! $recipe->revisions()->exists()) {
            // Première modification depuis le lot 31 : l'état d'avant devient la version d'origine.
            RecipeRevision::create([
                'recipe_id' => $recipe->id,
                'user_id' => $recipe->created_by,
                'action' => 'origin',
                'summary' => 'Version d\'origine',
                'snapshot' => $before,
                'created_at' => $beforeAt ?? $recipe->created_at ?? Carbon::now(),
            ]);
        }

        if ($before !== null && $action === 'edit' && $this->same($before, $after)) {
            return null;   // rien de changé dans le contenu (une case « favori » cochée, par exemple)
        }

        $revision = RecipeRevision::create([
            'recipe_id' => $recipe->id,
            'user_id' => auth()->id(),
            'action' => $before === null ? 'create' : $action,
            'summary' => $summary ?? ($before === null ? 'Création' : $this->describe($before, $after)),
            'snapshot' => $after,
            'created_at' => Carbon::now(),
        ]);

        $this->prune($recipe);

        return $revision;
    }

    /**
     * Ce qui a changé, en mots : « titre, 2 ingrédients ajoutés, 1 modifié, étapes ».
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function describe(array $before, array $after): string
    {
        $labels = [
            'title' => 'titre', 'description' => 'description', 'servings' => 'portions', 'difficulty' => 'difficulté',
            'source' => 'source', 'notes' => 'notes',
        ];
        $parts = [];

        foreach ($labels as $key => $label) {
            if (($before[$key] ?? null) != ($after[$key] ?? null)) {
                $parts[] = $label;
            }
        }

        if ([$before['prep_minutes'] ?? null, $before['cook_minutes'] ?? null, $before['rest_minutes'] ?? null]
            != [$after['prep_minutes'] ?? null, $after['cook_minutes'] ?? null, $after['rest_minutes'] ?? null]) {
            $parts[] = 'temps';
        }

        if (collect($before['tags'] ?? [])->pluck('id')->sort()->values()->all() !== collect($after['tags'] ?? [])->pluck('id')->sort()->values()->all()) {
            $parts[] = 'catégories';
        }

        $old = collect($before['ingredients'] ?? [])->map(fn ($l) => $this->lineText($l));
        $new = collect($after['ingredients'] ?? [])->map(fn ($l) => $this->lineText($l));
        $oldNames = collect($before['ingredients'] ?? [])->pluck('name')->map(fn ($n) => mb_strtolower((string) $n));
        $newNames = collect($after['ingredients'] ?? [])->pluck('name')->map(fn ($n) => mb_strtolower((string) $n));
        $added = $newNames->diff($oldNames)->count();
        $removed = $oldNames->diff($newNames)->count();
        $changed = $new->diff($old)->count() - $added;

        if ($added || $removed || $changed > 0) {
            $parts[] = implode(', ', array_filter([
                $added ? $added.' ingrédient'.($added > 1 ? 's' : '').' ajouté'.($added > 1 ? 's' : '') : null,
                $removed ? $removed.' retiré'.($removed > 1 ? 's' : '') : null,
                $changed > 0 ? $changed.' modifié'.($changed > 1 ? 's' : '') : null,
            ]));
        } elseif ($old->all() !== $new->all()) {
            $parts[] = 'ordre des ingrédients';
        }

        if (($before['steps'] ?? []) !== ($after['steps'] ?? [])) {
            $diff = count($after['steps'] ?? []) - count($before['steps'] ?? []);
            $parts[] = 'étapes'.($diff > 0 ? ' (+'.$diff.')' : ($diff < 0 ? ' ('.$diff.')' : ''));
        }

        if (($before['components'] ?? []) != ($after['components'] ?? [])) {
            $parts[] = 'sous-recettes';
        }

        return $parts === [] ? 'Aucun changement de contenu' : Str::ucfirst(implode(', ', $parts));
    }

    /** Revenir à une version : la recette reprend ce contenu, et une nouvelle version est ajoutée. */
    public function restore(Recipe $recipe, RecipeRevision $revision): Recipe
    {
        if ($recipe->isForeign() || (int) $revision->recipe_id !== (int) $recipe->id) {
            throw new InvalidArgumentException('Cette version n\'appartient pas à la recette.');
        }

        $snapshot = $revision->snapshot;
        $before = $this->snapshot($recipe);
        $beforeAt = $recipe->updated_at?->copy();

        DB::transaction(function () use ($recipe, $snapshot) {
            $title = trim((string) ($snapshot['title'] ?? $recipe->title));

            // Un autre plat a pris ce titre entre-temps : on garde le titre actuel.
            if (Recipe::query()->where('title', $title)->whereKeyNot($recipe->id)->exists()) {
                $title = $recipe->title;
            }

            $recipe->fill([
                'title' => $title,
                'description' => $snapshot['description'] ?? null,
                'servings' => max(1, (int) ($snapshot['servings'] ?? $recipe->servings)),
                'prep_minutes' => $snapshot['prep_minutes'] ?? null,
                'cook_minutes' => $snapshot['cook_minutes'] ?? null,
                'rest_minutes' => $snapshot['rest_minutes'] ?? null,
                'difficulty' => $snapshot['difficulty'] ?? null,
                'source' => $snapshot['source'] ?? null,
                'notes' => $snapshot['notes'] ?? null,
                'updated_by' => auth()->id(),
            ])->save();

            $recipe->ingredients()->delete();

            foreach (array_values($snapshot['ingredients'] ?? []) as $order => $line) {
                $ingredient = $this->ingredientFor($line);

                if (! $ingredient) {
                    continue;
                }

                $recipe->ingredients()->create([
                    'ingredient_id' => $ingredient->id,
                    'quantity' => $line['quantity'],
                    'unit_id' => $line['unit_id'] && Unit::query()->whereKey($line['unit_id'])->exists() ? $line['unit_id'] : null,
                    'preparation' => $line['preparation'] ?? null,
                    'group_name' => $line['group_name'] ?? null,
                    'is_optional' => (bool) ($line['is_optional'] ?? false),
                    'sort_order' => $order + 1,
                ]);
            }

            // Lot 40 : chaque étape reprend l'identifiant de l'étape au même rang (notes et photos restent).
            $uids = $recipe->steps()->orderBy('position')->orderBy('id')->pluck('uid')->all();
            $recipe->steps()->delete();

            foreach (array_values($snapshot['steps'] ?? []) as $position => $step) {
                $recipe->steps()->create([
                    'uid' => $uids[$position] ?? null,
                    'position' => $position + 1,
                    'group_name' => $step['group_name'] ?? null,
                    'instruction' => (string) $step['instruction'],
                ]);
            }

            app(StepNotes::class)->syncPhotos($recipe);

            $tagIds = Tag::query()->whereIn('id', collect($snapshot['tags'] ?? [])->pluck('id'))->pluck('id')->all();
            $recipe->tags()->sync($tagIds);

            $subRecipes = app(SubRecipes::class);
            $components = collect($snapshot['components'] ?? [])
                ->filter(function (array $row) use ($recipe, $subRecipes) {
                    $candidate = Recipe::query()->find((int) $row['recipe_id']);

                    return $candidate && $subRecipes->refusal($recipe, $candidate) === null;
                })
                ->map(fn (array $row) => ['recipe_id' => (int) $row['recipe_id'], 'quantity' => $row['quantity'] ?? 1, 'note' => (string) ($row['note'] ?? '')])
                ->values()->all();
            $subRecipes->sync($recipe, $components);
        });

        $date = $revision->created_at?->locale('fr')->isoFormat('D MMM YYYY [à] HH:mm');
        $this->record($recipe->refresh(), $before, 'restore', 'Retour à la version du '.$date, $beforeAt);

        return $recipe;
    }

    /** Ingrédient d'une ligne gardée : le même s'il existe encore, sinon par son nom, sinon recréé. */
    private function ingredientFor(array $line): ?Ingredient
    {
        if ($line['ingredient_id'] && ($ingredient = Ingredient::query()->find($line['ingredient_id']))) {
            return $ingredient;
        }

        $name = trim((string) ($line['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        return Ingredient::findByName($name) ?? Ingredient::create([
            'name' => Str::ucfirst($name),
            'aisle_id' => RecipeForm::defaultAisleId(),
        ]);
    }

    private function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = (string) $value;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    private function lineText(array $line): string
    {
        return implode('|', [mb_strtolower((string) $line['name']), $line['quantity'] ?? '', $line['unit_id'] ?? '', $line['preparation'] ?? '', $line['group_name'] ?? '', (int) ($line['is_optional'] ?? 0)]);
    }

    /** Même contenu ? (MySQL range les clés d'un JSON à sa façon : on compare clés triées.) */
    public function same(array $a, array $b): bool
    {
        return json_encode($this->sorted($a)) === json_encode($this->sorted($b));
    }

    private function sorted(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? $this->sorted($item) : $item, $value);
    }

    private function prune(Recipe $recipe): void
    {
        $keep = RecipeRevision::query()->where('recipe_id', $recipe->id)->where('action', '!=', 'origin')
            ->orderByDesc('id')->limit(self::KEEP)->pluck('id');

        RecipeRevision::query()->where('recipe_id', $recipe->id)->where('action', '!=', 'origin')
            ->whereNotIn('id', $keep)->delete();
    }
}
