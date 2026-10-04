<?php

namespace App\Livewire\Recipes\Concerns;

use App\Models\MealSlot;
use App\Models\User;
use App\Services\Planning\WeekPlanner;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * Fiche recette — notes et avis, favori, envies, planifier (découpé de Show au lot 36).
 */
trait RatesAndPlansRecipe
{
    public function rate(int $stars): void
    {
        $stars = max(0, min(5, $stars));

        if ($stars === 0) {
            $this->recipe->ratings()->where('user_id', auth()->id())->delete();
            $this->myRating = 0;

            return;
        }

        // Lot 26 (26.3) : le foyer de la personne, pour distinguer les avis des proches.
        $this->recipe->ratings()->updateOrCreate(['user_id' => auth()->id()], ['rating' => $stars, 'comment' => trim($this->myComment) ?: null, 'household_id' => \App\Support\CurrentHousehold::id()]);
        $this->myRating = $stars;
    }

    public function saveComment(): void
    {
        $this->validate(['myComment' => 'nullable|string|max:1000'], [], ['myComment' => 'commentaire']);

        if ($this->myRating === 0) {
            $this->addError('myComment', 'Donnez d\'abord une note (étoiles).');

            return;
        }

        $this->recipe->ratings()->where('user_id', auth()->id())->update(['comment' => trim($this->myComment) ?: null]);
        $this->dispatch('notify', message: 'Commentaire enregistré.');
    }

    public function toggleFavorite(): void
    {
        if (! $this->allowedToEdit() || $this->recipe->isForeign()) {
            return;
        }

        $this->recipe->timestamps = false;
        $this->recipe->update(['is_favorite' => ! $this->recipe->is_favorite]);
    }

    /** Ajoute la recette aux envies « à planifier bientôt » (14.5). */
    public function addToWishes(): void
    {
        if (! $this->allowedToEdit() || $this->recipe->isForeign()) {
            return;
        }

        if (\App\Models\Wish::query()->open()->where('recipe_id', $this->recipe->id)->exists()) {
            $this->dispatch('notify', message: 'Cette recette est déjà dans les envies.');

            return;
        }

        \App\Models\Wish::create(['user_id' => auth()->id(), 'recipe_id' => $this->recipe->id]);
        unset($this->isWished);
        $this->dispatch('notify', message: 'Ajoutée aux envies : elle sera proposée en priorité.');
    }

    public function removeFromWishes(): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        \App\Models\Wish::query()->open()->where('recipe_id', $this->recipe->id)->delete();
        unset($this->isWished);
        $this->dispatch('notify', message: 'Retirée des envies.');
    }

    /** Bilan des réactions du foyer (18.4). */
    #[Computed]
    public function feedback(): array
    {
        return app(\App\Services\Planning\MealFeedback::class)->forRecipe($this->recipe);
    }

    #[Computed]
    public function isWished(): bool
    {
        return \App\Models\Wish::query()->open()->where('recipe_id', $this->recipe->id)->exists();
    }

    public function openPlan(): void
    {
        $planner = app(WeekPlanner::class);

        $this->resetErrorBag();
        $this->planDate = now()->toDateString();
        $this->planSlotId = MealSlot::query()->active()->ordered()->get()->last()?->id;
        $this->planServings = max($this->householdServings(), $this->servings);
        $this->showPlan = true;
    }

    public function closePlan(): void
    {
        $this->showPlan = false;
    }

    public function plan(WeekPlanner $planner): void
    {
        $this->validate([
            'planDate' => 'required|date',
            'planSlotId' => 'required|integer|exists:meal_slots,id',
            'planServings' => 'required|numeric|min:0.5|max:50',
        ], [], ['planDate' => 'date', 'planSlotId' => 'créneau', 'planServings' => 'portions']);

        abort_if($this->recipe->isArchived(), 403);

        $meal = $planner->addRecipe($this->planDate, (int) $this->planSlotId, $this->recipe, str_replace(',', '.', (string) $this->planServings));

        $this->showPlan = false;
        session()->flash('status', 'Recette ajoutée au planning.');
        $this->redirectRoute('planner.week', ['semaine' => $planner->weekStart($meal->date)->toDateString()], navigate: true);
    }

    /** Notes de chaque membre du foyer (y compris ceux qui n'ont pas noté). */
    #[Computed]
    public function householdRatings(): Collection
    {
        $ratings = $this->recipe->ratings()->get()->keyBy('user_id');

        return User::query()->inHousehold()->orderBy('name')->get()->map(fn (User $user) => [
            'user' => $user,
            'rating' => $ratings->get($user->id),
        ]);
    }
}
