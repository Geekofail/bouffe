<?php

namespace App\Livewire;

use App\Models\Reminder;
use App\Services\Planning\EveningRendezvous;
use App\Services\Planning\PrepReminderPlanner;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * « Ce soir » (lot 37, 37.1, règle R38) : la page ouverte par la notification du soir.
 *
 * Trois choses, dans l'ordre où on les fait : clôturer les repas du jour (mangé · pas fait · restes à
 * placer), préparer demain (décongeler, faire tremper, gamelles), et un coup d'œil au menu de demain.
 * Rien n'est fait sans un geste.
 */
#[Title('Ce soir')]
class Evening extends Component
{
    use Concerns\ClosesMeals;
    use Concerns\OffersUndo;
    use Concerns\PlacesLeftovers;

    /** « Tout comme prévu » : les repas d'aujourd'hui et d'hier, mangés avec le retrait du stock (37.2), annulable. */
    public function closeAllAsPlanned(EveningRendezvous $evening): void
    {
        [$recent] = $this->split($evening->toClose(Carbon::now()));
        $this->closeAsPlannedWithUndo($recent, 'Repas marqués mangés comme prévu');
    }

    /**
     * Aujourd'hui et hier d'un côté ; les jours d'avant (rattrapage) de l'autre.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection}
     */
    private function split(\Illuminate\Support\Collection $meals): array
    {
        $yesterday = Carbon::yesterday()->toDateString();
        [$recent, $older] = $meals->partition(fn ($meal) => $meal->date->toDateString() >= $yesterday);

        return [$recent->values(), $older->values()];
    }

    public function reminderDone(int $reminderId, PrepReminderPlanner $planner): void
    {
        $planner->markDone(Reminder::findOrFail($reminderId));
        $this->dispatch('reminders-changed');
    }

    public function reminderIgnored(int $reminderId, PrepReminderPlanner $planner): void
    {
        $planner->markIgnored(Reminder::findOrFail($reminderId));
        $this->dispatch('reminders-changed');
    }

    /** Le retrait du stock ou les restes rangés depuis la fenêtre : la page se redessine. */
    #[On('stock-changed')]
    #[On('meal-planned')]
    public function refresh(): void {}

    public function render(EveningRendezvous $evening, PrepReminderPlanner $planner)
    {
        $now = Carbon::now();
        // Les rappels suivent le planning (un repas déplacé entre-temps).
        $planner->sync($now->copy()->startOfDay(), $now->copy()->addDays(3));

        $toClose = $evening->toClose($now);
        [$recent, $older] = $this->split($toClose);
        $settings = EveningRendezvous::settings(auth()->user());

        return view('livewire.evening', [
            'now' => $now,
            'toClose' => $toClose,
            'recent' => $recent,
            'older' => $older,
            'leftovers' => $evening->leftovers($now),
            'preparations' => $evening->preparations($now),
            'lunchboxes' => $evening->lunchboxes($now),
            'tomorrow' => $evening->tomorrow($now),
            'tomorrowDate' => $now->copy()->addDay(),
            'settings' => $settings,
            'canEdit' => auth()->user()->canEdit(),
            'nothingLeft' => $toClose->isEmpty() && $evening->preparations($now)->isEmpty() && $evening->lunchboxes($now)->isEmpty(),
        ]);
    }
}
