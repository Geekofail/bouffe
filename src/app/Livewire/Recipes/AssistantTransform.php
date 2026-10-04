<?php

namespace App\Livewire\Recipes;

use App\Models\Recipe;
use App\Services\Assistant\AssistantFailed;
use App\Services\Assistant\RecipeAssistant;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * « Adapter avec l'assistant » sur la fiche recette (33.1).
 *
 * On choisit une transformation (végétarienne, sans lactose…) ou on la décrit en quelques mots ;
 * l'assistant propose un brouillon, surligné, que l'on garde comme variante, comme nouvelle recette
 * (relue dans l'écran d'import), ou que l'on jette. Rien n'est enregistré sans ce choix.
 */
class AssistantTransform extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;

    #[Locked]
    public Recipe $recipe;

    public bool $show = false;

    public string $preset = 'vegetarien';

    public string $free = '';

    /** Proposition en cours (jamais modifiable depuis le navigateur). */
    #[Locked]
    public ?array $result = null;

    #[On('open-recipe-assistant')]
    public function open(): void
    {
        $this->resetErrorBag();
        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
    }

    public function propose(RecipeAssistant $assistant): void
    {
        if (! $this->allowedToEdit() || $this->recipe->isForeign()) {
            return;
        }

        $this->validate(['free' => 'nullable|string|max:'.RecipeAssistant::MAX_FREE_TEXT], [], ['free' => 'consigne']);
        \App\Support\TimeLimit::atLeast(90);

        try {
            $this->result = $assistant->transform($this->recipe, $this->preset, $this->preset === 'libre' ? $this->free : null);
            $this->resetErrorBag();
        } catch (AssistantFailed|InvalidArgumentException $e) {
            $this->addError($this->preset === 'libre' && $e instanceof InvalidArgumentException ? 'free' : 'assistant', $e->getMessage());
        }
    }

    public function saveAsVariant(RecipeAssistant $assistant): void
    {
        if (! $this->result || ! $this->allowedToEdit()) {
            return;
        }

        try {
            $variant = $assistant->saveAsVariant($this->recipe, $this->result);
        } catch (InvalidArgumentException $e) {
            $this->addError('assistant', $e->getMessage());

            return;
        }

        session()->flash('status', 'Variante « '.$variant->name.' » enregistrée. Vérifiez-la avant de la cuisiner.');
        $this->redirectRoute('recipes.show', ['recipe' => $this->recipe, 'variante' => $variant->id], navigate: true);
    }

    /** Le brouillon part dans l'écran d'import, pour être relu et corrigé avant d'exister. */
    public function asNewRecipe(RecipeAssistant $assistant): void
    {
        if (! $this->result || ! $this->allowedToEdit()) {
            return;
        }

        session()->put('assistant.draft', $assistant->transformAsDraft($this->recipe, $this->result));
        $this->redirectRoute('recipes.import', navigate: true);
    }

    public function discard(): void
    {
        $this->result = null;
    }

    public function render(RecipeAssistant $assistant)
    {
        return view('livewire.recipes.assistant-transform', [
            'presets' => collect(RecipeAssistant::PRESETS)->map(fn ($preset) => $preset[0]),
            'fitsVariant' => $this->result !== null && $assistant->fitsVariant($this->result),
        ]);
    }
}
