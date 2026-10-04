<?php

namespace App\Livewire\Stock;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Services\Assistant\AssistantFailed;
use App\Services\Assistant\AssistantService;
use App\Services\Assistant\RecipeAssistant;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * « Que faire avec… ? » sur la page Que cuisiner (33.2).
 *
 * On tape quelques ingrédients : Bouffe cherche d'abord dans le carnet (sans rien envoyer), puis,
 * sur demande, l'assistant propose trois idées nouvelles. Seule la liste tapée part chez lui —
 * jamais le stock.
 */
class WhatToMake extends Component
{
    public string $text = '';

    /** Ingrédients compris, après « Chercher ». @var list<string> */
    public array $terms = [];

    /** Idées de l'assistant (jamais modifiables depuis le navigateur). */
    #[Locked]
    public ?array $ideas = null;

    public function search(RecipeAssistant $assistant): void
    {
        $this->validate(['text' => 'required|string|max:300'], [], ['text' => 'ingrédients']);

        $this->terms = $assistant->ingredientsFromText($this->text);
        $this->ideas = null;
        unset($this->matches);

        if ($this->terms === []) {
            $this->addError('text', 'Indiquez au moins un ingrédient.');
        }
    }

    public function askIdeas(RecipeAssistant $assistant): void
    {
        if (! auth()->user()?->canEdit() || $this->terms === []) {
            return;
        }

        \App\Support\TimeLimit::atLeast(90);

        try {
            $this->ideas = $assistant->ideas(implode(', ', $this->terms));
            $this->resetErrorBag();

            if ($this->ideas === []) {
                $this->addError('assistant', 'L\'assistant n\'a rien proposé. Reformulez la liste et réessayez.');
            }
        } catch (AssistantFailed|InvalidArgumentException $e) {
            $this->addError('assistant', $e->getMessage());
        }
    }

    /** L'idée part dans l'écran d'import, pour être relue avant d'exister. */
    public function import(int $index, RecipeAssistant $assistant): void
    {
        $idea = $this->ideas[$index] ?? null;

        if (! $idea || ! auth()->user()?->canEdit()) {
            return;
        }

        session()->put('assistant.draft', $assistant->ideaAsDraft($idea));
        $this->redirectRoute('recipes.import', navigate: true);
    }

    public function clear(): void
    {
        $this->reset('text', 'terms', 'ideas');
        $this->resetErrorBag();
    }

    /**
     * Les trois recettes du carnet qui utilisent le plus d'ingrédients tapés.
     *
     * @return Collection<int, array{recipe: Recipe, found: list<string>}>
     */
    #[Computed]
    public function matches(): Collection
    {
        if ($this->terms === []) {
            return collect();
        }

        // Ingrédient => terme tapé : le nom exact d'abord, sinon ceux qui le contiennent.
        $termOf = [];

        foreach ($this->terms as $term) {
            $found = Ingredient::findByName($term);
            $ids = $found ? [$found->id] : Ingredient::query()->search($term)->limit(8)->pluck('id')->all();

            foreach ($ids as $id) {
                $termOf[$id] ??= $term;
            }
        }

        if ($termOf === []) {
            return collect();
        }

        $byRecipe = RecipeIngredient::query()
            ->whereIn('ingredient_id', array_keys($termOf))
            ->whereIn('recipe_id', Recipe::active()->select('id'))
            ->get(['recipe_id', 'ingredient_id'])
            ->groupBy('recipe_id')
            ->map(fn ($lines) => $lines->map(fn ($line) => $termOf[$line->ingredient_id])->unique()->values()->all())
            ->sortByDesc(fn ($found) => count($found))
            ->take(3);

        $recipes = Recipe::query()->whereIn('id', $byRecipe->keys())->get()->keyBy('id');

        return $byRecipe->map(fn ($found, $id) => ['recipe' => $recipes->get($id), 'found' => $found])
            ->filter(fn ($row) => $row['recipe'] !== null)
            ->values();
    }

    #[Computed]
    public function assistantAvailable(): bool
    {
        return (bool) auth()->user()?->canEdit() && app(AssistantService::class)->available();
    }

    public function render()
    {
        return view('livewire.stock.what-to-make');
    }
}
