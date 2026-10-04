<?php

namespace App\Models\Concerns;

use App\Models\Household;
use App\Models\Scopes\HouseholdScope;
use App\Support\CurrentHousehold;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Donnée propre à un foyer (lot 24, R29) : lue seulement dans le foyer actif, créée dans ce foyer.
 *
 * Pour lire au-delà (administration, suppression d'un foyer) :
 *   Modele::withoutGlobalScope(HouseholdScope::class) — ou CurrentHousehold::run($foyer, fn () => …).
 */
trait BelongsToHousehold
{
    public static function bootBelongsToHousehold(): void
    {
        static::addGlobalScope(new HouseholdScope);

        static::creating(function ($model) {
            if (empty($model->household_id)) {
                $model->household_id = CurrentHousehold::id();
            }
        });
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
