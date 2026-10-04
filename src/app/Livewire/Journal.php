<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\Activity\ActivityLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Journal du foyer (30.2) : qui a fait quoi ces 30 derniers jours. Visible des membres du foyer.
 */
#[Title('Journal du foyer')]
class Journal extends Component
{
    #[Url(as: 'personne', except: 0)]
    public int $person = 0;

    #[Url(as: 'quoi', except: '')]
    public string $family = '';

    #[Computed]
    public function members(): Collection
    {
        return User::query()->inHousehold()->orderBy('name')->get(['id', 'name']);
    }

    /** Lignes regroupées par jour. @return Collection<string, Collection> */
    #[Computed]
    public function days(): Collection
    {
        $family = array_key_exists($this->family, ActivityLog::TYPES) ? $this->family : null;

        return app(ActivityLog::class)->recent($this->person ?: null, $family)
            ->groupBy(fn ($event) => $event->updated_at->toDateString());
    }

    public function dayLabel(string $date): string
    {
        $day = Carbon::parse($date);

        return match (true) {
            $day->isToday() => 'Aujourd\'hui',
            $day->isYesterday() => 'Hier',
            default => ucfirst($day->locale('fr')->isoFormat('dddd D MMMM')),
        };
    }

    public function render()
    {
        return view('livewire.journal', ['families' => ActivityLog::TYPES]);
    }
}
