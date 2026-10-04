<?php

namespace App\Services\Linked;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Scopes\HouseholdScope;
use App\Models\Tag;
use App\Models\User;
use App\Services\IngredientLineFormatter;
use App\Services\RecipePhotoService;
use Illuminate\Support\Facades\DB;

/**
 * Copie d'une recette d'un foyer relié dans son propre carnet (26.2, règle R31).
 *
 *  - reprend titre, description, portions, temps, difficulté, ingrédients (catalogue commun : rien
 *    à convertir), étapes, photo et sous-recettes (copiées aussi, ou reprises si déjà copiées) ;
 *  - garde l'origine (recette, foyer, date) et une empreinte du contenu de l'original ;
 *  - quand l'original change, la copie propose de voir les différences et d'en reprendre une partie,
 *    sans jamais écraser d'elle-même les modifications locales ;
 *  - un original supprimé ou refermé ne touche pas aux copies.
 *
 * Toujours appelé dans le foyer actif (celui qui copie).
 */
class RecipeCopier
{
    public const SECTIONS = ['general' => 'Titre, portions, temps', 'ingredients' => 'Ingrédients', 'steps' => 'Étapes'];

    public function __construct(
        private readonly RecipePhotoService $photos,
        private readonly IngredientLineFormatter $formatter,
    ) {}

    /** Copie déjà faite par le foyer actif (non archivée). */
    public function existingCopy(Recipe $source): ?Recipe
    {
        return Recipe::query()->where('origin_recipe_id', $source->id)->whereNull('archived_at')->first();
    }

    public function copy(Recipe $source, ?User $user = null): Recipe
    {
        return DB::transaction(fn () => $this->copyRecipe($source, $user, 0));
    }

    private function copyRecipe(Recipe $source, ?User $user, int $depth): Recipe
    {
        $source->loadMissing(['ingredients', 'steps', 'tags', 'household']);

        $copy = new Recipe([
            'title' => $this->uniqueTitle($source->title, (string) $source->household?->name),
            'description' => $source->description,
            'servings' => $source->servings,
            'prep_minutes' => $source->prep_minutes,
            'cook_minutes' => $source->cook_minutes,
            'rest_minutes' => $source->rest_minutes,
            'difficulty' => $source->difficulty,
            'source' => $source->source,
            'visibility' => 'private',
            'created_by' => $user?->id,
            'updated_by' => $user?->id,
            'origin_recipe_id' => $source->id,
            'origin_household_id' => $source->household_id,
            'origin_synced_at' => now(),
            'origin_hash' => $this->hash($source),
        ]);
        $copy->photo_path = $source->photo_path ? $this->photos->copy($source->photo_path) : null;
        $copy->save();

        $this->copyIngredients($source, $copy);
        $this->copySteps($source, $copy);

        // Catégories : celles du même nom dans notre foyer (les catégories sont propres à chaque foyer).
        $names = $source->tags->pluck('name')->all();
        $copy->tags()->sync($names === [] ? [] : Tag::query()->whereIn('name', $names)->pluck('id'));

        // Sous-recettes : reprises si déjà copiées, copiées sinon (elles doivent être dans notre carnet).
        foreach ($source->components()->with('component')->get() as $component) {
            $sub = $component->component;

            if (! $sub) {
                continue;
            }

            $mine = $this->existingCopy($sub) ?? ($depth < 3 ? $this->copyRecipe($sub, $user, $depth + 1) : null);

            if ($mine) {
                $copy->components()->create(['component_recipe_id' => $mine->id] + $component->only(['quantity', 'note', 'sort_order']));
            }
        }

        return $copy;
    }

    /* ================================================================ Mises à jour de l'original (R31) */

    /** L'original a-t-il changé depuis la copie (ou la dernière reprise) ? */
    public function hasUpdate(Recipe $copy): bool
    {
        $origin = $this->origin($copy);

        return $origin !== null && $copy->origin_hash !== null && $this->hash($origin) !== $copy->origin_hash;
    }

    /** Original encore lisible par le foyer actif (sinon : rien à proposer). */
    public function origin(Recipe $copy): ?Recipe
    {
        if (! $copy->origin_recipe_id) {
            return null;
        }

        $origin = Recipe::query()->withoutGlobalScope(HouseholdScope::class)->find($copy->origin_recipe_id);

        return $origin && ! $origin->isArchived() && app(SharedRecipes::class)->canView($origin) ? $origin : null;
    }

    /**
     * Différences section par section.
     *
     * @return array<string, array{changed: bool, mine: list<string>, theirs: list<string>}>
     */
    public function diff(Recipe $copy): array
    {
        $origin = $this->origin($copy) ?? throw new \InvalidArgumentException('L\'original n\'est plus disponible.');

        $result = [];

        foreach (array_keys(self::SECTIONS) as $section) {
            $mine = $this->lines($copy, $section);
            $theirs = $this->lines($origin, $section);
            $result[$section] = ['changed' => $mine !== $theirs, 'mine' => $mine, 'theirs' => $theirs];
        }

        return $result;
    }

    /**
     * Reprend les sections choisies de l'original ; les autres restent telles quelles.
     *
     * @param  list<string>  $sections
     */
    public function apply(Recipe $copy, array $sections): void
    {
        $origin = $this->origin($copy) ?? throw new \InvalidArgumentException('L\'original n\'est plus disponible.');

        DB::transaction(function () use ($copy, $origin, $sections) {
            if (in_array('general', $sections, true)) {
                $copy->fill($origin->only(['description', 'servings', 'prep_minutes', 'cook_minutes', 'rest_minutes', 'difficulty']));

                if ($origin->title !== $copy->title && ! Recipe::query()->where('title', $origin->title)->whereKeyNot($copy->id)->exists()) {
                    $copy->title = $origin->title;
                }
            }

            if (in_array('ingredients', $sections, true)) {
                $copy->ingredients()->delete();
                $this->copyIngredients($origin, $copy);
            }

            if (in_array('steps', $sections, true)) {
                $copy->steps()->delete();
                $this->copySteps($origin, $copy);
            }

            $copy->forceFill(['origin_hash' => $this->hash($origin), 'origin_synced_at' => now()])->save();
        });
    }

    /** « Ne rien reprendre » : la copie ne signale plus cette version de l'original. */
    public function dismiss(Recipe $copy): void
    {
        if ($origin = $this->origin($copy)) {
            $copy->forceFill(['origin_hash' => $this->hash($origin), 'origin_synced_at' => now()])->save();
        }
    }

    /** Empreinte du contenu d'une recette : ce que R31 compare. */
    public function hash(Recipe $recipe): string
    {
        return sha1(json_encode([
            $this->lines($recipe, 'general'),
            $this->lines($recipe, 'ingredients'),
            $this->lines($recipe, 'steps'),
        ], JSON_UNESCAPED_UNICODE));
    }

    /** @return list<string> */
    private function lines(Recipe $recipe, string $section): array
    {
        return match ($section) {
            'general' => array_values(array_filter([
                'Titre : '.$recipe->title,
                'Portions : '.$recipe->servings,
                $recipe->prep_minutes ? 'Préparation : '.$recipe->prep_minutes.' min' : null,
                $recipe->cook_minutes ? 'Cuisson : '.$recipe->cook_minutes.' min' : null,
                $recipe->rest_minutes ? 'Repos : '.$recipe->rest_minutes.' min' : null,
                $recipe->difficulty ? 'Difficulté : '.$recipe->difficulty->label() : null,
                $recipe->description ? 'Description : '.trim($recipe->description) : null,
            ])),
            'ingredients' => $recipe->ingredients()->with('ingredient', 'unit')->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (RecipeIngredient $line) => trim(($line->group_name ? '['.$line->group_name.'] ' : '')
                    .($line->ingredient ? $this->formatter->format($line->quantity !== null ? (float) $line->quantity : null, $line->unit, $line->ingredient)['text'] : '')
                    .($line->preparation ? ', '.$line->preparation : '').($line->is_optional ? ' (facultatif)' : '')))
                ->all(),
            'steps' => $recipe->steps()->orderBy('position')->get()
                ->map(fn ($step) => trim(($step->group_name ? '['.$step->group_name.'] ' : '').$step->instruction))
                ->all(),
        };
    }

    private function copyIngredients(Recipe $from, Recipe $to): void
    {
        foreach ($from->ingredients()->orderBy('sort_order')->orderBy('id')->get() as $line) {
            $to->ingredients()->create($line->only(['ingredient_id', 'quantity', 'unit_id', 'preparation', 'group_name', 'is_optional', 'sort_order']));
        }
    }

    private function copySteps(Recipe $from, Recipe $to): void
    {
        foreach ($from->steps()->orderBy('position')->get() as $step) {
            $to->steps()->create($step->only(['position', 'group_name', 'instruction']));
        }
    }

    /** Le titre est unique dans un foyer : « Tarte (Pierre et Monique) » si « Tarte » existe déjà. */
    private function uniqueTitle(string $title, string $householdName): string
    {
        if (! Recipe::query()->where('title', $title)->exists()) {
            return $title;
        }

        $base = mb_substr($title, 0, 150).' ('.mb_substr($householdName ?: 'proches', 0, 40).')';
        $candidate = $base;
        $i = 2;

        while (Recipe::query()->where('title', $candidate)->exists()) {
            $candidate = $base.' '.$i++;
        }

        return $candidate;
    }
}
