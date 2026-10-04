<?php

namespace App\Livewire\Planner\Concerns;

use App\Models\PlannedMeal;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;

/**
 * Planning — détail d'un repas : portions, commentaire, cuisinier, mangé / pas mangé, gamelle,
 * réactions, dupliquer, retirer (découpé de Week au lot 36).
 */
trait ManagesMealDetail
{
    /**
     * « Autre idée » dans le détail d'un plat (lot 37, 37.4).
     *
     * @var list<array<string, mixed>>
     */
    public array $mealIdeas = [];

    /** @var list<int> */
    public array $mealIdeasSeen = [];

    public function selectMeal(int $mealId): void
    {
        $meal = PlannedMeal::findOrFail($mealId);

        $this->resetErrorBag();
        $this->reset('mealIdeas', 'mealIdeasSeen');
        $this->selectedMealId = $meal->id;
        $this->editServings = $meal->servings;
        $this->editComment = (string) $meal->comment;
        $this->editCook = $meal->cook_together ? 'together' : (string) ($meal->cook_user_id ?: '');
    }

    public function closeMeal(): void
    {
        $this->selectedMealId = null;
        $this->reset('mealIdeas', 'mealIdeasSeen');
        $this->resetErrorBag();
    }

    /** « Autre idée » : trois plats qui pourraient le remplacer (même calcul que « Remplir la semaine »). */
    public function otherIdea(): void
    {
        $meal = $this->selectedMeal;

        if (! $meal || ! $meal->isRecipe() || ! $this->allowedToEdit()) {
            return;
        }

        $filler = app(\App\Services\Planning\WeekFiller::class);
        $avoid = [...$this->mealIdeasSeen, (int) $meal->recipe_id];
        $ideas = $filler->ideasFor($meal->date, (int) $meal->meal_slot_id, 3, $avoid, $meal->id, $meal->course);

        // Tout a été vu : on recommence (sans reproposer le plat actuel).
        if ($ideas === [] && $this->mealIdeasSeen !== []) {
            $this->mealIdeasSeen = [];
            $ideas = $filler->ideasFor($meal->date, (int) $meal->meal_slot_id, 3, [(int) $meal->recipe_id], $meal->id, $meal->course);
        }

        $this->mealIdeas = $ideas;
        $this->mealIdeasSeen = array_values(array_unique([...$this->mealIdeasSeen, ...array_column($ideas, 'recipe_id')]));

        if ($ideas === []) {
            $this->dispatch('notify', type: 'warning', message: 'Pas d\'autre idée qui respecte les règles de la semaine et les convives.');
        }
    }

    /** Remplace le plat par une idée ; « Annuler » remet l'ancien (R32). */
    public function replaceWithIdea(int $recipeId): void
    {
        $meal = $this->selectedMeal;

        if (! $meal || ! $this->allowedToEdit()) {
            return;
        }

        $recipe = \App\Models\Recipe::query()->active()->findOrFail($recipeId);
        $before = $meal->label();

        $this->attempt(function () use ($meal, $recipe, $before) {
            $this->undoable('planning.replace', "« {$before} » remplacé par « {$recipe->title} »", function (\App\Services\Undo\UndoRecorder $r) use ($meal, $recipe) {
                $r->track('planned_meals', [$meal->id]);
                $r->track('wishes', \App\Models\Wish::query()->where('planned_meal_id', $meal->id)->pluck('id'));
                $replaced = $this->planner()->replaceRecipe($meal, $recipe);
                // Les rappels du nouveau plat font partie du geste (sinon « Annuler » les verrait comme une modification).
                app(\App\Services\Planning\PrepReminderPlanner::class)->syncMeal($replaced);
            });

            $this->reset('mealIdeas', 'mealIdeasSeen');
            unset($this->mealConflicts, $this->stockConflicts);
        });
    }

    /** Repas libre pris dehors (restaurant, livraison) : saisir ou modifier sa dépense (lot 22, 23.4). */
    public function mealExpense(int $mealId): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $expenseId = \App\Models\Expense::query()->where('planned_meal_id', $mealId)->value('id');
        $this->closeMeal();
        $this->dispatch('open-expense', expenseId: $expenseId, mealId: $expenseId ? null : $mealId)->to(\App\Livewire\Budget\ExpenseForm::class);
    }

    #[Computed]
    public function selectedMeal(): ?PlannedMeal
    {
        return $this->selectedMealId
            ? PlannedMeal::with(['recipe', 'slot', 'leftoverOf.recipe', 'leftoverOf.slot', 'leftovers.slot', 'forPerson'])->find($this->selectedMealId)
            : null;
    }

    public function saveMeal(): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $meal = $this->selectedMeal;

        if (! $meal) {
            return;
        }

        $this->validate([
            'editServings' => 'required|numeric|min:0.5|max:50',
            'editComment' => 'nullable|string|max:255',
        ], [], ['editServings' => 'portions', 'editComment' => 'commentaire']);

        $this->attempt(function () use ($meal) {
            $this->planner()->update($meal, [
                'servings' => str_replace(',', '.', (string) $this->editServings),
                'comment' => $this->editComment,
                'cook_user_id' => $this->editCook === 'together' ? null : (int) $this->editCook,
                'cook_together' => $this->editCook === 'together',
            ]);
            unset($this->mealConflicts);
            $this->closeMeal();
            $this->offerLeftovers($meal->fresh());
        }, errorField: 'editServings');
    }

    /** Réaction 👍 / 👎 sur un repas mangé (18.4). */
    public function react(int $mealId, int $value): void
    {
        $meal = PlannedMeal::findOrFail($mealId);

        $this->attempt(function () use ($meal, $value) {
            app(\App\Services\Planning\MealFeedback::class)->toggle($meal, auth()->user(), $value);
            unset($this->reactions);
        });
    }

    /** Réactions des repas affichés, par repas. */
    #[Computed]
    public function reactions(): Collection
    {
        return \App\Models\MealReaction::query()
            ->whereIn('planned_meal_id', $this->meals->flatten(1)->pluck('id'))
            ->with('user')
            ->get()
            ->groupBy('planned_meal_id');
    }

    /** « Pas fait » / « À faire » (lot 21, R23) : rien n'est retiré du stock. */
    public function toggleSkipped(int $mealId): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $meal = PlannedMeal::findOrFail($mealId);
        $closing = app(\App\Services\Planning\MealClosing::class);
        $meal->isSkipped() ? $closing->unskip($meal) : $closing->skip($meal);

        unset($this->meals, $this->selectedMeal);
        $this->dispatch('reminders-changed');
    }

    /** Gamelle du midi (32.3) : ces restes partent avec une personne, ou restent pour la table. */
    public function setLunchbox(int $mealId, string|int|null $personId = null): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        try {
            $meal = $this->planner()->setLunchbox(PlannedMeal::findOrFail($mealId), $personId ? (int) $personId : null);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'warning');

            return;
        }

        unset($this->meals, $this->selectedMeal);
        $this->dispatch('notify', message: $meal->isLunchbox() ? $meal->lunchboxLabel().', '.$meal->date->locale('fr')->isoFormat('dddd').'.' : 'Restes mangés à table.');
    }

    public function toggleCooked(int $mealId): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $meal = $this->planner()->toggleCooked(PlannedMeal::findOrFail($mealId));
        unset($this->meals, $this->selectedMeal);
        $this->dispatch('meal-cooked', mealId: $meal->id, cooked: $meal->cooked_at !== null)->to(\App\Livewire\Stock\MealStockDialog::class);
    }

    public function duplicateMeal(string $date, int $slotId): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $this->attempt(function () use ($date, $slotId) {
            $this->planner()->duplicate($this->selectedMeal, $date, $slotId);
            $this->closeMeal();
            $this->dispatch('notify', message: 'Repas dupliqué.');
        });
    }

    public function placeLeftoversOf(int $mealId): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $meal = PlannedMeal::with('slot')->findOrFail($mealId);
        $this->closeMeal();
        $this->offerLeftovers($meal);

        if (! $this->leftoverOffer) {
            $this->dispatch('notify', type: 'warning', message: 'Plus de restes disponibles pour ce repas.');
        }
    }

    public function deleteMeal(int $mealId): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $meal = PlannedMeal::findOrFail($mealId);
        $label = $meal->label();

        // Lot 30 (R32) : « Annuler » remet le repas, ses restes planifiés et sa place dans la case.
        $this->undoable('planning.remove', "« {$label} » retiré du planning", function (\App\Services\Undo\UndoRecorder $r) use ($meal) {
            $r->track('planned_meals', [
                ...$this->planner()->cellMealIds($meal->date, (int) $meal->meal_slot_id),
                ...$meal->leftovers()->pluck('id')->all(),
            ]);
            $this->planner()->delete($meal);
        });

        $this->closeMeal();
        $this->leftoverOffer = null;
        unset($this->meals, $this->mealConflicts);
    }
}
