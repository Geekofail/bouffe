<?php

namespace App\Livewire\Settings;

use App\Enums\RestrictionType;
use App\Enums\UserRole;
use App\Models\HouseholdPerson;
use App\Models\Ingredient;
use App\Models\Invitation;
use App\Models\PersonRestriction;
use App\Models\Tag;
use App\Models\User;
use App\Services\Households\HouseholdManager;
use App\Services\Planning\HouseholdService;
use App\Support\CurrentHousehold;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Paramètres → Foyer (18.1 et 18.5 ; lot 24 : 25.2, 25.3, 25.8 ; lot 39 : 39.1).
 *
 * Qui vit ici (les personnes, compte ou pas : appétit, couleur, goûts, cantine), et ce que
 * chaque compte a le droit de faire. Les goûts déclenchent les mêmes alertes que ceux des invités.
 */
#[Title('Foyer')]
class Household extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;

    /* ---------------------------------------------------------------- Contrainte */

    public ?int $restrictionPersonId = null;

    public string $restrictionType = 'allergy';

    public int|string|null $subjectId = null;

    public string $note = '';

    /* ---------------------------------------------------------------- Membre */

    public bool $showMember = false;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $role = 'viewer';

    public function openRestriction(int $personId): void
    {
        $this->reset('restrictionType', 'subjectId', 'note');
        $this->resetErrorBag();
        $this->restrictionPersonId = HouseholdPerson::query()->findOrFail($personId)->id;
    }

    public function closeRestriction(): void
    {
        $this->reset('restrictionPersonId', 'subjectId', 'note');
    }

    public function updatedRestrictionType(): void
    {
        $this->subjectId = null;
    }

    public function addRestriction(HouseholdService $household): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $this->validate([
            'subjectId' => 'required|integer',
            'note' => 'nullable|string|max:150',
        ], [], ['subjectId' => $this->type()->usesIngredient() ? 'ingrédient' : 'catégorie', 'note' => 'note']);

        try {
            $household->addRestriction(
                HouseholdPerson::query()->findOrFail($this->restrictionPersonId),
                $this->type(),
                (int) $this->subjectId,
                trim($this->note) ?: null,
            );
        } catch (InvalidArgumentException $e) {
            $this->addError('subjectId', $e->getMessage());

            return;
        }

        $this->closeRestriction();
        unset($this->people);
        $this->dispatch('notify', message: 'Contrainte ajoutée.');
    }

    public function removeRestriction(int $restrictionId): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        PersonRestriction::query()->whereKey($restrictionId)->delete();
        unset($this->people);
        $this->dispatch('notify', message: 'Contrainte retirée.');
    }

    /* ---------------------------------------------------------------- Foyer (lot 24) */

    public string $householdName = '';

    /* ---------------------------------------------------------------- Personnes du foyer (lot 32 ; lot 39, 39.1) */

    /** Personne en cours de modification : 0 pour une nouvelle, null si la fenêtre est fermée. */
    public ?int $editingPersonId = null;

    /** @var array{name: string, user_id: string, appetite: string, color: string, at_table: bool, canteen_days: list<string>, canteen_name: string, share_tastes: bool} */
    public array $person = [];

    /** @var array<string, string> part de chaque appétit, telle que saisie (« 0,75 ») */
    public array $parts = [];

    public string $inviteRole = 'full';

    public string $inviteEmail = '';

    /** Dernier lien d'invitation créé : montré une seule fois (seul son condensé est gardé). */
    public ?string $inviteUrl = null;

    public string $deleteConfirmation = '';

    public bool $showDelete = false;

    public function mount(): void
    {
        $household = CurrentHousehold::get();
        $this->householdName = (string) $household?->name;
        $appetites = app(\App\Services\Planning\Appetites::class);

        // Lot 39 : la table par défaut devient la liste des personnes, telle quelle (les portions ne changent pas).
        if (auth()->user()?->canEdit()) {
            app(\App\Services\People\HouseholdPeople::class)->ensure();
        }

        $this->parts = array_map(fn (float $part) => \App\Services\Planning\Appetites::formatPart($part), $appetites->parts());
    }

    private function owner(): bool
    {
        if (auth()->user()?->managesHousehold()) {
            return true;
        }

        $this->dispatch('notify', type: 'warning', message: 'Réservé aux responsables du foyer.');

        return false;
    }

    public function saveHousehold(HouseholdManager $manager): void
    {
        if (! $this->owner()) {
            return;
        }

        $this->validate([
            'householdName' => 'required|string|max:100',
        ], [], ['householdName' => 'nom du foyer']);

        $manager->rename(CurrentHousehold::get(), $this->householdName);
        unset($this->household);
        $this->dispatch('notify', message: 'Foyer enregistré.');
    }

    public function openPerson(int $personId = 0, ?int $userId = null): void
    {
        $this->resetErrorBag();
        $existing = $personId ? HouseholdPerson::query()->findOrFail($personId) : null;
        $user = $userId ? User::query()->inHousehold()->find($userId) : null;

        $this->editingPersonId = $existing?->id ?? 0;
        $this->person = [
            'name' => (string) ($existing?->name ?? $user?->name ?? ''),
            'user_id' => (string) ($existing?->user_id ?? $user?->id ?? ''),
            'appetite' => (string) ($existing?->appetite ?? 'normal'),
            'color' => (string) ($existing?->color ?? ''),
            'at_table' => (bool) ($existing?->at_table ?? true),
            'canteen_days' => array_map('strval', $existing?->canteenDays() ?? []),
            'canteen_name' => (string) ($existing?->canteen_name ?? ''),
            'share_tastes' => (bool) ($existing?->share_tastes ?? false),
        ];
    }

    public function closePerson(): void
    {
        $this->editingPersonId = null;
    }

    /** Le compte choisi donne son prénom à la personne, si elle n'en a pas encore. */
    public function updatedPersonUserId(mixed $value): void
    {
        if ($value && trim((string) ($this->person['name'] ?? '')) === '') {
            $this->person['name'] = (string) User::query()->inHousehold()->find((int) $value)?->name;
        }
    }

    public function savePerson(\App\Services\People\HouseholdPeople $people): void
    {
        if (! $this->allowedToEdit() || $this->editingPersonId === null) {
            return;
        }

        $this->validate([
            'person.name' => 'nullable|string|max:60',
            'person.canteen_name' => 'nullable|string|max:80',
        ], [], ['person.name' => 'prénom', 'person.canteen_name' => 'cantine']);

        try {
            $saved = $people->save($this->editingPersonId ? $people->find($this->editingPersonId) : null, $this->person);
        } catch (InvalidArgumentException $e) {
            $this->addError('person.name', $e->getMessage());

            return;
        }

        app(\App\Services\People\CanteenCalendar::class)->forget();
        $this->editingPersonId = null;
        unset($this->people);
        $this->dispatch('notify', message: $saved->name.' : enregistré. À table d\'habitude : '.app(\App\Services\Planning\OccasionService::class)->summary(null).'.');
    }

    public function deletePerson(\App\Services\People\HouseholdPeople $people): void
    {
        if (! $this->allowedToEdit() || ! $this->editingPersonId) {
            return;
        }

        $person = $people->find($this->editingPersonId);

        try {
            $people->delete($person);
        } catch (InvalidArgumentException $e) {
            $this->addError('person.name', $e->getMessage());

            return;
        }

        app(\App\Services\People\CanteenCalendar::class)->forget();
        $this->editingPersonId = null;
        unset($this->people);
        $this->dispatch('notify', message: $person->name.' ne fait plus partie de la liste.');
    }

    public function movePerson(int $personId, int $direction, \App\Services\People\HouseholdPeople $people): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $people->move($people->find($personId), $direction);
        unset($this->people);
    }

    public function saveParts(\App\Services\Planning\Appetites $appetites): void
    {
        if (! $this->owner()) {
            return;
        }

        try {
            $appetites->saveParts($this->parts);
        } catch (\InvalidArgumentException $e) {
            $this->addError('parts', $e->getMessage());

            return;
        }

        $this->resetErrorBag('parts');
        $this->dispatch('notify', message: 'Parts enregistrées.');
    }

    /* ---------------------------------------------------------------- Membres et rôles (25.3) */

    public function openMember(): void
    {
        $this->reset('name', 'email', 'password', 'role');
        $this->resetErrorBag();
        $this->showMember = true;
    }

    public function closeMember(): void
    {
        $this->showMember = false;
    }

    /** Créer directement un compte (sans invitation), rattaché à ce foyer. */
    public function addMember(HouseholdManager $manager): void
    {
        if (! $this->owner()) {
            return;
        }

        $this->validate([
            'name' => 'required|string|max:100',
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')],
            'password' => 'required|string|min:8',
            'role' => ['required', Rule::enum(UserRole::class)],
        ], [
            'email.unique' => 'Un compte existe déjà avec cette adresse : envoyez-lui plutôt une invitation.',
        ], ['name' => 'prénom', 'email' => 'adresse e-mail', 'password' => 'mot de passe', 'role' => 'rôle']);

        $user = User::create([
            'name' => trim($this->name),
            'email' => mb_strtolower(trim($this->email)),
            'password' => $this->password,
        ]);
        $manager->attach(CurrentHousehold::get(), $user, UserRole::from($this->role));

        $this->showMember = false;
        $this->reset('name', 'email', 'password');
        unset($this->members);
        $this->dispatch('notify', message: 'Membre ajouté.');
    }

    public function changeRole(int $userId, string $role, HouseholdManager $manager): void
    {
        if (! $this->owner()) {
            return;
        }

        $user = User::query()->inHousehold()->findOrFail($userId);
        $target = UserRole::tryFrom($role) ?? UserRole::Full;

        if ($user->is(auth()->user()) && $target !== UserRole::Owner) {
            $this->dispatch('notify', type: 'warning', message: 'Vous ne pouvez pas retirer vos propres droits.');

            return;
        }

        try {
            $manager->changeRole(CurrentHousehold::get(), $user, $target);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());

            return;
        }

        unset($this->members);
        $this->dispatch('notify', message: "{$user->name} : {$target->label()}.");
    }

    /** Retirer un membre du foyer : son compte reste, il n'a plus accès à ce foyer. */
    public function removeMember(int $userId, HouseholdManager $manager): void
    {
        if (! $this->owner()) {
            return;
        }

        $user = User::query()->inHousehold()->findOrFail($userId);

        if ($user->is(auth()->user())) {
            return;
        }

        try {
            $manager->detach(CurrentHousehold::get(), $user);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());

            return;
        }

        unset($this->members);
        $this->dispatch('notify', message: "{$user->name} ne fait plus partie du foyer.");
    }

    /** Quitter ce foyer (25.8). */
    public function leave(HouseholdManager $manager)
    {
        try {
            $manager->detach(CurrentHousehold::get(), auth()->user());
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());

            return null;
        }

        return $this->redirectRoute('dashboard');
    }

    /* ---------------------------------------------------------------- Invitations (25.2) */

    public function invite(HouseholdManager $manager): void
    {
        if (! $this->owner()) {
            return;
        }

        $this->validate([
            'inviteRole' => ['required', Rule::enum(UserRole::class)],
            'inviteEmail' => ['nullable', 'email', 'max:191'],
        ], [], ['inviteRole' => 'rôle', 'inviteEmail' => 'adresse e-mail']);

        try {
            $result = $manager->invite(CurrentHousehold::get(), UserRole::from($this->inviteRole), auth()->user(), $this->inviteEmail);
        } catch (InvalidArgumentException $e) {
            $this->addError('inviteEmail', $e->getMessage());

            return;
        }

        $this->inviteUrl = $result['url'];
        $this->reset('inviteEmail');
        unset($this->invitations);
    }

    public function revokeInvitation(int $id, HouseholdManager $manager): void
    {
        if (! $this->owner()) {
            return;
        }

        if ($invitation = Invitation::query()->where('household_id', CurrentHousehold::id())->find($id)) {
            $manager->revoke($invitation);
        }

        unset($this->invitations);
    }

    /* ---------------------------------------------------------------- Suppression (25.8) */

    public function requestDeletion(HouseholdManager $manager): void
    {
        if (! $this->owner()) {
            return;
        }

        $household = CurrentHousehold::get();
        $this->resetErrorBag('deleteConfirmation');

        if (mb_strtolower(trim($this->deleteConfirmation)) !== mb_strtolower($household->name)) {
            $this->addError('deleteConfirmation', 'Tapez exactement le nom du foyer pour confirmer.');

            return;
        }

        $manager->requestDeletion($household);
        $this->reset('deleteConfirmation', 'showDelete');
        unset($this->household);
        $this->dispatch('notify', type: 'warning', message: 'Le foyer sera supprimé dans '.\App\Models\Household::DELETION_DELAY_DAYS.' jours. Vous pouvez encore annuler.');
    }

    public function cancelDeletion(HouseholdManager $manager): void
    {
        if (! $this->owner()) {
            return;
        }

        $manager->cancelDeletion(CurrentHousehold::get());
        unset($this->household);
        $this->dispatch('notify', message: 'Suppression annulée.');
    }

    /* ---------------------------------------------------------------- Données */

    public function type(): RestrictionType
    {
        return RestrictionType::tryFrom($this->restrictionType) ?? RestrictionType::Allergy;
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function members(): Collection
    {
        return app(HouseholdService::class)->members();
    }

    #[Computed]
    public function editingPerson(): ?HouseholdPerson
    {
        return $this->restrictionPersonId ? HouseholdPerson::query()->find($this->restrictionPersonId) : null;
    }

    /** Les personnes du foyer, goûts chargés (lot 39). @return Collection<int, HouseholdPerson> */
    #[Computed]
    public function people(): Collection
    {
        return HouseholdPerson::query()->ordered()->with('user:id,name,share_restrictions', 'restrictions.ingredient', 'restrictions.tag')->get();
    }

    /** Comptes du foyer sans personne. @return Collection<int, User> */
    #[Computed]
    public function accountsWithoutPerson(): Collection
    {
        return app(\App\Services\People\HouseholdPeople::class)->accountsWithoutPerson();
    }

    #[Computed]
    public function household(): ?\App\Models\Household
    {
        return CurrentHousehold::get();
    }

    /** Invitations en attente. @return Collection<int, Invitation> */
    #[Computed]
    public function invitations(): Collection
    {
        return Invitation::query()->with('inviter')->where('household_id', CurrentHousehold::id())
            ->whereNull('accepted_at')->where('expires_at', '>', now())->latest()->get();
    }

    #[Computed]
    public function ingredients(): Collection
    {
        return Ingredient::query()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function tags(): Collection
    {
        return Tag::query()->ordered()->get(['id', 'name']);
    }

    public function render()
    {
        return view('livewire.settings.household');
    }
}
