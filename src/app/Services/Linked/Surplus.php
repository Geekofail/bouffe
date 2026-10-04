<?php

namespace App\Services\Linked;

use App\Enums\MovementType;
use App\Models\Scopes\HouseholdScope;
use App\Models\StockItem;
use App\Models\SurplusOffer;
use App\Models\User;
use App\Services\Stock\StockManager;
use App\Support\CurrentHousehold;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Surplus à donner (26.7) : une annonce visible des foyers reliés, réservée en un geste ; le stock
 * du donneur n'est retiré qu'à la remise (consommé, avec la mention « donné à … »).
 */
class Surplus
{
    public function __construct(private readonly HouseholdLinks $links, private readonly StockManager $stock) {}

    /** @param  array{label?: string|null, stock_item_id?: int|null, quantity?: string|null, available_until: string, note?: string|null}  $data */
    public function offer(array $data, ?User $user = null): SurplusOffer
    {
        $item = ! empty($data['stock_item_id']) ? StockItem::query()->active()->find($data['stock_item_id']) : null;
        $label = trim((string) ($data['label'] ?? '')) ?: ($item?->name() ?? '');

        if ($label === '') {
            throw new InvalidArgumentException('Dites ce que vous donnez.');
        }

        $until = Carbon::parse($data['available_until'])->startOfDay();

        if ($until->lt(Carbon::today())) {
            throw new InvalidArgumentException('La date est déjà passée.');
        }

        return SurplusOffer::create([
            'stock_item_id' => $item?->id,
            'label' => mb_substr($label, 0, 150),
            'quantity' => mb_substr(trim((string) ($data['quantity'] ?? '')), 0, 60) ?: null,
            'available_until' => $until->toDateString(),
            'note' => mb_substr(trim((string) ($data['note'] ?? '')), 0, 255) ?: null,
            'created_by' => $user?->id,
        ]);
    }

    /** Nos annonces (en cours et récentes). @return Collection<int, SurplusOffer> */
    public function mine(): Collection
    {
        return SurplusOffer::query()
            ->where(fn ($q) => $q->whereNull('handed_at')->whereNull('cancelled_at')->orWhere('updated_at', '>=', now()->subDays(14)))
            ->with('reservedBy', 'reservedByUser', 'stockItem')
            ->orderBy('available_until')->get();
    }

    /** Annonces des foyers reliés, encore disponibles ou réservées par nous. @return Collection<int, SurplusOffer> */
    public function fromLinked(?int $householdId = null): Collection
    {
        $householdId ??= CurrentHousehold::id();
        $linked = $this->links->linkedIds($householdId);

        if ($linked === []) {
            return collect();
        }

        return SurplusOffer::query()->withoutGlobalScope(HouseholdScope::class)
            ->whereIn('household_id', $linked)
            ->open()
            ->where(fn ($q) => $q->whereNull('reserved_by_household_id')->orWhere('reserved_by_household_id', $householdId))
            ->with('household', 'author')
            ->orderBy('available_until')->get();
    }

    public function reserve(int $offerId, ?User $user = null): SurplusOffer
    {
        $householdId = (int) CurrentHousehold::id();
        $offer = $this->fromLinked($householdId)->firstWhere('id', $offerId) ?? throw new InvalidArgumentException('Cette annonce n\'est plus disponible.');

        if ($offer->reserved_by_household_id && (int) $offer->reserved_by_household_id !== $householdId) {
            throw new InvalidArgumentException('Déjà réservé par un autre foyer.');
        }

        $offer->forceFill(['reserved_by_household_id' => $householdId, 'reserved_by_user_id' => $user?->id, 'reserved_at' => now()])->save();

        return $offer;
    }

    public function release(int $offerId): void
    {
        SurplusOffer::query()->withoutGlobalScope(HouseholdScope::class)->whereKey($offerId)
            ->where('reserved_by_household_id', CurrentHousehold::id())->whereNull('handed_at')
            ->update(['reserved_by_household_id' => null, 'reserved_by_user_id' => null, 'reserved_at' => null]);
    }

    /** Remis : l'article du stock (s'il y en a un) est retiré, consommé « donné à … ». */
    public function handOver(SurplusOffer $offer): void
    {
        if ($offer->handed_at || $offer->cancelled_at) {
            return;
        }

        $item = $offer->stock_item_id ? StockItem::query()->active()->find($offer->stock_item_id) : null;

        if ($item) {
            $this->stock->finish($item, MovementType::Consume, 'Donné'.($offer->reservedBy ? ' à '.$offer->reservedBy->name : ''));
        }

        $offer->forceFill(['handed_at' => now()])->save();
    }

    public function cancel(SurplusOffer $offer): void
    {
        $offer->forceFill(['cancelled_at' => now()])->save();
    }
}
