<?php

namespace App\Services\Stays;

use App\Enums\MovementType;
use App\Models\Stay;
use App\Models\StayPackedItem;
use App\Models\StockItem;
use App\Services\Stock\StockManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * « À emporter » (lot 34, 34.4) : on choisit dans le stock ce qui part au séjour. Au départ, c'est
 * retiré du stock ; au retour, on dit ce qui revient, et c'est remis. Entre les deux, la liste de
 * courses du séjour en tient compte.
 */
class StayPacking
{
    /** Raison des mouvements de stock : ni consommé à la maison, ni jeté. */
    public const REASON = 'sejour';

    public function __construct(private readonly StockManager $stock) {}

    /** Articles du stock que l'on peut emporter. @return Collection<int, StockItem> */
    public function candidates(Stay $stay, string $search = ''): Collection
    {
        $packed = $stay->packedItems()->whereNull('taken_at')->pluck('stock_item_id')->filter()->all();
        $term = \App\Support\NameNormalizer::normalize($search);

        return StockItem::query()->active()->where('is_present', true)
            ->whereNotIn('id', $packed)
            ->with('ingredient', 'unit', 'location')
            ->get()
            ->filter(fn (StockItem $item) => $term === '' || str_contains(\App\Support\NameNormalizer::normalize($item->name()), $term))
            ->sortBy(fn (StockItem $item) => \App\Support\NameNormalizer::normalize($item->name()))
            ->take(30)
            ->values();
    }

    /**
     * Lot 42 (42.1, R45) : chaque foyer emporte de **son** stock. Les articles du foyer actif
     * (ceux d'avant le lot 42, sans foyer noté, sont ceux de l'organisateur).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<StayPackedItem, Stay>
     */
    public function mine(Stay $stay)
    {
        $current = (int) \App\Support\CurrentHousehold::id();

        return $stay->packedItems()->where(fn ($q) => $q->where('household_id', $current)
            ->when($current === (int) $stay->household_id, fn ($q) => $q->orWhereNull('household_id')));
    }

    /** Départ et retour du foyer actif : ceux du séjour pour l'organisateur, les siens pour un foyer qui co-organise. */
    public function state(Stay $stay): object
    {
        $current = (int) \App\Support\CurrentHousehold::id();

        if ($current === (int) $stay->household_id) {
            return $stay;
        }

        return \App\Models\StayHousehold::query()->where('stay_id', $stay->id)->where('household_id', $current)->first()
            ?? (object) ['departed_at' => null, 'returned_at' => null];
    }

    private function markState(Stay $stay, string $column): void
    {
        $state = $this->state($stay);

        if ($state instanceof Stay || $state instanceof \App\Models\StayHousehold) {
            $state->update([$column => $state->{$column} ?? now()]);
        }
    }

    public function pack(Stay $stay, StockItem $item, float|string|null $quantity = null): StayPackedItem
    {
        if ($this->state($stay)->returned_at) {
            throw new InvalidArgumentException('Ce séjour est terminé.');
        }

        if ($item->finished_at || ! $item->is_present) {
            throw new InvalidArgumentException('Cet article n\'est plus dans le stock.');
        }

        $available = $item->quantity === null ? null : (float) $item->quantity;
        $quantity = $quantity === null || $quantity === '' ? $available : (float) str_replace(',', '.', (string) $quantity);

        if ($available !== null && ($quantity === null || $quantity <= 0 || $quantity > $available + 0.0005)) {
            throw new InvalidArgumentException('Quantité à emporter : entre 0 et '.rtrim(rtrim(number_format($available, 3, ',', ''), '0'), ',').'.');
        }

        $packed = $stay->packedItems()->create([
            'household_id' => \App\Support\CurrentHousehold::id(),
            'stock_item_id' => $item->id,
            'ingredient_id' => $item->ingredient_id,
            'label' => $item->name(),
            'quantity' => $available === null ? null : round((float) $quantity, 3),
            'unit_id' => $item->unit_id,
            'storage_location_id' => $item->storage_location_id,
        ]);

        // Déjà parti : l'article quitte le stock tout de suite.
        if ($this->state($stay)->departed_at) {
            $this->take($packed, $stay);
        }

        return $packed;
    }

    public function unpack(StayPackedItem $packed): void
    {
        if ($packed->taken_at) {
            throw new InvalidArgumentException('Déjà parti : indiquez-le plutôt au retour.');
        }

        $packed->delete();
    }

    /** Le départ : tout ce qui est prévu quitte le stock. @return int articles retirés */
    public function depart(Stay $stay): int
    {
        return DB::transaction(function () use ($stay) {
            $count = 0;

            foreach ($this->mine($stay)->whereNull('taken_at')->get() as $packed) {
                $this->take($packed, $stay);
                $count++;
            }

            $this->markState($stay, 'departed_at');

            return $count;
        });
    }

    private function take(StayPackedItem $packed, Stay $stay): void
    {
        $item = $packed->stockItem;

        if ($item && ! $item->finished_at) {
            $reason = self::REASON;
            $remaining = $item->quantity === null || $packed->quantity === null ? 0.0 : (float) $item->quantity - (float) $packed->quantity;

            $remaining > 0.0005
                ? $this->stock->setQuantity($item, round($remaining, 3))
                : $this->stock->finish($item, MovementType::Consume, $reason);
        }

        $packed->update(['taken_at' => now()]);
    }

    /**
     * Le retour : ce qui revient est remis dans le stock (dans l'article d'origine s'il y est encore,
     * sinon dans un nouvel article au même emplacement).
     *
     * @param  array<int, float|string|bool|null>  $returned  id => quantité revenue (ou vrai pour un article sans quantité)
     */
    public function returnHome(Stay $stay, array $returned): int
    {
        return DB::transaction(function () use ($stay, $returned) {
            $count = 0;

            foreach ($this->mine($stay)->whereNotNull('taken_at')->whereNull('returned_at')->with('stockItem')->get() as $packed) {
                $value = $returned[$packed->id] ?? null;
                $quantity = $packed->quantity === null
                    ? (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1.0 : 0.0)
                    : max(0.0, min((float) $packed->quantity, (float) str_replace(',', '.', (string) $value)));

                if ($quantity > 0) {
                    $this->putBack($packed, $quantity);
                    $count++;
                }

                $packed->update(['returned_quantity' => $packed->quantity === null ? null : round($quantity, 3), 'returned_at' => now()]);
            }

            $state = $this->state($stay);

            if ($state instanceof Stay || $state instanceof \App\Models\StayHousehold) {
                $state->update(['returned_at' => now()]);
            }

            return $count;
        });
    }

    private function putBack(StayPackedItem $packed, float $quantity): void
    {
        $item = $packed->stockItem;

        if ($item && ! $item->finished_at && $item->quantity !== null && $packed->quantity !== null) {
            $this->stock->setQuantity($item, round((float) $item->quantity + $quantity, 3));

            return;
        }

        $data = [
            'ingredient_id' => $packed->ingredient_id,
            'label' => $packed->ingredient_id ? null : $packed->label,
            'quantity' => $packed->quantity === null ? null : $quantity,
            'unit_id' => $packed->unit_id,
            'storage_location_id' => $packed->storage_location_id ?? $item?->storage_location_id,
            'note' => 'Revenu du séjour',
        ];

        // La date de péremption d'origine, sinon celle que Bouffe propose pour cet ingrédient.
        if ($item?->expires_on) {
            $data['expires_on'] = $item->expires_on->toDateString();
        }

        $this->stock->add($data);
    }

    /** Le départ se propose la veille du séjour et pendant. */
    public function canDepart(Stay $stay, ?Carbon $today = null): bool
    {
        $today ??= Carbon::today();

        return ! $this->state($stay)->departed_at && $today->gte($stay->starts_on->copy()->subDay()) && $today->lte($stay->ends_on);
    }
}
