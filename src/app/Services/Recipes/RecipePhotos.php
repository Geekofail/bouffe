<?php

namespace App\Services\Recipes;

use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\RecipePhoto;
use App\Services\RecipePhotoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Plusieurs photos par recette (lot 31, 31.2).
 *
 *  - la photo **principale** reste `recipes.photo_path` (cartes, fiche, impression) ;
 *  - des photos d'**étapes**, montrées dans le mode cuisine à la bonne étape ;
 *  - « **notre version** », prise après le repas (proposée quand on marque le repas mangé).
 *
 * Les fichiers vont dans `recipes/photos/` : ils suivent les sauvegardes et l'export du foyer.
 */
class RecipePhotos
{
    public const MAX_PER_RECIPE = 40;

    public const FOLDER = 'recipes/photos';

    public function __construct(private readonly RecipePhotoService $files) {}

    public function add(Recipe $recipe, UploadedFile $file, string $kind, ?int $stepNumber = null, ?string $caption = null, ?PlannedMeal $meal = null): RecipePhoto
    {
        if ($recipe->isForeign()) {
            throw new InvalidArgumentException('Seul le foyer de la recette peut y ajouter des photos.');
        }

        if (! array_key_exists($kind, RecipePhoto::KINDS)) {
            throw new InvalidArgumentException('Type de photo inconnu.');
        }

        $steps = $recipe->steps()->count();

        if ($kind === 'step' && ($stepNumber === null || $stepNumber < 1 || $stepNumber > $steps)) {
            throw new InvalidArgumentException('Choisissez l\'étape de la photo.');
        }

        if ($recipe->photos()->count() >= self::MAX_PER_RECIPE) {
            throw new InvalidArgumentException('Déjà '.self::MAX_PER_RECIPE.' photos pour cette recette : supprimez-en d\'abord.');
        }

        $path = $this->files->store($file, null, self::FOLDER);

        return RecipePhoto::create([
            'recipe_id' => $recipe->id,
            'kind' => $kind,
            'step_number' => $kind === 'step' ? $stepNumber : null,
            // Lot 40 (40.2) : la photo suit son étape, même si on en insère une avant.
            'step_uid' => $kind === 'step' ? $recipe->steps()->orderBy('position')->orderBy('id')->offset($stepNumber - 1)->limit(1)->value('uid') : null,
            'path' => $path,
            'caption' => mb_substr(trim((string) $caption), 0, 150) ?: null,
            'position' => (int) $recipe->photos()->max('position') + 1,
            'planned_meal_id' => $meal?->id,
            'user_id' => auth()->id(),
        ]);
    }

    public function delete(RecipePhoto $photo): void
    {
        $this->files->delete($photo->path);
        $photo->delete();
    }

    /** Fait d'une photo la photo principale (une copie : la photo reste aussi à sa place). */
    public function makeMain(RecipePhoto $photo): Recipe
    {
        $recipe = $photo->recipe;

        if ($recipe->isForeign()) {
            throw new InvalidArgumentException('Seul le foyer de la recette peut changer sa photo.');
        }

        $old = $recipe->photo_path;
        $recipe->photo_path = $this->files->copy($photo->path);
        $recipe->save();
        $this->files->delete($old);

        return $recipe;
    }

    /** Photos d'étapes, par numéro d'étape. @return Collection<int, Collection<int, RecipePhoto>> */
    public function byStep(Recipe $recipe): Collection
    {
        return $recipe->photos()->where('kind', 'step')->get()->groupBy('step_number');
    }

    /** Fichiers à supprimer avec la recette (les lignes partent en cascade). */
    public function deleteFilesOf(Recipe $recipe): void
    {
        foreach (DB::table('recipe_photos')->where('recipe_id', $recipe->id)->pluck('path') as $path) {
            $this->files->delete($path);
        }
    }
}
