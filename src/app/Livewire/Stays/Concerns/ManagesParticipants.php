<?php

namespace App\Livewire\Stays\Concerns;

use App\Models\Guest;
use App\Models\Household;
use App\Models\StayParticipant;
use App\Services\Linked\HouseholdLinks;
use App\Services\Stays\StayCoorganizers;
use App\Services\Stays\StayService;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;

/**
 * Séjour — participants (34.1) : le foyer, des invités du carnet, des foyers reliés, d'autres
 * personnes ; appétit, groupe (qui paie ensemble) et jours de présence.
 */
trait ManagesParticipants
{
    public string $guestId = '';

    public string $linkedId = '';

    public string $personName = '';

    public string $personAppetite = 'normal';

    public string $personGroup = '';

    /** Lot 42 (42.1) : foyer relié à inviter à co-organiser. */
    public string $coorganizerId = '';

    public function addHousehold(StayService $stays): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $added = $stays->addHousehold($this->stay);
        $this->afterParticipants($added ? $added.' personne'.($added > 1 ? 's' : '').' du foyer ajoutée'.($added > 1 ? 's' : '').'.' : 'Tout le foyer participe déjà.');
    }

    public function addGuest(StayService $stays): void
    {
        if (! $this->allowedToEdit() || ! $this->guestId) {
            return;
        }

        try {
            $guest = Guest::query()->findOrFail((int) $this->guestId);
            $stays->addGuest($this->stay, $guest);
        } catch (InvalidArgumentException $e) {
            $this->addError('guestId', $e->getMessage());

            return;
        }

        $this->guestId = '';
        $this->afterParticipants($guest->name.' ajouté'.($guest->is_child ? '' : '').'.');
    }

    public function addLinked(StayService $stays): void
    {
        if (! $this->linkedId || ! $this->organizerOnly()) {
            return;
        }

        try {
            $household = $this->linkedHouseholds->firstWhere('id', (int) $this->linkedId) ?? throw new InvalidArgumentException('Ce foyer n\'est pas relié au vôtre.');
            $added = $stays->addLinkedHousehold($this->stay, $household);
        } catch (InvalidArgumentException $e) {
            $this->addError('linkedId', $e->getMessage());

            return;
        }

        $this->linkedId = '';
        $this->afterParticipants($added ? $added.' personne'.($added > 1 ? 's' : '').' de « '.$household->name.' » ajoutée'.($added > 1 ? 's' : '').'.' : 'Ce foyer participe déjà.');
    }

    public function addPerson(StayService $stays): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $this->validate(['personName' => 'required|string|max:60', 'personGroup' => 'nullable|string|max:60'], [], ['personName' => 'prénom', 'personGroup' => 'groupe']);

        try {
            $stays->addPerson($this->stay, $this->personName, $this->personAppetite, $this->personGroup);
        } catch (InvalidArgumentException $e) {
            $this->addError('personName', $e->getMessage());

            return;
        }

        $this->reset('personName', 'personAppetite');
        $this->afterParticipants('Personne ajoutée.');
    }

    public function updateParticipant(int $id, string $field, ?string $value, StayService $stays): void
    {
        if (! $this->allowedToEdit() || ! in_array($field, ['name', 'appetite', 'group_label', 'present_from', 'present_to'], true)) {
            return;
        }

        if (! $this->canManageParticipant($id)) {
            return;
        }

        try {
            $stays->updateParticipant($this->participant($id), [$field => $value]);
        } catch (InvalidArgumentException $e) {
            $this->addError('participants', $e->getMessage());

            return;
        }

        $this->resetErrorBag('participants');
        unset($this->eaters);
    }

    public function removeParticipant(int $id): void
    {
        if (! $this->allowedToEdit() || ! $this->canManageParticipant($id)) {
            return;
        }

        $this->participant($id)->delete();
        $this->afterParticipants('Participant retiré.');
    }

    /** Lot 42 (42.1) : chaque foyer gère ses participants. */
    private function canManageParticipant(int $id): bool
    {
        if (app(StayCoorganizers::class)->canManageParticipant($this->participant($id), $this->stay)) {
            return true;
        }

        $this->dispatch('notify', type: 'warning', message: 'Cette personne est gérée par un autre foyer du séjour.');

        return false;
    }

    /* ================================================================ Co-organiser (lot 42, 42.1) */

    public function inviteCoorganizer(StayCoorganizers $coorganizers): void
    {
        if (! $this->coorganizerId || ! $this->organizerOnly()) {
            return;
        }

        $this->resetErrorBag('coorganizerId');

        try {
            $row = $coorganizers->invite($this->stay, (int) $this->coorganizerId, auth()->user());
        } catch (InvalidArgumentException $e) {
            $this->addError('coorganizerId', $e->getMessage());

            return;
        }

        $this->coorganizerId = '';
        unset($this->coorganizerRows);
        $this->dispatch('notify', message: '« '.$row->household?->name.' » est invité à co-organiser le séjour.');
    }

    public function removeCoorganizer(int $householdId, StayCoorganizers $coorganizers): void
    {
        if (! $this->organizerOnly()) {
            return;
        }

        $coorganizers->remove($this->stay, $householdId);
        unset($this->coorganizerRows, $this->eaters);
        $this->stay->unsetRelation('participants');
        $this->dispatch('notify', message: 'Foyer retiré du séjour. Ses dépenses restent dans les comptes.');
    }

    /** @return Collection<int, \App\Models\StayHousehold> */
    #[Computed]
    public function coorganizerRows(): Collection
    {
        return app(StayCoorganizers::class)->rows($this->stay);
    }

    private function participant(int $id): StayParticipant
    {
        return $this->stay->participants()->findOrFail($id);
    }

    private function afterParticipants(string $message): void
    {
        $this->stay->unsetRelation('participants');
        unset($this->eaters, $this->availableGuests);
        $this->resetErrorBag();
        $this->dispatch('notify', message: $message);
    }

    /** Invités du carnet qui ne participent pas encore. @return Collection<int, Guest> */
    #[Computed]
    public function availableGuests(): Collection
    {
        return Guest::query()->whereNull('archived_at')
            ->whereNotIn('id', $this->stay->participants()->whereNotNull('guest_id')->pluck('guest_id'))
            ->orderBy('name')->get(['id', 'name', 'group_name']);
    }

    /** @return Collection<int, Household> */
    #[Computed]
    public function linkedHouseholds(): Collection
    {
        return app(HouseholdLinks::class)->linked();
    }

    /** Participants et leurs contraintes connues. */
    #[Computed]
    public function eaters(): Collection
    {
        return app(StayService::class)->eaters($this->stay);
    }
}
