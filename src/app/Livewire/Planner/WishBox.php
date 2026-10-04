<?php

namespace App\Livewire\Planner;

use App\Models\Recipe;
use App\Models\Wish;
use App\Support\NameNormalizer;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Boîte « À planifier bientôt » (14.5).
 *
 * Une envie est soit une recette du carnet, soit une simple idée écrite. Si le texte saisi
 * correspond à une recette existante, l'envie est rattachée à cette recette (le remplissage
 * automatique pourra alors la placer).
 */
class WishBox extends Component
{
    /** Affichage réduit (colonne du planning) ou complet. */
    public bool $compact = false;

    public string $text = '';

    public function add(): void
    {
        $this->validate(
            ['text' => 'required|string|min:2|max:200'],
            [],
            ['text' => 'envie'],
        );

        $text = trim($this->text);
        $recipe = Recipe::query()->active()->where('search_title', NameNormalizer::normalize($text))->first();

        Wish::create([
            'user_id' => auth()->id(),
            'recipe_id' => $recipe?->id,
            'text' => $recipe ? null : mb_substr($text, 0, 200),
        ]);

        $this->reset('text');
        unset($this->wishes);
        $this->dispatch('wishes-changed');
    }

    public function addRecipe(int $recipeId): void
    {
        $recipe = Recipe::active()->findOrFail($recipeId);

        if (! Wish::query()->open()->where('recipe_id', $recipe->id)->exists()) {
            Wish::create(['user_id' => auth()->id(), 'recipe_id' => $recipe->id]);
        }

        unset($this->wishes);
        $this->dispatch('wishes-changed');
        $this->dispatch('notify', message: "« {$recipe->title} » est dans les envies.");
    }

    public function remove(int $wishId): void
    {
        Wish::query()->whereKey($wishId)->delete();
        unset($this->wishes);
        $this->dispatch('wishes-changed');
    }

    /** Marquée comme faite sans passer par le planning (« on l'a mangée hier »). */
    public function done(int $wishId): void
    {
        Wish::query()->whereKey($wishId)->update(['planned_at' => now()]);
        unset($this->wishes);
        $this->dispatch('wishes-changed');
    }

    #[On('wishes-changed')]
    public function refresh(): void
    {
        unset($this->wishes);
    }

    /** @return Collection<int, Wish> */
    #[Computed]
    public function wishes(): Collection
    {
        return Wish::query()->open()->with('recipe', 'user')->latest('id')->get();
    }

    /** Recettes proposées à la saisie (liste de suggestion). */
    #[Computed]
    public function recipeNames(): array
    {
        return Recipe::query()->active()->orderBy('title')->pluck('title')->all();
    }

    public function render()
    {
        return view('livewire.planner.wish-box');
    }
}
