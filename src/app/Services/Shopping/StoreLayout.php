<?php

namespace App\Services\Shopping;

use App\Models\Aisle;
use App\Models\Store;
use App\Models\StoreAisle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ordre des rayons dans un magasin donné (15.3).
 *
 * L'ordre général des rayons (Paramètres → Rayons) reste la référence : un magasin ne fait que
 * le réarranger. Un rayon qu'on ne trouve pas dans ce magasin peut être masqué — ses articles
 * ne disparaissent pas pour autant, ils sont montrés à part, sans quoi on les oublierait.
 */
class StoreLayout
{
    /** Crée les lignes manquantes pour ce magasin, dans l'ordre général des rayons. */
    public function sync(Store $store): void
    {
        $known = $store->storeAisles()->pluck('aisle_id')->all();
        $position = (int) $store->storeAisles()->max('position');

        foreach (Aisle::query()->ordered()->get() as $aisle) {
            if (in_array($aisle->id, $known, true)) {
                continue;
            }

            StoreAisle::create([
                'store_id' => $store->id,
                'aisle_id' => $aisle->id,
                'position' => ++$position,
            ]);
        }
    }

    /**
     * Les rayons du magasin, dans l'ordre où on les traverse.
     *
     * @return Collection<int, StoreAisle> avec la relation `aisle` chargée
     */
    public function rows(Store $store): Collection
    {
        $this->sync($store);

        return $store->storeAisles()->with('aisle')->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Rang de chaque rayon : `[aisle_id => position]`. Sans magasin, c'est l'ordre général.
     *
     * @return array<int, int>
     */
    public function order(?Store $store): array
    {
        if (! $store) {
            return Aisle::query()->ordered()->pluck('id')->values()
                ->mapWithKeys(fn (int $id, int $index) => [$id => $index + 1])->all();
        }

        return $this->rows($store)->mapWithKeys(fn (StoreAisle $row) => [$row->aisle_id => $row->position])->all();
    }

    /**
     * Rayons masqués dans ce magasin.
     *
     * @return list<int>
     */
    public function hidden(?Store $store): array
    {
        return $store
            ? $this->rows($store)->where('is_hidden', true)->pluck('aisle_id')->map(fn ($id) => (int) $id)->values()->all()
            : [];
    }

    /** Glisser-déposer : place un rayon à une position et renumérote le magasin. */
    public function move(Store $store, int $aisleId, int $position): void
    {
        DB::transaction(function () use ($store, $aisleId, $position) {
            $ids = $this->rows($store)->pluck('aisle_id')->map(fn ($id) => (int) $id);

            if (! $ids->contains($aisleId)) {
                return;
            }

            $ids = $ids->reject(fn (int $id) => $id === $aisleId)->values();
            $ids->splice(max(0, min($position, $ids->count())), 0, [$aisleId]);

            foreach ($ids as $index => $id) {
                StoreAisle::query()
                    ->where('store_id', $store->id)
                    ->where('aisle_id', $id)
                    ->update(['position' => $index + 1]);
            }
        });
    }

    public function toggleHidden(Store $store, int $aisleId): bool
    {
        $this->sync($store);

        $row = StoreAisle::query()->where('store_id', $store->id)->where('aisle_id', $aisleId)->firstOrFail();
        $row->update(['is_hidden' => ! $row->is_hidden]);

        return $row->is_hidden;
    }

    /** Reprend l'ordre général des rayons (Paramètres → Rayons) pour ce magasin. */
    public function reset(Store $store): void
    {
        $this->sync($store);

        DB::transaction(function () use ($store) {
            foreach (Aisle::query()->ordered()->get()->values() as $index => $aisle) {
                StoreAisle::query()
                    ->where('store_id', $store->id)
                    ->where('aisle_id', $aisle->id)
                    ->update(['position' => $index + 1]);
            }
        });
    }
}
