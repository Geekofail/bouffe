<?php

namespace App\Livewire\Linked;

use App\Enums\Course;
use App\Models\MealOccasionHousehold;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Services\Linked\LinkedEaters;
use App\Services\Linked\SharedMeals;
use App\Services\Planning\WeekPlanner;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Repas commun, côté foyer invité (26.5) : répondre, dire combien on sera, choisir ce qu'on apporte.
 * Les plats apportés sont des repas de notre planning (liste de courses et rappels compris).
 */
#[Title('Repas en commun')]
class SharedMeal extends Component
{
    #[Locked]
    public int $rowId;

    public int|string $people = 2;

    public string $message = '';

    public ?int $recipeId = null;

    public string $course = '';

    public int|float|string $servings = '';

    public function mount(int $row, SharedMeals $meals): void
    {
        $found = $meals->find($row) ?? abort(404);
        $this->rowId = $found->id;
        $this->people = $found->people ?: max(1, \App\Support\Settings::int('household_size', 2));
        $this->message = (string) $found->message;
    }

    private function row(): MealOccasionHousehold
    {
        return app(SharedMeals::class)->find($this->rowId) ?? abort(404);
    }

    public function respond(bool $accept, SharedMeals $meals): void
    {
        $this->requireEdit();
        $this->validate(['people' => 'required|integer|min:1|max:50', 'message' => 'nullable|string|max:255'], [], ['people' => 'nombre de personnes']);
        $meals->respond($this->row(), $accept, (int) $this->people, $this->message);
        $this->dispatch('notify', message: $accept ? 'Réponse envoyée : vous venez.' : 'Réponse envoyée : vous ne venez pas.');
    }

    public function bring(SharedMeals $meals): void
    {
        $this->requireEdit();
        $this->validate(['recipeId' => 'required|integer', 'servings' => 'nullable|numeric|min:0.5|max:50'], [], ['recipeId' => 'recette', 'servings' => 'portions']);

        try {
            $meals->bring($this->row(), (int) $this->recipeId, Course::tryFrom($this->course), $this->servings === '' ? null : \App\Services\Planning\Appetites::clamp($this->servings));
        } catch (\InvalidArgumentException $e) {
            $this->addError('recipeId', $e->getMessage());

            return;
        }

        $this->reset('recipeId', 'course', 'servings');
        $this->dispatch('notify', message: 'Plat ajouté à votre planning : ses ingrédients iront dans votre liste de courses.');
    }

    public function removeDish(int $mealId): void
    {
        $this->requireEdit();
        $meal = PlannedMeal::query()->where('for_occasion_id', $this->row()->meal_occasion_id)->find($mealId);

        if ($meal) {
            app(WeekPlanner::class)->delete($meal);
        }
    }

    private function requireEdit(): void
    {
        abort_unless(Auth::user()?->canEdit(), 403, 'Réservé aux comptes complets du foyer.');
    }

    public function render(SharedMeals $meals, LinkedEaters $eaters)
    {
        $row = $this->row();
        $occasion = $row->occasion;

        return view('livewire.linked.shared-meal', [
            'row' => $row,
            'occasion' => $occasion,
            'host' => $occasion->household,
            'slotName' => \App\Models\MealSlot::query()->withoutGlobalScope(\App\Models\Scopes\HouseholdScope::class)->whereKey($occasion->meal_slot_id)->value('name'),
            'serveAt' => \App\Support\CurrentHousehold::run((int) $occasion->household_id, fn () => $occasion->serveAt()),
            'hostMenu' => $meals->hostMenu($occasion),
            'others' => $meals->broughtDishes($occasion)->where('household_id', '!=', $row->household_id),
            'mine' => $meals->myDishes($row),
            'recipes' => Recipe::query()->active()->orderBy('title')->get(['id', 'title']),
            'courses' => Course::ordered(),
            'canEdit' => (bool) Auth::user()?->canEdit(),
            'sharing' => $eaters->members($row),
        ]);
    }
}
