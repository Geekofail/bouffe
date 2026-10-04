<?php

namespace App\Livewire\Recipes;

use App\Models\Recipe;
use App\Models\RecipeCollection;
use App\Services\Recipes\Collections as CollectionService;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Fenêtre « Collections » de la fiche recette (31.1) : cocher les collections où ranger la
 * recette, ou en créer une.
 */
class RecipeCollections extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;

    #[Locked]
    public Recipe $recipe;

    public bool $show = false;

    public string $newName = '';

    #[On('open-recipe-collections')]
    public function open(): void
    {
        $this->resetErrorBag();
        $this->newName = '';
        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
    }

    public function toggle(int $collectionId, CollectionService $collections): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        try {
            $in = $collections->toggle(RecipeCollection::query()->findOrFail($collectionId), $this->recipe);
        } catch (InvalidArgumentException $e) {
            $this->addError('newName', $e->getMessage());

            return;
        }

        $this->dispatch('recipe-collections-changed');
        $this->dispatch('notify', message: $in ? 'Ajoutée à la collection.' : 'Retirée de la collection.');
    }

    public function createAndAdd(CollectionService $collections): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        try {
            $collection = $collections->create($this->newName);
            $collections->toggle($collection, $this->recipe);
        } catch (InvalidArgumentException $e) {
            $this->addError('newName', $e->getMessage());

            return;
        }

        $this->newName = '';
        $this->dispatch('recipe-collections-changed');
        $this->dispatch('notify', message: 'Collection « '.$collection->name.' » créée, avec cette recette.');
    }

    public function render()
    {
        $ids = $this->recipe->collections()->pluck('collections.id')->map(fn ($id) => (int) $id)->all();

        return view('livewire.recipes.recipe-collections', [
            'collections' => $this->show ? RecipeCollection::query()->orderBy('name')->get() : collect(),
            'selected' => $ids,
        ]);
    }
}
