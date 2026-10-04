<?php

namespace App\Livewire\Recipes;

use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\RecipePhoto;
use App\Services\Recipes\RecipePhotos;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Photos d'une recette (lot 31, 31.2) : « notre version » et photos d'étapes, sur la fiche.
 * Aussi utilisé, en version réduite, dans le détail d'un repas mangé (« Une photo de notre version ? »).
 */
class Photos extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;
    use WithFileUploads;

    #[Locked]
    public Recipe $recipe;

    /** Repas d'où vient la photo (détail d'un repas mangé). */
    #[Locked]
    public ?int $mealId = null;

    /** Affichage réduit : un seul bouton « notre version ». */
    #[Locked]
    public bool $compact = false;

    public $upload = null;

    public string $kind = 'ours';

    public int|string|null $stepNumber = null;

    public string $caption = '';

    public bool $showForm = false;

    public function openForm(string $kind = 'ours', ?int $step = null): void
    {
        $this->resetErrorBag();
        $this->reset('upload', 'caption');
        $this->kind = $kind === 'step' ? 'step' : 'ours';
        $this->stepNumber = $step ?? ($this->kind === 'step' ? 1 : null);
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->reset('upload');
    }

    public function save(RecipePhotos $photos): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $this->validate([
            'upload' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'caption' => ['nullable', 'string', 'max:150'],
        ], [], ['upload' => 'photo', 'caption' => 'légende']);

        $meal = $this->mealId ? PlannedMeal::find($this->mealId) : null;

        try {
            $photos->add($this->recipe, $this->upload, $this->kind, $this->kind === 'step' ? (int) $this->stepNumber : null, $this->caption, $meal);
        } catch (InvalidArgumentException|\RuntimeException $e) {
            $this->addError('upload', $e->getMessage());

            return;
        }

        $this->showForm = false;
        $this->reset('upload', 'caption');
        unset($this->photos);
        $this->dispatch('recipe-photos-changed');
        $this->dispatch('notify', message: $this->kind === 'step' ? 'Photo ajoutée à l\'étape '.$this->stepNumber.'.' : 'Photo de notre version ajoutée.');
    }

    public function delete(int $photoId, RecipePhotos $photos): void
    {
        if (! $this->allowedToEdit() || $this->recipe->isForeign()) {
            return;
        }

        $photos->delete($this->recipe->photos()->findOrFail($photoId));
        unset($this->photos);
        $this->dispatch('recipe-photos-changed');
    }

    public function makeMain(int $photoId, RecipePhotos $photos): void
    {
        if (! $this->allowedToEdit() || $this->recipe->isForeign()) {
            return;
        }

        $photos->makeMain($this->recipe->photos()->findOrFail($photoId));
        $this->dispatch('notify', message: 'Photo principale remplacée.');
        $this->redirectRoute('recipes.show', ['recipe' => $this->recipe], navigate: true);
    }

    /** @return Collection<int, RecipePhoto> */
    #[Computed]
    public function photos(): Collection
    {
        return $this->recipe->photos()->with('user', 'meal')->get();
    }

    public function render()
    {
        return view('livewire.recipes.photos', [
            'ours' => $this->photos->where('kind', 'ours')->sortByDesc('id')->values(),
            'steps' => $this->photos->where('kind', 'step')->sortBy(['step_number', 'position'])->values(),
            'stepCount' => $this->recipe->steps()->count(),
            'canEdit' => ! $this->recipe->isForeign() && (bool) auth()->user()?->canEdit(),
        ]);
    }
}
