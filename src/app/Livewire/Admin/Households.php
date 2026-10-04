<?php

namespace App\Livewire\Admin;

use App\Enums\UserRole;
use App\Models\Household;
use App\Services\Households\HouseholdData;
use App\Services\Households\HouseholdManager;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Administration de l'installation (lot 24, 25.7) : les foyers, leurs membres, la place occupée,
 * le dernier passage. Aucun accès au contenu des foyers depuis cet écran.
 */
#[Title('Administration')]
class Households extends Component
{
    public string $newName = '';

    public string $ownerEmail = '';

    /** Lien d'invitation du responsable du foyer créé : montré une seule fois. */
    public ?string $inviteUrl = null;

    public ?string $inviteFor = null;

    public function create(HouseholdManager $manager): void
    {
        $this->validate([
            'newName' => 'required|string|max:100',
            'ownerEmail' => 'nullable|email|max:191',
        ], [], ['newName' => 'nom du foyer', 'ownerEmail' => 'adresse du responsable']);

        try {
            $household = $manager->create($this->newName, auth()->user());
            $result = $manager->invite($household, UserRole::Owner, auth()->user(), $this->ownerEmail ?: null);
        } catch (InvalidArgumentException $e) {
            $this->addError('newName', $e->getMessage());

            return;
        }

        $this->inviteUrl = $result['url'];
        $this->inviteFor = $household->name;
        $this->reset('newName', 'ownerEmail');
        $this->dispatch('notify', message: "Foyer « {$household->name} » créé.");
    }

    /** Nouveau lien de responsable pour un foyer (le précédent a expiré, ou personne ne l'a utilisé). */
    public function ownerInvite(int $id, HouseholdManager $manager): void
    {
        $household = Household::findOrFail($id);
        $result = $manager->invite($household, UserRole::Owner, auth()->user());
        $this->inviteUrl = $result['url'];
        $this->inviteFor = $household->name;
    }

    public function toggle(int $id, HouseholdManager $manager): void
    {
        $household = Household::findOrFail($id);

        if ($household->isActive() && $household->members()->whereKey(auth()->id())->exists() && Household::query()->whereNull('disabled_at')->count() <= 1) {
            $this->dispatch('notify', type: 'warning', message: 'Impossible de désactiver le dernier foyer actif.');

            return;
        }

        $manager->setDisabled($household, $household->isActive());
        $this->dispatch('notify', message: $household->fresh()->isActive() ? "« {$household->name} » réactivé." : "« {$household->name} » désactivé : ses membres n'y ont plus accès.");
    }

    public function cancelDeletion(int $id, HouseholdManager $manager): void
    {
        $manager->cancelDeletion(Household::findOrFail($id));
    }

    public function render(HouseholdData $data)
    {
        $households = Household::query()->withCount('members')->orderBy('name')->get()
            ->map(fn (Household $h) => [
                'household' => $h,
                'owners' => $h->members()->wherePivot('role', UserRole::Owner->value)->pluck('name')->all(),
                'last_active' => DB::table('household_user')->where('household_id', $h->id)->max('last_active_at'),
                'bytes' => $data->storageBytes($h),
                'recipes' => DB::table('recipes')->where('household_id', $h->id)->count(),
                'pending' => $h->invitations()->whereNull('accepted_at')->where('expires_at', '>', now())->count(),
            ]);

        return view('livewire.admin.households', [
            'households' => $households,
            'accounts' => DB::table('users')->count(),
            'without' => DB::table('users')->whereNotIn('id', DB::table('household_user')->select('user_id'))->count(),
        ]);
    }
}
