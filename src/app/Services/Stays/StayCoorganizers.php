<?php

namespace App\Services\Stays;

use App\Models\Household;
use App\Models\Scopes\HouseholdScope;
use App\Models\ShoppingList;
use App\Models\Stay;
use App\Models\StayHousehold;
use App\Models\StayParticipant;
use App\Models\User;
use App\Services\Linked\HouseholdLinks;
use App\Support\CurrentHousehold;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Séjour co-organisé (lot 42, 42.1, règle R45).
 *
 *  - le séjour reste rangé chez le foyer qui l'organise ; il invite un foyer **relié** ;
 *  - un foyer qui accepte devient co-organisateur : il prévoit des repas (de son carnet), note ses
 *    dépenses, gère **ses** participants et ce qu'il emporte de **son** stock ;
 *  - chacun ne voit des autres que ce qu'il voyait déjà : les goûts partagés des foyers reliés,
 *    jamais la fiche d'un invité de l'autre foyer, ni ses recettes au-delà de leur titre ;
 *  - l'organisateur peut retirer un foyer : ses dépenses restent dans les comptes, marquées.
 */
class StayCoorganizers
{
    public const ORGANIZER = 'organizer';

    public const COORGANIZER = 'coorganizer';

    /** @var array<int, list<int>> foyers qui co-organisent, par séjour (le temps d'une requête) */
    private array $accepted = [];

    public function __construct(private readonly HouseholdLinks $links) {}

    /* ================================================================ Côté organisateur */

    public function invite(Stay $stay, int $householdId, ?User $user = null): StayHousehold
    {
        $this->guardOrganizer($stay);

        if ($householdId === (int) $stay->household_id || ! $this->links->areLinked((int) $stay->household_id, $householdId)) {
            throw new InvalidArgumentException('Seul un foyer relié peut co-organiser le séjour.');
        }

        $row = StayHousehold::query()->firstOrNew(['stay_id' => $stay->id, 'household_id' => $householdId]);

        if ($row->exists && $row->isAccepted()) {
            return $row;
        }

        // Une nouvelle invitation après un refus ou un retrait repart de zéro.
        $row->fill(['status' => 'invited', 'invited_by' => $user?->id, 'responded_at' => null])->save();
        $this->forget($stay);

        return $row;
    }

    /**
     * Retire un foyer : une invitation en attente disparaît ; un foyer qui co-organisait est marqué
     * « retiré ». Ses participants, ses plats et ses dépenses restent (R45) ; ses participants sont
     * désormais gérés par l'organisateur.
     */
    public function remove(Stay $stay, int $householdId): void
    {
        $this->guardOrganizer($stay);
        $row = StayHousehold::query()->where('stay_id', $stay->id)->where('household_id', $householdId)->first();

        if (! $row) {
            return;
        }

        in_array($row->status, ['invited', 'declined'], true) && ! $this->hasTraces($stay, $householdId)
            ? $row->delete()
            : $row->update(['status' => 'removed']);

        $this->forget($stay);
    }

    /** @return Collection<int, StayHousehold> foyers invités, avec leur nom */
    public function rows(Stay $stay): Collection
    {
        return $stay->households()->with('household')->get();
    }

    /* ================================================================ Côté foyer invité */

    /** Invitations en attente pour le foyer actif, séjours pas encore finis. @return Collection<int, StayHousehold> */
    public function invitationsFor(?int $householdId = null, ?Carbon $today = null): Collection
    {
        $householdId ??= CurrentHousehold::id();
        $today ??= Carbon::today();

        return StayHousehold::query()->where('household_id', $householdId)->where('status', 'invited')
            ->whereHas('stay', fn ($q) => $q->whereDate('ends_on', '>=', $today->toDateString()))
            ->with(['stay.household'])
            ->get()
            ->sortBy(fn (StayHousehold $row) => $row->stay->starts_on->toDateString())
            ->values();
    }

    public function respond(int $rowId, bool $accept): StayHousehold
    {
        $row = StayHousehold::query()->whereKey($rowId)->where('household_id', CurrentHousehold::id())->where('status', 'invited')->first()
            ?? throw new InvalidArgumentException('Cette invitation n\'est plus valable.');

        $row->update(['status' => $accept ? 'accepted' : 'declined', 'responded_at' => now()]);

        if ($accept && ($list = $row->stay->shoppingList)) {
            // La liste du séjour s'ouvre aux foyers qui viennent (liste groupée, 26.8).
            $list->forceFill(['shared_with_links' => true])->save();
        }

        $this->forget($row->stay);

        return $row;
    }

    /** Le foyer actif ne co-organise plus ce séjour. */
    public function leave(Stay $stay): void
    {
        StayHousehold::query()->where('stay_id', $stay->id)->where('household_id', CurrentHousehold::id())->where('status', 'accepted')
            ->update(['status' => 'left', 'updated_at' => now()]);
        $this->forget($stay);
    }

    /** Séjours que le foyer actif co-organise. @return Collection<int, Stay> */
    public function coorganized(?int $householdId = null): Collection
    {
        $householdId ??= CurrentHousehold::id();

        return Stay::query()->withoutGlobalScope(HouseholdScope::class)
            ->whereIn('id', StayHousehold::query()->select('stay_id')->where('household_id', $householdId)->where('status', 'accepted'))
            ->with('household')
            ->get();
    }

    /* ================================================================ Rôles */

    /** « organizer », « coorganizer » ou rien. */
    public function role(Stay $stay, ?int $householdId = null): ?string
    {
        $householdId ??= (int) CurrentHousehold::id();

        return match (true) {
            $householdId === (int) $stay->household_id => self::ORGANIZER,
            in_array($householdId, $this->acceptedIds($stay), true) => self::COORGANIZER,
            default => null,
        };
    }

    public function isOrganizer(Stay $stay, ?int $householdId = null): bool
    {
        return $this->role($stay, $householdId) === self::ORGANIZER;
    }

    /** @return list<int> */
    public function acceptedIds(Stay $stay): array
    {
        return $this->accepted[$stay->id] ??= StayHousehold::query()->where('stay_id', $stay->id)->where('status', 'accepted')
            ->pluck('household_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Foyers qui ont quitté le séjour ou en ont été retirés. @return list<int> */
    public function goneIds(Stay $stay): array
    {
        return StayHousehold::query()->where('stay_id', $stay->id)->whereIn('status', ['removed', 'left'])
            ->pluck('household_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Le foyer qui gère un participant : celui qui co-organise s'il est le sien, sinon l'organisateur
     * (y compris pour la personne d'un foyer relié qui ne co-organise pas).
     */
    public function ownerOf(StayParticipant $participant, Stay $stay): int
    {
        $linked = (int) $participant->linked_household_id;

        return $linked && in_array($linked, $this->acceptedIds($stay), true) ? $linked : (int) $stay->household_id;
    }

    public function canManageParticipant(StayParticipant $participant, Stay $stay, ?int $householdId = null): bool
    {
        return $this->ownerOf($participant, $stay) === ($householdId ?? (int) CurrentHousehold::id());
    }

    /** Un plat, une dépense, un article emporté : à qui ? (vide = le foyer qui organise) */
    public function ownerId(?int $householdId, Stay $stay): int
    {
        return $householdId ?: (int) $stay->household_id;
    }

    /** L'organisateur gère tout ; un co-organisateur, ce qu'il a ajouté. */
    public function canManage(?int $ownerHouseholdId, Stay $stay, ?int $householdId = null): bool
    {
        $householdId ??= (int) CurrentHousehold::id();

        return $this->isOrganizer($stay, $householdId) || $this->ownerId($ownerHouseholdId, $stay) === $householdId;
    }

    /** Exécute $callback dans le foyer qui organise (planning, portions, liste de courses du séjour). */
    public function asOrganizer(Stay $stay, Closure $callback): mixed
    {
        return (int) CurrentHousehold::id() === (int) $stay->household_id
            ? $callback()
            : CurrentHousehold::run((int) $stay->household_id, $callback);
    }

    /** @return array<int, string> nom de chaque foyer, organisateur compris */
    public function names(Stay $stay): array
    {
        $ids = [(int) $stay->household_id, ...StayHousehold::query()->where('stay_id', $stay->id)->pluck('household_id')->map(fn ($id) => (int) $id)->all()];

        return Household::query()->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($name) => (string) $name)->all();
    }

    /**
     * La liste de courses d'un séjour que le foyer actif co-organise (mode magasin, 42.1) ; sinon
     * seulement une liste du foyer actif.
     */
    public function findList(int $listId): ?ShoppingList
    {
        $own = ShoppingList::query()->find($listId);

        if ($own) {
            return $own;
        }

        $list = ShoppingList::query()->withoutGlobalScope(HouseholdScope::class)->whereKey($listId)->whereNotNull('stay_id')->first();
        $stay = $list ? Stay::query()->withoutGlobalScope(HouseholdScope::class)->find($list->stay_id) : null;

        return $stay && $this->role($stay) === self::COORGANIZER ? $list : null;
    }

    private function hasTraces(Stay $stay, int $householdId): bool
    {
        return $stay->meals()->where('household_id', $householdId)->exists()
            || $stay->payments()->where('household_id', $householdId)->exists()
            || $stay->packedItems()->where('household_id', $householdId)->exists();
    }

    private function guardOrganizer(Stay $stay): void
    {
        if (! $this->isOrganizer($stay)) {
            throw new InvalidArgumentException('Seul le foyer qui organise le séjour peut le faire.');
        }
    }

    public function forget(Stay $stay): void
    {
        unset($this->accepted[$stay->id]);
    }
}
