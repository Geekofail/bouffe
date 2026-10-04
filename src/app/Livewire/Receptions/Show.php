<?php

namespace App\Livewire\Receptions;

use App\Enums\Course;
use App\Models\MealOccasion;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Services\Planning\GuestCompatibility;
use App\Services\Planning\WeekPlanner;
use App\Services\Receptions\ReceptionPlanner;
use App\Services\Receptions\Receptions;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Une réception (module 21) : menu par plat (21.1), rétroplanning (21.2), carte de menu (21.3)
 * et souvenir (21.4).
 */
class Show extends Component
{
    use WithFileUploads;

    public MealOccasion $occasion;

    public string $serveTime = '';

    public ?int $newRecipeId = null;

    public string $newCourse = 'plat';

    public int|float|string $newServings = '';

    public string $menuMessage = '';

    public string $memoryNote = '';

    public $photo = null;

    public function mount(MealOccasion $occasion): void
    {
        $this->occasion = $occasion;
        $this->fillFromOccasion();
    }

    private function fillFromOccasion(): void
    {
        $this->serveTime = (string) $this->occasion->serve_time;
        $this->menuMessage = (string) $this->occasion->menu_message;
        $this->memoryNote = (string) $this->occasion->memory_note;
        $this->newServings = app(\App\Services\Planning\OccasionService::class)->diners($this->occasion);
    }

    #[On('occasion-saved')]
    public function refreshOccasion(): void
    {
        $this->occasion->refresh();
        $this->newServings = app(\App\Services\Planning\OccasionService::class)->diners($this->occasion);
        $this->forget();
    }

    public function editGuests(): void
    {
        $this->dispatch('open-occasion', date: $this->occasion->date->toDateString(), slotId: $this->occasion->meal_slot_id);
    }

    /* ================================================================ Heure et menu */

    public function saveTime(Receptions $receptions): void
    {
        $this->authorizeEdit();

        try {
            $receptions->setServeTime($this->occasion, $this->serveTime);
        } catch (InvalidArgumentException $e) {
            $this->addError('serveTime', $e->getMessage());

            return;
        }

        $this->occasion->refresh();
        $this->forget();
        $this->dispatch('notify', message: $this->occasion->serve_time ? 'Heure enregistrée : le rétroplanning suit.' : 'Heure retirée.');
    }

    public function addDish(Receptions $receptions): void
    {
        $this->authorizeEdit();
        $this->validate([
            'newRecipeId' => ['required', 'integer', 'exists:recipes,id'],
            'newCourse' => ['nullable', 'string'],
            'newServings' => ['required', 'numeric', 'min:0.5', 'max:50'],
        ], ['newRecipeId.required' => 'Choisissez une recette.'], ['newServings' => 'portions']);

        $receptions->addDish($this->occasion, (int) $this->newRecipeId, Course::tryFrom($this->newCourse), \App\Services\Planning\Appetites::clamp($this->newServings));

        $this->newRecipeId = null;
        $this->forget();
        $this->dispatch('notify', message: 'Plat ajouté au menu (et au planning).');
    }

    public function setCourse(int $mealId, string $course, Receptions $receptions): void
    {
        $this->authorizeEdit();
        $receptions->setCourse($this->meal($mealId), Course::tryFrom($course));
        $this->forget();
    }

    public function setServings(int $mealId, int|float|string $servings, WeekPlanner $planner): void
    {
        $this->authorizeEdit();

        try {
            $planner->update($this->meal($mealId), ['servings' => str_replace(',', '.', (string) $servings)]);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());
        }

        $this->forget();
    }

    public function removeDish(int $mealId, WeekPlanner $planner, Receptions $receptions): void
    {
        $this->authorizeEdit();
        $planner->delete($this->meal($mealId));
        $receptions->syncReminders($this->occasion);
        $this->forget();
    }

    /* ================================================================ Rétroplanning */

    public function toggleTask(string $key): void
    {
        $this->planner()->toggle($this->occasion, $key);
        $this->occasion->refresh();
        unset($this->timeline);
        $this->dispatch('reminders-changed');
    }

    /* ================================================================ Carte et souvenir */

    public function saveMessage(): void
    {
        $this->authorizeEdit();
        $this->validate(['menuMessage' => ['nullable', 'string', 'max:255']]);
        $this->occasion->update(['menu_message' => trim($this->menuMessage) ?: null]);
        $this->dispatch('notify', message: 'Mot de la carte enregistré.');
    }

    public function saveMemory(Receptions $receptions): void
    {
        $this->validate([
            'memoryNote' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $receptions->saveMemory($this->occasion, $this->memoryNote, $this->photo);
        $this->photo = null;
        $this->occasion->refresh();
        $this->dispatch('notify', message: 'Souvenir enregistré : il apparaît aussi sur la fiche des invités.');
    }

    public function removePhoto(Receptions $receptions): void
    {
        $receptions->saveMemory($this->occasion, $this->memoryNote, null, removePhoto: true);
        $this->occasion->refresh();
    }

    /* ================================================================ Données */

    #[Computed]
    public function courses(): Collection
    {
        return $this->planner()->courses($this->occasion);
    }

    #[Computed]
    public function timeline(): Collection
    {
        return $this->planner()->grouped($this->occasion);
    }

    /** Contraintes des invités, plat par plat. @return array<int, list<array>> */
    #[Computed]
    public function conflicts(): array
    {
        $guests = $this->occasion->guests()->with('restrictions.ingredient', 'restrictions.tag')->get()
            // Lot 26 (26.6) : membres des foyers reliés qui viennent, s'ils partagent leurs contraintes.
            ->concat(app(\App\Services\Linked\LinkedEaters::class)->forOccasion($this->occasion)->filter(fn ($e) => $e->restrictions->isNotEmpty()));

        if ($guests->isEmpty()) {
            return [];
        }

        $compatibility = app(GuestCompatibility::class);

        // Lot 26 : les plats apportés par les foyers reliés sont vérifiés aussi.
        return $this->occasion->meals()->concat(app(\App\Services\Linked\SharedMeals::class)->broughtDishes($this->occasion))
            ->filter(fn (PlannedMeal $m) => $m->isRecipe() && $m->recipe)
            ->mapWithKeys(fn (PlannedMeal $m) => [$m->id => $compatibility->conflicts($m->recipe, $guests)])
            ->filter()
            ->all();
    }

    #[Computed]
    public function recipes(): Collection
    {
        return Recipe::query()->active()->orderBy('title')->get(['id', 'title']);
    }

    public function render()
    {
        $this->occasion->loadMissing('guests', 'slot');
        $receptions = app(Receptions::class);

        return view('livewire.receptions.show', [
            'name' => $receptions->name($this->occasion),
            'receptions' => $receptions,
            'diners' => $this->planner()->diners($this->occasion),
            'menuText' => $this->planner()->menuText($this->occasion),
            'isPast' => $this->occasion->date->lt(today()),
            'canEdit' => auth()->user()->canEdit(),
            'linkedGuests' => app(\App\Services\Linked\SharedMeals::class)->guestsOf($this->occasion),
            'linkedEaters' => app(\App\Services\Linked\LinkedEaters::class)->forOccasion($this->occasion),
            'brought' => app(\App\Services\Linked\SharedMeals::class)->broughtDishes($this->occasion),
            'linkable' => app(\App\Services\Linked\HouseholdLinks::class)->linked(),
        ])->title($receptions->name($this->occasion));
    }

    /* ================================================================ Foyers reliés (26.5) */

    public ?int $inviteHouseholdId = null;

    public function inviteHousehold(\App\Services\Linked\SharedMeals $meals): void
    {
        $this->authorizeEdit();

        if (! $this->inviteHouseholdId) {
            return;
        }

        try {
            $meals->invite($this->occasion, (int) $this->inviteHouseholdId, auth()->user());
        } catch (InvalidArgumentException $e) {
            $this->addError('inviteHouseholdId', $e->getMessage());

            return;
        }

        $this->reset('inviteHouseholdId');
        $this->dispatch('notify', message: 'Invitation envoyée : elle apparaît chez eux, dans « Proches ».');
    }

    public function cancelHousehold(int $householdId, \App\Services\Linked\SharedMeals $meals): void
    {
        $this->authorizeEdit();
        $meals->cancel($this->occasion, $householdId);
        $this->forget();
    }

    private function planner(): ReceptionPlanner
    {
        return app(ReceptionPlanner::class);
    }

    private function meal(int $id): PlannedMeal
    {
        $meal = PlannedMeal::findOrFail($id);

        abort_unless($meal->date->isSameDay($this->occasion->date) && $meal->meal_slot_id === $this->occasion->meal_slot_id, 404);

        return $meal;
    }

    private function forget(): void
    {
        unset($this->courses, $this->timeline, $this->conflicts);
    }

    private function authorizeEdit(): void
    {
        abort_unless(auth()->user()->canEdit(), 403);
    }
}
