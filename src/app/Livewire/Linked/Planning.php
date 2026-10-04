<?php

namespace App\Livewire\Linked;

use App\Models\Household;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Services\Linked\CalendarFeed;
use App\Services\Linked\HouseholdLinks;
use App\Services\Planning\WeekPlanner;
use App\Support\CurrentHousehold;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Planning d'un foyer relié (26.4) : en lecture (« qu'est-ce qu'on mange chez les parents dimanche ? »)
 * ou en écriture si ce foyer l'a ouvert ainsi (les parents qui gardent les enfants la semaine).
 * En écriture, on ajoute une recette de **leur** carnet ou un texte libre, et on retire un repas.
 */
#[Title('Planning des proches')]
class Planning extends Component
{
    #[Locked]
    public int $householdId;

    #[Url(as: 'semaine', except: '')]
    public string $week = '';

    /* Ajout (écriture) */
    public ?string $addDate = null;

    public ?int $addSlotId = null;

    public string $addText = '';

    public ?int $addRecipeId = null;

    public function mount(Household $household): void
    {
        $this->householdId = $household->id;
        abort_if($this->access() === 'none', 404);
    }

    public function access(): string
    {
        return app(HouseholdLinks::class)->planningAccess($this->householdId, (int) CurrentHousehold::id());
    }

    public function previousWeek(): void
    {
        $this->week = $this->weekStart()->subWeek()->toDateString();
    }

    public function nextWeek(): void
    {
        $this->week = $this->weekStart()->addWeek()->toDateString();
    }

    public function thisWeek(): void
    {
        $this->week = '';
    }

    public function openAdd(string $date, int $slotId): void
    {
        $this->guardWrite();
        $this->addDate = Carbon::parse($date)->toDateString();
        $this->addSlotId = $slotId;
        $this->reset('addText', 'addRecipeId');
        $this->resetErrorBag();
    }

    public function closeAdd(): void
    {
        $this->reset('addDate', 'addSlotId', 'addText', 'addRecipeId');
    }

    public function add(): void
    {
        $this->guardWrite();

        if (! $this->addDate || ! $this->addSlotId) {
            return;
        }

        try {
            CurrentHousehold::run($this->householdId, function () {
                $slot = MealSlot::query()->findOrFail($this->addSlotId);
                $planner = app(WeekPlanner::class);

                if ($this->addRecipeId) {
                    $recipe = Recipe::query()->active()->findOrFail($this->addRecipeId);
                    $planner->addRecipe($this->addDate, $slot, $recipe, null, 'Ajouté par '.Auth::user()->name);
                } else {
                    $planner->addFree($this->addDate, $slot, $this->addText, 'Ajouté par '.Auth::user()->name);
                }
            });
        } catch (\InvalidArgumentException $e) {
            $this->addError('addText', $e->getMessage());

            return;
        }

        $this->closeAdd();
        $this->dispatch('notify', message: 'Ajouté à leur planning.');
    }

    public function remove(int $mealId): void
    {
        $this->guardWrite();

        CurrentHousehold::run($this->householdId, function () use ($mealId) {
            if ($meal = PlannedMeal::query()->find($mealId)) {
                app(WeekPlanner::class)->delete($meal);
            }
        });
    }

    private function guardWrite(): void
    {
        abort_unless($this->access() === 'write', 403, 'Ce foyer ouvre son planning en lecture seulement.');
    }

    private function weekStart(): Carbon
    {
        return app(WeekPlanner::class)->weekStart($this->week ?: null);
    }

    public function render(CalendarFeed $calendar)
    {
        $weekStart = $this->weekStart();
        $household = Household::findOrFail($this->householdId);
        $writable = $this->access() === 'write';   // calculé dans notre foyer, avant de lire le leur

        [$mealSlots, $meals, $recipes] = CurrentHousehold::run($household, fn () => [
            MealSlot::query()->active()->ordered()->get(),
            app(WeekPlanner::class)->mealsForWeek($weekStart)->groupBy(fn (PlannedMeal $m) => $m->date->toDateString().'|'.$m->meal_slot_id),
            $writable && $this->addDate ? Recipe::query()->active()->orderBy('title')->get(['id', 'title']) : collect(),
        ]);

        return view('livewire.linked.planning', [
            'household' => $household,
            'writable' => $writable,
            'days' => app(WeekPlanner::class)->days($weekStart),
            'weekStart' => $weekStart,
            'mealSlots' => $mealSlots,   // pas « slots » : nom réservé par Livewire
            'meals' => $meals,
            'recipes' => $recipes,
            'calendarUrl' => $calendar->url(Auth::user(), $household->id),
        ]);
    }
}
