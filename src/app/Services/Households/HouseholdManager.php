<?php

namespace App\Services\Households;

use App\Enums\UserRole;
use App\Models\BudgetCategory;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\User;
use App\Support\CurrentHousehold;
use App\Support\StockDefaults;
use Database\Seeders\MealSlotSeeder;
use Database\Seeders\TagSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Foyers et membres (lot 24 : 25.1 à 25.4, 25.7, 25.8).
 */
class HouseholdManager
{
    /* ================================================================ Foyers */

    /** Nouveau foyer, prêt à servir : créneaux, emplacements, catégories et postes de budget de départ. */
    public function create(string $name, ?User $creator = null): Household
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('Donnez un nom au foyer (100 caractères au plus).');
        }

        return DB::transaction(function () use ($name, $creator) {
            $household = Household::create(['name' => $name, 'created_by' => $creator?->id]);
            $this->provision($household);

            return $household;
        });
    }

    public function provision(Household $household): void
    {
        CurrentHousehold::run($household, function () use ($household) {
            (new MealSlotSeeder)->run();
            (new TagSeeder)->run();
            StockDefaults::seedLocations($household->id);

            foreach (BudgetCategory::DEFAULTS as $i => [$name, $kind, $color]) {
                BudgetCategory::firstOrCreate(['kind' => $kind, 'name' => $name], ['color' => $color, 'sort_order' => $i + 1]);
            }
        });
    }

    public function rename(Household $household, string $name): void
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('Donnez un nom au foyer (100 caractères au plus).');
        }

        $household->update(['name' => $name]);
    }

    /* ================================================================ Membres (25.3, 25.4) */

    public function attach(Household $household, User $user, UserRole $role): void
    {
        $household->members()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);
        $household->members()->updateExistingPivot($user->id, ['role' => $role->value]);

        if (! $user->current_household_id || ! $user->households()->whereKey($user->current_household_id)->exists()) {
            $user->forceFill(['current_household_id' => $household->id])->save();
        }

        $user->forgetRoles();
        CurrentHousehold::forget();
    }

    public function changeRole(Household $household, User $user, UserRole $role): void
    {
        $current = $user->roleIn($household->id);

        if (! $current) {
            throw new InvalidArgumentException('Ce compte n\'est pas membre du foyer.');
        }

        if ($current === UserRole::Owner && $role !== UserRole::Owner && $this->ownerCount($household) <= 1) {
            throw new InvalidArgumentException('Un foyer garde toujours au moins un responsable.');
        }

        $user->setRoleIn($role, $household->id);
    }

    /** Départ d'un membre (25.8) : le compte reste, il n'a plus accès au foyer. */
    public function detach(Household $household, User $user): void
    {
        if ($user->roleIn($household->id) === UserRole::Owner && $this->ownerCount($household) <= 1) {
            throw new InvalidArgumentException('Dernier responsable du foyer : nommez-en un autre, ou supprimez le foyer.');
        }

        $household->members()->detach($user->id);

        // Lot 39 : sa personne reste dans le foyer (ses goûts, ses gamelles), sans compte rattaché.
        \Illuminate\Support\Facades\DB::table('household_people')->where('household_id', $household->id)->where('user_id', $user->id)->update(['user_id' => null]);

        if ((int) $user->current_household_id === $household->id) {
            $user->forceFill(['current_household_id' => $user->households()->value('households.id')])->save();
        }

        $user->forgetRoles();
        CurrentHousehold::forget();
    }

    /** Passer d'un foyer à l'autre (25.4). */
    public function switchTo(User $user, Household $household): void
    {
        if (! $user->roleIn($household->id)) {
            throw new InvalidArgumentException('Vous n\'êtes pas membre de ce foyer.');
        }

        if (! $household->isActive()) {
            throw new InvalidArgumentException('Ce foyer est désactivé.');
        }

        $user->forceFill(['current_household_id' => $household->id])->save();
        CurrentHousehold::forget();
    }

    public function ownerCount(Household $household): int
    {
        return DB::table('household_user')->where('household_id', $household->id)->where('role', UserRole::Owner->value)->count();
    }

    /* ================================================================ Invitations (25.2) */

    /**
     * Crée une invitation ; le lien n'est connu qu'ici (seul son condensé est gardé).
     *
     * @return array{invitation: Invitation, url: string}
     */
    public function invite(Household $household, UserRole $role, ?User $inviter = null, ?string $email = null): array
    {
        $email = $email !== null && trim($email) !== '' ? mb_strtolower(trim($email)) : null;

        if ($email !== null && User::query()->where('email', $email)->whereHas('households', fn ($q) => $q->whereKey($household->id))->exists()) {
            throw new InvalidArgumentException('Cette personne fait déjà partie du foyer.');
        }

        $token = Str::random(48);
        $invitation = Invitation::create([
            'household_id' => $household->id,
            'email' => $email,
            'token_hash' => Invitation::hash($token),
            'role' => $role->value,
            'invited_by' => $inviter?->id,
            'expires_at' => Carbon::now()->addDays(Invitation::VALID_DAYS),
        ]);

        return ['invitation' => $invitation, 'url' => route('invitation.show', $token)];
    }

    public function findInvitation(string $token): ?Invitation
    {
        return Invitation::query()->with('household')->where('token_hash', Invitation::hash($token))->first();
    }

    /** Accepte l'invitation pour ce compte (déjà connecté, ou tout juste créé). */
    public function accept(Invitation $invitation, User $user): Household
    {
        if (! $invitation->isUsable()) {
            throw new InvalidArgumentException('Cette invitation n\'est plus valable : demandez-en une nouvelle.');
        }

        if ($invitation->email && mb_strtolower($user->email) !== $invitation->email) {
            throw new InvalidArgumentException('Cette invitation a été envoyée à une autre adresse e-mail.');
        }

        return DB::transaction(function () use ($invitation, $user) {
            $household = $invitation->household;
            $this->attach($household, $user, $user->roleIn($household->id) ?? $invitation->role);
            $user->forceFill(['current_household_id' => $household->id])->save();
            $invitation->update(['accepted_at' => now(), 'accepted_by' => $user->id]);
            CurrentHousehold::forget();

            return $household;
        });
    }

    public function revoke(Invitation $invitation): void
    {
        $invitation->delete();
    }

    /* ================================================================ Administration et suppression (25.7, 25.8) */

    public function setDisabled(Household $household, bool $disabled): void
    {
        $household->update(['disabled_at' => $disabled ? now() : null]);
    }

    public function requestDeletion(Household $household): void
    {
        $household->update(['deletion_requested_at' => now()]);
        $household->invitations()->whereNull('accepted_at')->delete();
    }

    public function cancelDeletion(Household $household): void
    {
        $household->update(['deletion_requested_at' => null]);
    }

    /** Foyers dont le délai de 30 jours est passé : toutes leurs données et photos disparaissent. */
    public function purgeDue(?Carbon $now = null): int
    {
        $limit = ($now ?? Carbon::now())->copy()->subDays(Household::DELETION_DELAY_DAYS);
        $count = 0;

        foreach (Household::query()->whereNotNull('deletion_requested_at')->where('deletion_requested_at', '<=', $limit)->get() as $household) {
            app(HouseholdData::class)->destroy($household);
            $count++;
        }

        return $count;
    }
}
