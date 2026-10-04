<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Tri manuel par colonne `sort_order` (rayons, unités, créneaux…).
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasSortOrder
{
    public static function bootHasSortOrder(): void
    {
        static::creating(function (self $model) {
            if (! $model->sort_order) {
                $model->sort_order = (int) static::query()->max('sort_order') + 1;
            }
        });
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Déplace un élément à une position (0 = premier) et renumérote toute la liste.
     * Signature compatible avec la directive Livewire `wire:sort`.
     */
    public static function moveToPosition(int|string $id, int $position): void
    {
        DB::transaction(function () use ($id, $position) {
            $ids = static::query()->ordered()->pluck('id')->map(fn ($value) => (int) $value);

            if (! $ids->contains((int) $id)) {
                return;
            }

            $ids = $ids->reject(fn (int $value) => $value === (int) $id)->values();
            $ids->splice(max(0, min($position, $ids->count())), 0, [(int) $id]);

            foreach ($ids as $index => $value) {
                static::query()->whereKey($value)->update(['sort_order' => $index + 1]);
            }
        });
    }
}
