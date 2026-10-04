<?php

namespace App\Services\Linked;

use App\Models\Household;
use App\Models\HouseholdLink;
use App\Models\HouseholdShare;
use App\Models\User;
use App\Support\CurrentHousehold;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Foyers reliés (lot 26, module 26) : l'un invite, l'autre accepte ; chacun règle ce qu'il ouvre.
 *
 * Par défaut, relier deux foyers n'ouvre rien : les recettes restent privées (Q36) et le planning
 * fermé. Seules les recettes marquées « foyers reliés » ou « toute l'installation » se voient.
 */
class HouseholdLinks
{
    /** Liens acceptés, mémorisés le temps de la requête. @var array<int, list<int>> */
    private array $linkedCache = [];

    /**
     * Crée une invitation : le lien s'affiche une fois, seul son condensé est gardé.
     *
     * @return array{link: HouseholdLink, token: string}
     */
    public function invite(Household $household, ?User $inviter = null): array
    {
        $token = Str::random(40);

        $link = HouseholdLink::create([
            'household_id' => $household->id,
            'token_hash' => HouseholdLink::hash($token),
            'invited_by' => $inviter?->id,
            'expires_at' => now()->addDays(HouseholdLink::VALID_DAYS),
        ]);

        return ['link' => $link, 'token' => $token];
    }

    public function findInvitation(string $token): ?HouseholdLink
    {
        return HouseholdLink::query()->where('token_hash', HouseholdLink::hash($token))->first();
    }

    /** Le foyer actif de la personne accepte l'invitation. */
    public function accept(HouseholdLink $link, Household $household, ?User $user = null): HouseholdLink
    {
        if (! $link->isUsable()) {
            throw new InvalidArgumentException('Ce lien n\'est plus valable : demandez-en un nouveau.');
        }

        if ($link->household_id === $household->id) {
            throw new InvalidArgumentException('C\'est le lien de votre propre foyer : envoyez-le à l\'autre foyer.');
        }

        if ($this->areLinked($link->household_id, $household->id)) {
            throw new InvalidArgumentException('Vos deux foyers sont déjà reliés.');
        }

        return DB::transaction(function () use ($link, $household, $user) {
            $link->forceFill([
                'linked_household_id' => $household->id,
                'token_hash' => null,
                'accepted_at' => now(),
                'accepted_by' => $user?->id,
            ])->save();

            foreach ([[$link->household_id, $household->id], [$household->id, $link->household_id]] as [$from, $to]) {
                HouseholdShare::query()->firstOrCreate(['household_id' => $from, 'target_household_id' => $to]);
            }

            $this->linkedCache = [];

            return $link;
        });
    }

    /**
     * Défait le lien : plus rien n'est partagé dans un sens ni dans l'autre. Les copies de recettes
     * restent (R31) ; les repas planifiés avec une recette de l'autre foyer aussi (elle reste lisible
     * par ces repas, pas depuis le carnet).
     */
    public function unlink(int $householdId, int $otherId): void
    {
        DB::transaction(function () use ($householdId, $otherId) {
            HouseholdLink::query()->activeFor($householdId)
                ->where(fn ($q) => $q->where('household_id', $otherId)->orWhere('linked_household_id', $otherId))
                ->delete();
            HouseholdShare::query()->whereIn('household_id', [$householdId, $otherId])->whereIn('target_household_id', [$householdId, $otherId])->delete();
        });

        $this->linkedCache = [];
    }

    public function revokeInvitation(HouseholdLink $link): void
    {
        if ($link->isPending()) {
            $link->delete();
        }
    }

    /** @return list<int> foyers reliés (liens acceptés, foyers actifs) */
    public function linkedIds(?int $householdId = null): array
    {
        $householdId ??= CurrentHousehold::id();

        if (! $householdId) {
            return [];
        }

        return $this->linkedCache[$householdId] ??= HouseholdLink::query()->activeFor($householdId)->get()
            ->map(fn (HouseholdLink $l) => $l->otherId($householdId))
            ->filter()
            ->unique()
            ->pipe(fn (Collection $ids) => Household::query()->whereIn('id', $ids)->whereNull('disabled_at')->whereNull('deletion_requested_at')->pluck('id'))
            ->map(fn ($id) => (int) $id)->values()->all();
    }

    /** @return Collection<int, Household> */
    public function linked(?int $householdId = null): Collection
    {
        return Household::query()->whereIn('id', $this->linkedIds($householdId))->orderBy('name')->get();
    }

    public function areLinked(int $a, int $b): bool
    {
        return in_array($b, $this->linkedIds($a), true);
    }

    /** Ce que $from ouvre à $to (réglages par défaut s'il n'y a pas de ligne). */
    public function share(int $from, int $to): HouseholdShare
    {
        return HouseholdShare::query()->where('household_id', $from)->where('target_household_id', $to)->first()
            ?? new HouseholdShare(['household_id' => $from, 'target_household_id' => $to]);
    }

    public function updateShare(int $from, int $to, bool $recipesAll, string $planning): HouseholdShare
    {
        if (! $this->areLinked($from, $to)) {
            throw new InvalidArgumentException('Ces foyers ne sont pas reliés.');
        }

        return HouseholdShare::query()->updateOrCreate(
            ['household_id' => $from, 'target_household_id' => $to],
            ['recipes_all' => $recipesAll, 'planning' => array_key_exists($planning, HouseholdShare::PLANNING) ? $planning : 'none'],
        );
    }

    /** Planning de $owner ouvert à $viewer : none · read · write. */
    public function planningAccess(int $owner, int $viewer): string
    {
        return $this->areLinked($owner, $viewer) ? $this->share($owner, $viewer)->planning : 'none';
    }

    /** Invitations en attente créées par le foyer. @return Collection<int, HouseholdLink> */
    public function pendingInvitations(int $householdId): Collection
    {
        return HouseholdLink::query()->where('household_id', $householdId)->whereNull('accepted_at')
            ->where('expires_at', '>', now())->latest()->get();
    }
}
