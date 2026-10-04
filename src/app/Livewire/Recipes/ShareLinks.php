<?php

namespace App\Livewire\Recipes;

use App\Models\Recipe;
use App\Models\RecipeShareLink;
use App\Services\Recipes\RecipeShares;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Fenêtre « Partager un lien » de la fiche recette (31.4).
 */
class ShareLinks extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;

    #[Locked]
    public Recipe $recipe;

    public bool $show = false;

    /** Adresse du lien qui vient d'être créé : montrée une seule fois. */
    public ?string $newUrl = null;

    #[On('open-recipe-share')]
    public function open(): void
    {
        $this->resetErrorBag();
        $this->newUrl = null;
        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
        $this->newUrl = null;
    }

    public function create(RecipeShares $shares): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        try {
            $this->newUrl = $shares->create($this->recipe)['url'];
        } catch (InvalidArgumentException $e) {
            $this->addError('share', $e->getMessage());
        }
    }

    public function revoke(int $linkId, RecipeShares $shares): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $shares->revoke(RecipeShareLink::query()->where('recipe_id', $this->recipe->id)->findOrFail($linkId));
        $this->newUrl = null;
        $this->dispatch('notify', message: 'Lien révoqué : il ne fonctionne plus.');
    }

    public function render(RecipeShares $shares)
    {
        return view('livewire.recipes.share-links', [
            'links' => $this->show ? $shares->active($this->recipe)->load('author') : collect(),
        ]);
    }
}
