<?php

namespace App\Services\Stock;

use App\Enums\ExpiryType;
use App\Enums\LocationType;
use App\Enums\MovementType;
use App\Enums\StockMode;
use App\Models\Ingredient;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Support\StockDefaults;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Toutes les opérations sur le stock (9.3 à 9.7). Chaque opération crée un mouvement ;
 * la dernière opération sur un article peut être annulée pendant quelques minutes.
 */
class StockManager
{
    /** Champs ajoutés à chaque mouvement créé (ex. repas à l'origine d'une déduction). */
    private array $context = [];

    /** Mouvements rattachés à un repas (déduction R9, restes). */
    public function forMeal(?int $plannedMealId): static
    {
        $clone = clone $this;
        $clone->context = $plannedMealId ? ['planned_meal_id' => $plannedMealId] : [];

        return $clone;
    }

    private const SNAPSHOT_FIELDS = [
        'quantity', 'unit_id', 'is_present', 'storage_location_id', 'expires_on', 'expiry_type',
        'opened_on', 'frozen_on', 'thawed_on', 'note', 'finished_at',
    ];

    /* ================================================================ Valeurs proposées */

    /**
     * Emplacement, date et unité proposés pour un ingrédient ou un plat préparé.
     *
     * @return array{storage_location_id: int|null, expires_on: string|null, expiry_type: string, unit_id: int|null}
     */
    public function defaults(?Ingredient $ingredient, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();

        if (! $ingredient) {
            return [
                'storage_location_id' => StorageLocation::firstOfType(LocationType::Fresh)?->id ?? StorageLocation::query()->ordered()->value('id'),
                'expires_on' => $today->copy()->addDays(StockDefaults::PREPARED_FRIDGE_DAYS)->toDateString(),
                'expiry_type' => ExpiryType::Dlc->value,
                'unit_id' => null,
            ];
        }

        // Mode présence (sel, pâtes…) : pas de date estimée, qui déclencherait des alertes sans objet.
        $estimate = $ingredient->stock_mode !== StockMode::Presence && $ingredient->shelf_life_days !== null;

        return [
            'storage_location_id' => $ingredient->storage_location_id ?? StorageLocation::query()->ordered()->value('id'),
            'expires_on' => $estimate ? $today->copy()->addDays($ingredient->shelf_life_days)->toDateString() : null,
            'expiry_type' => ($ingredient->shelf_life_type ?? ExpiryType::None)->value,
            'unit_id' => $ingredient->default_unit_id,
        ];
    }

    /* ================================================================ Entrées */

    /**
     * @param  array{ingredient_id?: int|null, label?: string|null, quantity?: float|null, unit_id?: int|null, storage_location_id?: int|null, expires_on?: string|null, expiry_type?: string|null, note?: string|null, shopping_list_item_id?: int|null, shopping_list_id?: int|null, planned_meal_id?: int|null}  $data
     */
    public function add(array $data): StockItem
    {
        $ingredient = isset($data['ingredient_id']) ? Ingredient::find($data['ingredient_id']) : null;
        $label = trim((string) ($data['label'] ?? ''));

        if (! $ingredient && $label === '') {
            throw new InvalidArgumentException('Indiquez l\'ingrédient ou le nom du plat.');
        }

        if ($ingredient?->stock_mode === StockMode::None) {
            throw new InvalidArgumentException("« {$ingredient->name} » n'est pas suivi dans le stock (réglage de l'ingrédient).");
        }

        $defaults = $this->defaults($ingredient);
        $quantity = isset($data['quantity']) && $data['quantity'] !== '' ? (float) $data['quantity'] : null;

        if ($quantity !== null && ($quantity <= 0 || $quantity > 1_000_000)) {
            throw new InvalidArgumentException('La quantité doit être positive.');
        }

        $presence = $ingredient?->stock_mode === StockMode::Presence;

        // Mode présence : un seul article « en stock » par ingrédient.
        if ($presence && ($existing = $ingredient->stockItems()->active()->first())) {
            return $existing;
        }

        $locationId = (int) ($data['storage_location_id'] ?? $defaults['storage_location_id']);

        if (! StorageLocation::whereKey($locationId)->exists()) {
            throw new InvalidArgumentException('Choisissez un emplacement.');
        }

        return DB::transaction(function () use ($data, $ingredient, $label, $quantity, $presence, $locationId, $defaults) {
            $item = StockItem::create([
                'ingredient_id' => $ingredient?->id,
                'label' => $ingredient ? null : mb_substr($label, 0, 150),
                'planned_meal_id' => $data['planned_meal_id'] ?? null,
                'quantity' => $presence ? null : $quantity,
                'initial_quantity' => $presence ? null : $quantity,
                'unit_id' => $presence || $quantity === null ? null : ($data['unit_id'] ?? $defaults['unit_id']),
                'is_present' => true,
                'storage_location_id' => $locationId,
                'expires_on' => array_key_exists('expires_on', $data) ? ($data['expires_on'] ?: null) : $defaults['expires_on'],
                'expiry_type' => ExpiryType::tryFrom((string) ($data['expiry_type'] ?? '')) ?? ExpiryType::from($defaults['expiry_type']),
                'note' => trim((string) ($data['note'] ?? '')) ?: null,
                'shopping_list_item_id' => $data['shopping_list_item_id'] ?? null,
                'created_by' => auth()->id(),
            ]);

            // `link_movement` à false : l'article est lié au repas, mais son entrée n'est pas une « déduction »
            // du repas (plat cuisiné à l'avance, 14.8) — décocher « mangé » ne doit pas le faire disparaître.
            $this->record($item, MovementType::In, $item->quantity === null ? null : (float) $item->quantity, null, [
                'shopping_list_id' => $data['shopping_list_id'] ?? null,
                'planned_meal_id' => ($data['link_movement'] ?? true) ? ($data['planned_meal_id'] ?? null) : null,
            ]);

            return $item->load('ingredient', 'unit', 'location');
        });
    }

    /* ================================================================ Sorties */

    /** Terminé (consommé) ou jeté, avec une raison. */
    public function finish(StockItem $item, MovementType $type = MovementType::Consume, ?string $reason = null): StockMovement
    {
        $this->ensureActive($item);

        return $this->change($item, $type, fn (StockItem $i) => $i->finished_at = Carbon::now(),
            quantity: $item->quantity === null ? null : -(float) $item->quantity, reason: $reason);
    }

    /** « Il en reste ¾ · ½ · ¼ » : fraction du paquet de départ. */
    public function setRemaining(StockItem $item, float $fraction): StockMovement
    {
        $this->ensureActive($item);

        if ($fraction <= 0 || $fraction >= 1) {
            throw new InvalidArgumentException('Fraction invalide.');
        }

        if ($item->quantity === null) {
            return $this->change($item, MovementType::Adjust, function (StockItem $i) use ($fraction) {
                $i->note = trim(preg_replace('/\s*·?\s*(entamé|reste ¾|reste ½|reste ¼)$/u', '', (string) $i->note).' · reste '.['0.75' => '¾', '0.5' => '½', '0.25' => '¼'][(string) $fraction], ' ·') ?: null;
                $i->opened_on ??= Carbon::today();
            });
        }

        $base = (float) ($item->initial_quantity ?? $item->quantity);

        return $this->setQuantity($item, round($base * $fraction, 3));
    }

    public function setQuantity(StockItem $item, float $quantity): StockMovement
    {
        $this->ensureActive($item);

        if ($quantity < 0) {
            throw new InvalidArgumentException('La quantité ne peut pas être négative.');
        }

        if ($quantity == 0.0) {
            return $this->finish($item);
        }

        $delta = $quantity - (float) $item->quantity;

        return $this->change($item, MovementType::Adjust, function (StockItem $i) use ($quantity) {
            $i->quantity = $quantity;
            $i->initial_quantity ??= $quantity;
        }, quantity: $delta);
    }

    /* ================================================================ Transformations */

    public function open(StockItem $item): StockMovement
    {
        $this->ensureActive($item);

        return $this->change($item, MovementType::Open, fn (StockItem $i) => $i->opened_on = Carbon::today());
    }

    public function freeze(StockItem $item): StockMovement
    {
        $this->ensureActive($item);

        if ($item->ingredient && $item->ingredient->freezer_months === null) {
            throw new InvalidArgumentException("« {$item->ingredient->name} » n'est pas indiqué comme congelable (réglage de l'ingrédient).");
        }

        $freezer = StorageLocation::firstOfType(LocationType::Freezer) ?? throw new InvalidArgumentException('Aucun emplacement de type congélation.');

        return $this->change($item, MovementType::Freeze, function (StockItem $i) use ($freezer) {
            $i->storage_location_id = $freezer->id;
            $i->frozen_on = Carbon::today();
            $i->thawed_on = null;
        });
    }

    public function thaw(StockItem $item): StockMovement
    {
        $this->ensureActive($item);

        if (! $item->isFrozen()) {
            throw new InvalidArgumentException('Cet article n\'est pas congelé.');
        }

        $fridge = StorageLocation::firstOfType(LocationType::Fresh);

        return $this->change($item, MovementType::Thaw, function (StockItem $i) use ($fridge) {
            $i->storage_location_id = $fridge?->id ?? $i->storage_location_id;
            $i->thawed_on = Carbon::today();
        });
    }

    public function move(StockItem $item, int $locationId): StockMovement
    {
        $this->ensureActive($item);
        $location = StorageLocation::findOrFail($locationId);

        return $this->change($item, MovementType::Move, fn (StockItem $i) => $i->storage_location_id = $location->id);
    }

    /**
     * Correction des informations (date, type, note, unité, quantité).
     *
     * @param  array{expires_on?: string|null, expiry_type?: string, note?: string|null, quantity?: float|null, unit_id?: int|null}  $data
     */
    public function edit(StockItem $item, array $data): StockMovement
    {
        $this->ensureActive($item);
        $delta = null;

        if (array_key_exists('quantity', $data) && $item->ingredient?->stock_mode !== StockMode::Presence) {
            $new = $data['quantity'] === null || $data['quantity'] === '' ? null : (float) $data['quantity'];

            if ($new !== null && $new <= 0) {
                throw new InvalidArgumentException('La quantité doit être positive.');
            }

            $delta = $new === null ? null : $new - (float) $item->quantity;
        }

        return $this->change($item, MovementType::Adjust, function (StockItem $i) use ($data) {
            if (array_key_exists('expires_on', $data)) {
                $i->expires_on = $data['expires_on'] ?: null;
            }
            if (isset($data['expiry_type']) && ExpiryType::tryFrom($data['expiry_type'])) {
                $i->expiry_type = ExpiryType::from($data['expiry_type']);
            }
            if (array_key_exists('note', $data)) {
                $i->note = trim((string) $data['note']) ?: null;
            }
            if (array_key_exists('quantity', $data) && $i->ingredient?->stock_mode !== StockMode::Presence) {
                $i->quantity = $data['quantity'] === null || $data['quantity'] === '' ? null : (float) $data['quantity'];
                $i->initial_quantity = $i->quantity === null ? null : max((float) $i->initial_quantity, (float) $i->quantity);
                $i->unit_id = $i->quantity === null ? null : ($data['unit_id'] ?? $i->unit_id);
            }
        }, quantity: $delta);
    }

    /* ================================================================ Mode présence */

    /** « En stock » / « Plus rien » pour un ingrédient suivi en présence. */
    public function setPresence(Ingredient $ingredient, bool $present): ?StockMovement
    {
        $items = $ingredient->stockItems()->active()->get();

        if ($present) {
            if ($items->isEmpty()) {
                $item = $this->add(['ingredient_id' => $ingredient->id]);

                return $item->movements()->latest('id')->first();
            }

            return null;
        }

        $last = null;

        foreach ($items as $item) {
            $last = $this->finish($item);
        }

        return $last;
    }

    /* ================================================================ Annulation */

    /** Dernier mouvement annulable de l'utilisateur (dernière action sur l'article, dans le délai). */
    public function lastUndoable(?int $userId = null): ?StockMovement
    {
        $movement = StockMovement::query()
            ->where('user_id', $userId ?? auth()->id())
            ->where('type', '!=', MovementType::Undo->value)
            ->where('created_at', '>=', Carbon::now()->subMinutes((int) config('bouffe.stock.undo_minutes', 15)))
            ->whereNotNull('stock_item_id')
            ->latest('id')
            ->first();

        return $movement && $this->isUndoable($movement) ? $movement : null;
    }

    public function isUndoable(StockMovement $movement): bool
    {
        if ($movement->type === MovementType::Undo || ! $movement->stock_item_id) {
            return false;
        }

        $latest = StockMovement::query()->where('stock_item_id', $movement->stock_item_id)->latest('id')->value('id');

        return $latest === $movement->id
            && $movement->created_at->gte(Carbon::now()->subMinutes((int) config('bouffe.stock.undo_minutes', 15)));
    }

    public function undo(StockMovement $movement): StockItem
    {
        if (! $this->isUndoable($movement)) {
            throw new InvalidArgumentException('Cette action ne peut plus être annulée.');
        }

        return DB::transaction(function () use ($movement) {
            $item = StockItem::findOrFail($movement->stock_item_id);

            if ($movement->type === MovementType::In) {
                $item->finished_at = Carbon::now();   // ajout annulé : l'article disparaît
            } else {
                foreach ($movement->snapshot ?? [] as $field => $value) {
                    $item->{$field} = $value;
                }
            }

            $item->save();

            StockMovement::create([
                'stock_item_id' => $item->id,
                'ingredient_id' => $item->ingredient_id,
                'label' => $item->name(),
                'type' => MovementType::Undo,
                'quantity' => $movement->quantity === null ? null : -(float) $movement->quantity,
                'unit_id' => $movement->unit_id,
                'reverts_movement_id' => $movement->id,
                'user_id' => auth()->id(),
            ]);

            return $item->fresh(['ingredient', 'unit', 'location']);
        });
    }

    /* ================================================================ Outils */

    private function change(StockItem $item, MovementType $type, callable $mutate, ?float $quantity = null, ?string $reason = null): StockMovement
    {
        return DB::transaction(function () use ($item, $type, $mutate, $quantity, $reason) {
            $snapshot = $this->snapshot($item);
            $mutate($item);
            $item->save();

            return $this->record($item, $type, $quantity, $reason, ['snapshot' => $snapshot]);
        });
    }

    private function record(StockItem $item, MovementType $type, ?float $quantity, ?string $reason = null, array $extra = []): StockMovement
    {
        return StockMovement::create(array_merge([
            'stock_item_id' => $item->id,
            'ingredient_id' => $item->ingredient_id,
            'label' => $item->ingredient?->name ?? (string) $item->label,
            'type' => $type,
            'quantity' => $quantity === null ? null : round($quantity, 3),
            'unit_id' => $item->unit_id,
            'reason' => $reason ? mb_substr($reason, 0, 50) : null,
            'user_id' => auth()->id(),
        ], $this->context, array_filter($extra, fn ($v) => $v !== null)));
    }

    /** @return array<string, mixed> */
    private function snapshot(StockItem $item): array
    {
        $snapshot = [];

        foreach (self::SNAPSHOT_FIELDS as $field) {
            $value = $item->getAttributes()[$field] ?? null;
            $snapshot[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value;
        }

        return $snapshot;
    }

    private function ensureActive(StockItem $item): void
    {
        if ($item->finished_at !== null) {
            throw new InvalidArgumentException('Cet article n\'est plus en stock.');
        }
    }
}
