<?php

namespace App\Livewire\Recipes;

use App\Models\Recipe;
use App\Models\RecipeCollection;
use App\Services\Recipes\Collections as CollectionService;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Une collection (31.1) : ses recettes dans l'ordre choisi, l'ajout de recettes, le partage avec
 * les foyers reliés et l'impression en carnet (26.9).
 */
class CollectionShow extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;

    #[Locked]
    public RecipeCollection $collection;

    public bool $editing = false;

    public string $name = '';

    public string $description = '';

    public string $search = '';

    public bool $showShare = false;

    public function mount(RecipeCollection $collection): void
    {
        $this->collection = $collection;
    }

    private function mine(): bool
    {
        return ! $this->collection->isForeign() && $this->allowedToEdit();
    }

    /* ---------------------------------------------------------------- Nom */

    public function edit(): void
    {
        $this->resetErrorBag();
        $this->name = $this->collection->name;
        $this->description = (string) $this->collection->description;
        $this->editing = true;
    }

    public function closeForm(): void
    {
        $this->editing = false;
    }

    public function saveDetails(CollectionService $collections): void
    {
        if (! $this->mine()) {
            return;
        }

        try {
            $collections->update($this->collection, $this->name, $this->description);
        } catch (InvalidArgumentException $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        $this->editing = false;
    }

    public function delete(CollectionService $collections): void
    {
        if (! $this->mine()) {
            return;
        }

        $name = $this->collection->name;
        $collections->delete($this->collection);
        session()->flash('status', "Collection « {$name} » supprimée (les recettes restent dans le carnet).");
        $this->redirectRoute('recipes.collections', navigate: true);
    }

    /* ---------------------------------------------------------------- Recettes */

    public function add(int $recipeId, CollectionService $collections): void
    {
        if (! $this->mine()) {
            return;
        }

        $recipe = Recipe::query()->findOrFail($recipeId);

        if (! $this->inCollection($recipe->id)) {
            $collections->toggle($this->collection, $recipe);
        }

        unset($this->recipes, $this->candidates);
    }

    public function remove(int $recipeId, CollectionService $collections): void
    {
        if (! $this->mine()) {
            return;
        }

        $recipe = Recipe::query()->findOrFail($recipeId);

        if ($this->inCollection($recipe->id)) {
            $collections->toggle($this->collection, $recipe);
        }

        unset($this->recipes, $this->candidates);
    }

    public function move(int $recipeId, int $direction, CollectionService $collections): void
    {
        if (! $this->mine()) {
            return;
        }

        $collections->move($this->collection, $recipeId, $direction);
        unset($this->recipes);
    }

    private function inCollection(int $recipeId): bool
    {
        return \Illuminate\Support\Facades\DB::table('collection_recipe')->where('collection_id', $this->collection->id)->where('recipe_id', $recipeId)->exists();
    }

    /* ---------------------------------------------------------------- Partage */

    public function openShare(): void
    {
        $this->showShare = true;
    }

    public function closeShare(): void
    {
        $this->showShare = false;
    }

    public function share(bool $shared, bool $withRecipes, CollectionService $collections): void
    {
        if (! $this->mine()) {
            return;
        }

        $opened = $collections->setShared($this->collection, $shared, $withRecipes);
        $this->showShare = false;
        unset($this->privateRecipes);

        $this->dispatch('notify', message: $shared
            ? 'Collection partagée avec les foyers reliés'.($opened > 0 ? ' ; '.$opened.' recette'.($opened > 1 ? 's' : '').' ouverte'.($opened > 1 ? 's' : '').' aussi.' : '.')
            : 'La collection n\'est plus partagée.');
    }

    /* ---------------------------------------------------------------- Données */

    /** @return Collection<int, Recipe> */
    #[Computed]
    public function recipes(): Collection
    {
        return app(CollectionService::class)->recipes($this->collection);
    }

    /** Recettes du carnet à ajouter : recherche par titre. @return Collection<int, Recipe> */
    #[Computed]
    public function candidates(): Collection
    {
        if ($this->collection->isForeign() || trim($this->search) === '') {
            return collect();
        }

        return Recipe::query()->active()->search($this->search)
            ->whereNotIn('recipes.id', \Illuminate\Support\Facades\DB::table('collection_recipe')->where('collection_id', $this->collection->id)->select('recipe_id'))
            ->orderBy('title')->limit(8)->get();
    }

    /** @return Collection<int, Recipe> */
    #[Computed]
    public function privateRecipes(): Collection
    {
        return $this->collection->isForeign() ? collect() : app(CollectionService::class)->privateRecipes($this->collection);
    }

    public function render(CollectionService $collections)
    {
        return view('livewire.recipes.collection-show', [
            'foreign' => $this->collection->isForeign(),
            'printUrl' => $collections->printUrl($this->collection),
            'linkedCount' => count(app(\App\Services\Linked\HouseholdLinks::class)->linkedIds()),
        ])->title($this->collection->name);
    }
}
