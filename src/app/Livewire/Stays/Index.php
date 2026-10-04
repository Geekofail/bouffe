<?php

namespace App\Livewire\Stays;

use App\Models\Stay;
use App\Services\Stays\StayService;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Séjours (lot 34) : la liste, et « Nouveau séjour ».
 */
#[Title('Séjours')]
class Index extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;

    public bool $creating = false;

    public string $name = '';

    public string $place = '';

    public string $startsOn = '';

    public string $endsOn = '';

    public function open(): void
    {
        $this->resetErrorBag();
        $this->startsOn = $this->startsOn ?: Carbon::today()->next(Carbon::SATURDAY)->toDateString();
        $this->endsOn = $this->endsOn ?: Carbon::parse($this->startsOn)->addDays(7)->toDateString();
        $this->creating = true;
    }

    public function close(): void
    {
        $this->creating = false;
    }

    public function create(StayService $stays): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $this->validate([
            'name' => 'required|string|max:100',
            'place' => 'nullable|string|max:150',
            'startsOn' => 'required|date',
            'endsOn' => 'required|date',
        ], [], ['name' => 'nom', 'place' => 'lieu', 'startsOn' => 'début', 'endsOn' => 'fin']);

        try {
            $stay = $stays->create(['name' => $this->name, 'place' => $this->place, 'starts_on' => $this->startsOn, 'ends_on' => $this->endsOn]);
        } catch (InvalidArgumentException $e) {
            $this->addError('endsOn', $e->getMessage());

            return;
        }

        $stays->addHousehold($stay);
        $this->redirectRoute('stays.show', ['stay' => $stay, 'onglet' => 'participants'], navigate: true);
    }

    /** Lot 42 (42.1) : répondre à une invitation à co-organiser. */
    public function respond(int $rowId, bool $accept, \App\Services\Stays\StayCoorganizers $coorganizers): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        try {
            $row = $coorganizers->respond($rowId, $accept);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());

            return;
        }

        if ($accept) {
            $this->redirectRoute('stays.show', ['stay' => $row->stay_id, 'onglet' => 'participants'], navigate: true);

            return;
        }

        $this->dispatch('notify', message: 'Invitation refusée.');
    }

    public function render()
    {
        $coorganizers = app(\App\Services\Stays\StayCoorganizers::class);
        $own = Stay::query()->withCount(['participants', 'meals'])->orderByDesc('starts_on')->get();
        // Lot 42 (42.1) : les séjours que nous co-organisons, rangés chez un foyer relié.
        $shared = $coorganizers->coorganized()->loadCount(['participants', 'meals']);
        $stays = $own->concat($shared)->sortByDesc(fn (Stay $stay) => $stay->starts_on->toDateString())->values();

        return view('livewire.stays.index', [
            'upcoming' => $stays->reject(fn (Stay $stay) => $stay->isPast())->sortBy(fn (Stay $stay) => $stay->starts_on->toDateString())->values(),
            'past' => $stays->filter(fn (Stay $stay) => $stay->isPast())->values(),
            'invitations' => $coorganizers->invitationsFor(),
            'mine' => (int) \App\Support\CurrentHousehold::id(),
        ]);
    }
}
