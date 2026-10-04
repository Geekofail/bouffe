<?php

namespace App\Models;

use App\Enums\LocationType;
use App\Models\Concerns\HasSortOrder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property LocationType $type
 * @property int $sort_order
 * @property Carbon|null $last_inventory_at
 */
#[Fillable(['name', 'type', 'sort_order', 'last_inventory_at'])]
class StorageLocation extends Model
{
    use \App\Models\Concerns\BelongsToHousehold, HasSortOrder;

    protected function casts(): array
    {
        return ['type' => LocationType::class, 'sort_order' => 'integer', 'last_inventory_at' => 'datetime'];
    }

    /** @return HasMany<StockItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }

    public static function firstOfType(LocationType $type): ?self
    {
        return static::query()->where('type', $type->value)->ordered()->first();
    }

    /**
     * Emplacement du foyer actif équivalent à celui donné (R30) : un réglage par défaut du catalogue
     * désigne l'emplacement d'un autre foyer ; on prend ici celui du même nom, sinon du même type.
     */
    public static function equivalentFor(int $id): ?int
    {
        $household = (int) \App\Support\CurrentHousehold::id();
        $cache = app()->bound('bouffe.locations') ? app('bouffe.locations') : [];

        if (! array_key_exists($household, $cache)) {
            $cache[$household] = ['own' => \Illuminate\Support\Facades\DB::table('storage_locations')->where('household_id', $household)->orderBy('sort_order')->get(['id', 'name', 'type'])->all(), 'mapped' => []];
        }

        $own = $cache[$household]['own'];

        foreach ($own as $location) {
            if ((int) $location->id === $id) {
                return $id;
            }
        }

        if (! array_key_exists($id, $cache[$household]['mapped'])) {
            $source = \Illuminate\Support\Facades\DB::table('storage_locations')->where('id', $id)->first(['name', 'type']);
            $match = null;

            foreach ($own as $location) {
                if ($source && mb_strtolower($location->name) === mb_strtolower($source->name)) {
                    $match = (int) $location->id;
                    break;
                }
            }

            foreach ($own as $location) {
                if ($match === null && $source && $location->type === $source->type) {
                    $match = (int) $location->id;
                }
            }

            $cache[$household]['mapped'][$id] = $match ?? (isset($own[0]) ? (int) $own[0]->id : null);
        }

        app()->instance('bouffe.locations', $cache);

        return $cache[$household]['mapped'][$id];
    }

    protected static function booted(): void
    {
        static::saved(fn () => app()->forgetInstance('bouffe.locations'));
        static::deleted(fn () => app()->forgetInstance('bouffe.locations'));
    }
}
