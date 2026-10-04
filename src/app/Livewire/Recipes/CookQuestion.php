<?php

namespace App\Livewire\Recipes;

use App\Models\Recipe;
use App\Services\Assistant\AssistantFailed;
use App\Services\Assistant\RecipeAssistant;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * « Une question ? » en mode cuisine (33.4) : une réponse courte sur l'étape en cours
 * (« je n'ai pas de crème, quoi à la place ? »). Affichée, jamais enregistrée dans la recette.
 */
class CookQuestion extends Component
{
    #[Locked]
    public Recipe $recipe;

    #[Locked]
    public int $step = 1;

    public bool $open = false;

    public string $question = '';

    public ?string $answer = null;

    /** Question à laquelle répond $answer. */
    public string $asked = '';

    public function ask(RecipeAssistant $assistant): void
    {
        if (! auth()->user()?->canEdit()) {
            return;
        }

        $this->validate(['question' => 'required|string|max:200'], [], ['question' => 'question']);
        \App\Support\TimeLimit::atLeast(60);

        try {
            $this->answer = $assistant->question($this->recipe, $this->step, $this->question);
            $this->asked = $this->question;
            $this->question = '';
            $this->resetErrorBag();
        } catch (AssistantFailed|InvalidArgumentException $e) {
            $this->addError('question', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.recipes.cook-question');
    }
}
