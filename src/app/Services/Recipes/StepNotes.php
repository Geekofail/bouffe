<?php

namespace App\Services\Recipes;

use App\Models\Recipe;
use App\Models\RecipePhoto;
use App\Models\RecipeStepNote;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Notes d'étape (lot 40, 40.2) : « notre four chauffe fort : 170 °C », montrées en mode cuisine et
 * sur la fiche. Notes et photos suivent l'**étape** (son identifiant) et non son numéro.
 */
class StepNotes
{
    public const MAX_LENGTH = 500;

    /**
     * Notes du foyer, par numéro d'étape actuel.
     *
     * @return Collection<int, Collection<int, RecipeStepNote>>
     */
    public function byStep(Recipe $recipe): Collection
    {
        $positions = $this->positions($recipe);

        return RecipeStepNote::query()->where('recipe_id', $recipe->id)->with('user:id,name')->orderBy('id')->get()
            ->filter(fn (RecipeStepNote $note) => isset($positions[$note->step_uid]))
            ->groupBy(fn (RecipeStepNote $note) => $positions[$note->step_uid]);
    }

    public function add(Recipe $recipe, int $stepNumber, string $text): RecipeStepNote
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $uid = array_search($stepNumber, $this->positions($recipe), true);

        if ($uid === false) {
            throw new InvalidArgumentException('Étape introuvable.');
        }

        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Une note de 1 à '.self::MAX_LENGTH.' caractères.');
        }

        return RecipeStepNote::create(['recipe_id' => $recipe->id, 'step_uid' => $uid, 'note' => $text, 'user_id' => auth()->id()]);
    }

    public function delete(RecipeStepNote $note): void
    {
        $note->delete();
    }

    /**
     * Après une modification de la recette : chaque photo d'étape reprend le numéro de son étape. Une
     * photo dont l'étape a disparu garde son ancien numéro, sans identifiant (elle reste visible).
     */
    public function syncPhotos(Recipe $recipe): void
    {
        $positions = $this->positions($recipe);

        foreach (RecipePhoto::query()->where('recipe_id', $recipe->id)->where('kind', 'step')->whereNotNull('step_uid')->get() as $photo) {
            if (isset($positions[$photo->step_uid])) {
                if ($photo->step_number !== $positions[$photo->step_uid]) {
                    $photo->update(['step_number' => $positions[$photo->step_uid]]);
                }
            } else {
                $photo->update(['step_uid' => null]);
            }
        }
    }

    /** @return array<string, int> identifiant d'étape => numéro (1, 2…) */
    public function positions(Recipe $recipe): array
    {
        $positions = [];

        foreach ($recipe->steps()->orderBy('position')->orderBy('id')->pluck('uid')->values() as $i => $uid) {
            if ($uid) {
                $positions[$uid] = $i + 1;
            }
        }

        return $positions;
    }
}
