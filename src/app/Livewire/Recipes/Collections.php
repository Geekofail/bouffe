<?php

namespace App\Livewire\Recipes;

use App\Services\Recipes\Collections as CollectionService;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Collections de recettes (lot 31, 31.1) : les nôtres, et celles que les foyers reliés partagent.
 */
#[Title('Collections')]
class Collections extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;

    public bool $showForm = false;

    public string $name = '';

    public string $description = '';

    public function create(): void
    {
        $this->resetErrorBag();
        $this->reset('name', 'description');
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function save(CollectionService $collections): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        try {
            $collection = $collections->create($this->name, $this->description);
        } catch (InvalidArgumentException $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        $this->showForm = false;
        $this->redirectRoute('recipes.collections.show', ['collection' => $collection->id], navigate: true);
    }

    public function render(CollectionService $collections)
    {
        $mine = $collections->mine();
        $shared = $collections->sharedWithMe()->with('household:id,name')->orderBy('name')->get();

        // Quelques photos pour illustrer chaque collection.
        $covers = \Illuminate\Support\Facades\DB::table('collection_recipe')
            ->join('recipes', 'recipes.id', '=', 'collection_recipe.recipe_id')
            ->whereIn('collection_recipe.collection_id', $mine->pluck('id'))
            ->whereNull('recipes.archived_at')
            ->orderBy('collection_recipe.position')
            ->get(['collection_recipe.collection_id', 'recipes.id'])
            ->groupBy('collection_id')
            ->map(fn ($rows) => $rows->take(3)->pluck('id'));
        $models = \App\Models\Recipe::query()->whereIn('id', $covers->flatten()->unique())->with('tags')->get()->keyBy('id');
        $covers = $covers->map(fn ($ids) => $ids->map(fn ($id) => $models->get($id))->filter()->values());

        return view('livewire.recipes.collections', [
            'mine' => $mine,
            'shared' => $shared,
            'covers' => $covers,
        ]);
    }
}
