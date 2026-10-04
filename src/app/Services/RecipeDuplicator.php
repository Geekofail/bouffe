<?php

namespace App\Services;

use App\Models\Recipe;
use Illuminate\Support\Facades\DB;

/**
 * Duplique une recette (variante) : ingrédients, étapes, catégories et photo.
 * Les notes des personnes et le statut favori/archivé ne sont pas copiés.
 */
class RecipeDuplicator
{
    public function __construct(private readonly RecipePhotoService $photos) {}

    public function duplicate(Recipe $source, ?int $userId = null): Recipe
    {
        return DB::transaction(function () use ($source, $userId) {
            $source->loadMissing(['ingredients', 'steps', 'tags']);

            $copy = $source->replicate(['slug', 'search_title', 'is_favorite', 'archived_at', 'photo_path', 'created_by', 'updated_by']);
            $copy->title = $this->copyTitle($source->title);
            $copy->is_favorite = false;
            $copy->archived_at = null;
            $copy->created_by = $userId;
            $copy->updated_by = $userId;
            $copy->photo_path = $source->photo_path ? $this->photos->copy($source->photo_path) : null;
            $copy->save();

            foreach ($source->ingredients as $line) {
                $copy->ingredients()->create($line->only(['ingredient_id', 'quantity', 'unit_id', 'preparation', 'group_name', 'is_optional', 'sort_order']));
            }

            foreach ($source->steps as $step) {
                $copy->steps()->create($step->only(['position', 'instruction']));
            }

            $copy->tags()->sync($source->tags->pluck('id'));

            foreach ($source->components()->get() as $component) {
                $copy->components()->create($component->only(['component_recipe_id', 'quantity', 'note', 'sort_order']));
            }

            return $copy;
        });
    }

    private function copyTitle(string $title): string
    {
        $base = mb_substr($title, 0, 185).' (copie)';
        $candidate = $base;
        $i = 2;

        while (Recipe::where('title', $candidate)->exists()) {
            $candidate = mb_substr($title, 0, 180)." (copie {$i})";
            $i++;
        }

        return $candidate;
    }
}
