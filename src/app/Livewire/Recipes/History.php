<?php

namespace App\Livewire\Recipes;

use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Services\Recipes\RecipeRevisions;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Historique d'une recette (lot 31, 31.5) : les versions, de la plus récente à la plus ancienne,
 * ce qui a changé, qui l'a fait ; voir une version et y revenir.
 */
class History extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;

    #[Locked]
    public Recipe $recipe;

    public ?int $openId = null;

    public function mount(Recipe $recipe): void
    {
        abort_if($recipe->isForeign(), 404);
        $this->recipe = $recipe;
    }

    public function toggle(int $revisionId): void
    {
        $this->openId = $this->openId === $revisionId ? null : $revisionId;
    }

    public function restore(int $revisionId, RecipeRevisions $revisions): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $revision = RecipeRevision::query()->where('recipe_id', $this->recipe->id)->findOrFail($revisionId);

        try {
            $recipe = $revisions->restore($this->recipe, $revision);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());

            return;
        }

        session()->flash('status', 'Recette revenue à la version du '.$revision->created_at?->locale('fr')->isoFormat('D MMMM YYYY').'.');
        $this->redirectRoute('recipes.show', ['recipe' => $recipe], navigate: true);
    }

    /** @return Collection<int, RecipeRevision> */
    #[Computed]
    public function revisions(): Collection
    {
        return $this->recipe->revisions()->with('user:id,name')->get();
    }

    /** La dernière version est-elle bien le contenu actuel ? (la recette a pu changer ailleurs : copie d'un proche…) */
    #[Computed]
    public function latestIsCurrent(): bool
    {
        $latest = $this->revisions->first();

        $revisions = app(RecipeRevisions::class);

        return $latest !== null && $revisions->same($latest->snapshot, $revisions->snapshot($this->recipe->fresh()));
    }

    public function render()
    {
        return view('livewire.recipes.history')->title('Historique · '.$this->recipe->title);
    }
}
