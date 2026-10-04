<?php

namespace App\Services\Security;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Households\HouseholdManager;
use App\Support\CurrentHousehold;
use Illuminate\Support\Facades\DB;

/**
 * Suppression de son propre compte (lot 25, 27.12).
 *
 * Ce que la personne a saisi dans un foyer (recettes, dépenses…) appartient au foyer et reste ;
 * son nom disparaît simplement des « ajouté par ». Ses propres données partent avec le compte :
 * contraintes alimentaires, réactions, abonnements aux notifications, journal des connexions.
 */
class AccountDeletion
{
    public function __construct(private readonly HouseholdManager $households) {}

    /** @return list<string> ce qui empêche la suppression (vide : possible) */
    public function blockers(User $user): array
    {
        $blockers = [];

        if ($user->isAdmin() && User::query()->where('is_admin', true)->count() <= 1) {
            $blockers[] = 'Vous administrez l\'installation : ce compte ne peut pas être supprimé depuis l\'application.';
        }

        foreach ($user->households()->get() as $household) {
            $members = DB::table('household_user')->where('household_id', $household->id)->count();

            if ($members <= 1 && ! $household->deletion_requested_at) {
                $blockers[] = "Vous êtes seul dans « {$household->name} » : supprimez d'abord ce foyer (Paramètres → Foyer).";
            } elseif ($members > 1 && $user->roleIn($household->id) === UserRole::Owner && $this->households->ownerCount($household) <= 1) {
                $blockers[] = "Vous êtes le seul responsable de « {$household->name} » : nommez-en un autre avant de partir.";
            }
        }

        return $blockers;
    }

    public function delete(User $user): void
    {
        if ($blockers = $this->blockers($user)) {
            throw new \InvalidArgumentException($blockers[0]);
        }

        DB::transaction(function () use ($user) {
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }

            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();   // clés étrangères : cascade (données personnelles) ou mise à NULL (« ajouté par »)
        });

        CurrentHousehold::forget();
    }
}
