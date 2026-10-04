<?php

namespace App\Livewire\Kitchen;

use App\Models\ChildChoice;
use App\Services\People\ChildChoices;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * L'écran du choix (lot 39, 39.3) : trois grandes images, un toucher, une confirmation.
 * Pour la tablette de cuisine ou le téléphone d'un parent tendu à l'enfant.
 */
#[Layout('layouts.kitchen')]
#[Title('Choisis ton repas')]
class Choice extends Component
{
    #[Url(as: 'choix', except: null)]
    public ?int $choiceId = null;

    /** Recette touchée, en attente du « Oui ». */
    public ?int $picked = null;

    /** Choix qui vient d'être fait (écran « C'est noté »). */
    public ?int $doneId = null;

    public function pick(int $recipeId): void
    {
        $this->picked = $recipeId;
    }

    public function back(): void
    {
        $this->picked = null;
    }

    public function confirm(ChildChoices $choices): void
    {
        $choice = $this->choice;

        if (! $choice || ! $this->picked) {
            return;
        }

        try {
            $done = $choices->choose($choice, $this->picked);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());
            $this->picked = null;

            return;
        }

        $this->doneId = $done->id;
        $this->picked = null;
        $this->choiceId = null;
        unset($this->choice);
    }

    public function next(): void
    {
        $this->doneId = null;
        unset($this->choice);
    }

    #[Computed]
    public function choice(): ?ChildChoice
    {
        $query = ChildChoice::query()->open()->with('slot', 'person');

        return $this->choiceId ? $query->find($this->choiceId) : $query->orderBy('date')->orderBy('meal_slot_id')->first();
    }

    #[Computed]
    public function done(): ?ChildChoice
    {
        return $this->doneId ? ChildChoice::query()->with('chosenRecipe', 'slot', 'person')->find($this->doneId) : null;
    }

    public function render()
    {
        return view('livewire.kitchen.choice');
    }
}
