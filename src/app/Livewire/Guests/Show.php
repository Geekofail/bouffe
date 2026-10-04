<?php

namespace App\Livewire\Guests;

use App\Models\Guest;
use App\Services\Planning\GuestCompatibility;
use App\Services\Planning\GuestManager;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;

class Show extends Component
{
    public Guest $guest;

    public function edit(): void
    {
        $this->dispatch('edit-guest', guestId: $this->guest->id)->to(GuestEditor::class);
    }

    #[On('guest-saved')]
    public function refreshGuest(): void
    {
        $this->guest->refresh();
    }

    public function toggleArchive(GuestManager $manager): void
    {
        $archived = ! $this->guest->isArchived();
        $manager->setArchived($this->guest, $archived);

        $this->dispatch('notify', message: $archived ? 'Invité archivé : il n\'est plus proposé, son historique est conservé.' : 'Invité sorti des archives.');
    }

    public function delete(GuestManager $manager): void
    {
        try {
            $name = $this->guest->name;
            $manager->delete($this->guest);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());

            return;
        }

        session()->flash('status', "« {$name} » supprimé du carnet.");
        $this->redirectRoute('guests.index', navigate: true);
    }

    public function render(GuestCompatibility $compatibility)
    {
        $this->guest->load('restrictions.ingredient', 'restrictions.tag');

        return view('livewire.guests.show', [
            'history' => $compatibility->history($this->guest),
        ])->title($this->guest->name);
    }
}
