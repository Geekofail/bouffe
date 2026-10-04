<?php

namespace App\Livewire\Receptions;

use App\Models\MealSlot;
use App\Services\Receptions\ReceptionPlanner;
use App\Services\Receptions\Receptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Réceptions (module 21) : celles à venir, les souvenirs des précédentes, et « Organiser ».
 */
#[Title('Réceptions')]
class Index extends Component
{
    public string $date = '';

    public ?int $slotId = null;

    public string $title = '';

    public string $serveTime = '';

    public bool $showCreate = false;

    public function mount(): void
    {
        $this->date = Carbon::today()->next(Carbon::SATURDAY)->toDateString();
        $this->slotId = MealSlot::query()->active()->orderBy('sort_order')->get()->last()?->id;
        $this->serveTime = '19:30';
    }

    public function create(Receptions $receptions): void
    {
        if (! auth()->user()->canEdit()) {
            abort(403);
        }

        $this->validate([
            'date' => ['required', 'date'],
            'slotId' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:150'],
            'serveTime' => ['nullable', 'string', 'max:5'],
        ], attributes: ['title' => 'nom', 'slotId' => 'créneau', 'serveTime' => 'heure']);

        try {
            $occasion = $receptions->create($this->date, (int) $this->slotId, $this->title, $this->serveTime);
        } catch (InvalidArgumentException $e) {
            $this->addError('title', $e->getMessage());

            return;
        }

        $this->redirectRoute('receptions.show', ['occasion' => $occasion->id], navigate: true);
    }

    #[Computed]
    public function upcoming(): Collection
    {
        return app(Receptions::class)->upcoming();
    }

    #[Computed]
    public function past(): Collection
    {
        return app(Receptions::class)->past();
    }

    #[Computed]
    public function slots(): Collection
    {
        return MealSlot::query()->active()->orderBy('sort_order')->get();
    }

    public function render()
    {
        return view('livewire.receptions.index', [
            'invitations' => app(\App\Services\Linked\SharedMeals::class)->invitationsFor(),
            'receptions' => app(Receptions::class),
            'planner' => app(ReceptionPlanner::class),
        ]);
    }
}
