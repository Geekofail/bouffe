<?php

namespace App\Livewire\Linked;

use App\Models\Household;
use App\Models\HouseholdShare;
use App\Services\Linked\GroupLists;
use App\Services\Linked\HouseholdLinks;
use App\Services\Linked\SharedMeals;
use App\Services\Linked\SharedRecipes;
use App\Support\CurrentHousehold;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Proches (lot 26) : foyers reliés, ce que l'on ouvre à chacun, ce qu'ils nous ouvrent,
 * invitations (lien à usage unique), repas communs reçus.
 */
#[Title('Proches')]
class Index extends Component
{
    public ?string $linkUrl = null;

    /** Réglages de partage affichés : foyer => [recipes_all, planning]. @var array<int, array{recipes_all: bool, planning: string}> */
    public array $shares = [];

    public function mount(HouseholdLinks $links): void
    {
        foreach ($links->linkedIds() as $id) {
            $share = $links->share((int) CurrentHousehold::id(), $id);
            $this->shares[$id] = ['recipes_all' => (bool) $share->recipes_all, 'planning' => (string) $share->planning];
        }
    }

    public function createLink(HouseholdLinks $links): void
    {
        $this->guardOwner();
        $result = $links->invite(CurrentHousehold::get(), Auth::user());
        $this->linkUrl = route('linked.accept', $result['token']);
        unset($this->invitations);
    }

    public function revokeLink(int $id, HouseholdLinks $links): void
    {
        $this->guardOwner();
        $link = $links->pendingInvitations((int) CurrentHousehold::id())->firstWhere('id', $id);

        if ($link) {
            $links->revokeInvitation($link);
        }

        $this->linkUrl = null;
        unset($this->invitations);
    }

    public function saveShare(int $householdId, HouseholdLinks $links): void
    {
        $this->guardOwner();
        $row = $this->shares[$householdId] ?? null;

        if (! $row || ! $links->areLinked((int) CurrentHousehold::id(), $householdId)) {
            return;
        }

        $links->updateShare((int) CurrentHousehold::id(), $householdId, (bool) $row['recipes_all'], (string) $row['planning']);
        $this->dispatch('notify', message: 'Partage enregistré.');
    }

    public function unlink(int $householdId, HouseholdLinks $links): void
    {
        $this->guardOwner();
        $links->unlink((int) CurrentHousehold::id(), $householdId);
        unset($this->shares[$householdId], $this->linked);
        $this->dispatch('notify', message: 'Les deux foyers ne sont plus reliés.');
    }

    #[Computed]
    public function linked()
    {
        $links = app(HouseholdLinks::class);
        $me = (int) CurrentHousehold::id();
        $recipes = app(SharedRecipes::class);
        $lists = app(GroupLists::class)->openLists($me)->groupBy('household_id');

        return $links->linked($me)->map(fn (Household $h) => [
            'household' => $h,
            'members' => $h->members()->pluck('name')->all(),
            'theirShare' => $links->share($h->id, $me),
            'recipes' => $recipes->visibleQuery($me)->where('recipes.household_id', $h->id)->count(),
            'lists' => $lists->get($h->id, collect()),
        ]);
    }

    #[Computed]
    public function invitations()
    {
        return app(HouseholdLinks::class)->pendingInvitations((int) CurrentHousehold::id());
    }

    #[Computed]
    public function mealInvitations()
    {
        return app(SharedMeals::class)->invitationsFor();
    }

    public function isOwner(): bool
    {
        return (bool) Auth::user()?->managesHousehold();
    }

    private function guardOwner(): void
    {
        abort_unless($this->isOwner(), 403, 'Réservé aux responsables du foyer.');
    }

    public function render()
    {
        return view('livewire.linked.index', [
            'owner' => $this->isOwner(),
            'household' => CurrentHousehold::get(),
            'planningOptions' => HouseholdShare::PLANNING,
        ]);
    }
}
