<?php

namespace App\Livewire\Kitchen;

use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Reminder;
use App\Models\ShoppingList;
use App\Services\Planning\OccasionService;
use App\Services\Planning\PrepReminderPlanner;
use App\Services\Shopping\ShoppingListManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Écran de cuisine (lot 35, 35.1) : pour une tablette posée en cuisine.
 *
 * Menu du jour et du lendemain, minuteurs en cours (ceux de ce navigateur), préparations à faire,
 * liste de courses en cours. Lisible de loin, rafraîchi toutes les minutes, sombre le soir.
 */
#[Layout('layouts.kitchen')]
#[Title('Écran de cuisine')]
class Screen extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;

    /** Nombre d'articles de la liste affichés. */
    public const SHOPPING_LIMIT = 14;

    public string $newItem = '';

    public function reminderDone(int $reminderId, PrepReminderPlanner $reminders): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $reminders->markDone(Reminder::findOrFail($reminderId));
    }

    /** « Il n'y a plus de lait » : ajouté à la liste en cours (ou à une nouvelle liste). */
    public function addItem(ShoppingListManager $manager): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $this->validate(['newItem' => 'required|string|max:120'], [], ['newItem' => 'article']);

        try {
            $item = $manager->addManual($manager->currentOrNew(), $this->newItem);
        } catch (InvalidArgumentException $e) {
            $this->addError('newItem', $e->getMessage());

            return;
        }

        $this->reset('newItem');
        $this->dispatch('notify', message: '« '.$item->label.' » ajouté à la liste.');
    }

    /**
     * Repas d'un jour, par créneau actif.
     *
     * @return Collection<int, array{slot: MealSlot, meals: Collection<int, PlannedMeal>, occasion: string|null}>
     */
    public function day(Carbon $date, Collection $slots): Collection
    {
        $meals = PlannedMeal::query()->whereDate('date', $date->toDateString())
            ->whereIn('meal_slot_id', $slots->pluck('id'))
            ->with(['recipe', 'leftoverOf.recipe', 'forPerson', 'cook:id,name'])
            ->orderBy('position')->get()
            ->sortBy(fn (PlannedMeal $meal) => $meal->course?->order() ?? 3)
            ->groupBy('meal_slot_id');
        $occasions = app(OccasionService::class)->forRange($date, $date)->keyBy('meal_slot_id');
        $canteen = app(\App\Services\People\CanteenCalendar::class);

        return $slots->map(fn (MealSlot $slot) => [
            'slot' => $slot,
            'canteen' => $canteen->isLunch($slot) ? $canteen->onDay($date) : collect(),
            'meals' => $meals->get($slot->id, collect())->values(),
            'occasion' => ($occasion = $occasions->get($slot->id)) ? app(OccasionService::class)->summary($occasion) : null,
        ]);
    }

    public function render(PrepReminderPlanner $reminders)
    {
        $today = Carbon::today();
        $slots = MealSlot::query()->active()->ordered()->get();
        $reminders->sync($today, $today->copy()->addDays(2));

        $list = ShoppingList::query()->active()->orderByDesc('period_start')->orderByDesc('id')->first();
        $items = $list?->items()->where('is_removed', false)->where('is_checked', false)
            ->with('aisle')->get()
            ->sortBy(fn ($item) => [$item->aisle?->sort_order ?? 999, mb_strtolower($item->label)])
            ->values() ?? collect();

        return view('livewire.kitchen.screen', [
            'today' => $today,
            'tomorrow' => $today->copy()->addDay(),
            'todayMeals' => $this->day($today, $slots),
            'tomorrowMeals' => $this->day($today->copy()->addDay(), $slots),
            'reminders' => $reminders->due($today->copy()->addDay()->endOfDay())->take(6),
            'list' => $list,
            'items' => $items->take(self::SHOPPING_LIMIT),
            'more' => max(0, $items->count() - self::SHOPPING_LIMIT),
            'updatedAt' => now(),
            'choices' => app(\App\Services\People\ChildChoices::class)->open()->take(3),
        ]);
    }
}
