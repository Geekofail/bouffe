<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ce qu'un foyer ouvre à un foyer relié (lot 26) : chacun règle son côté.
 *
 * @property int $household_id qui partage
 * @property int $target_household_id avec qui
 * @property bool $recipes_all tout le carnet visible (26.1)
 * @property string $planning none · read · write (26.4)
 */
#[Fillable(['household_id', 'target_household_id', 'recipes_all', 'planning'])]
class HouseholdShare extends Model
{
    public const PLANNING = ['none' => 'Fermé', 'read' => 'Lecture', 'write' => 'Lecture et écriture'];

    protected $attributes = ['recipes_all' => false, 'planning' => 'none'];

    protected function casts(): array
    {
        return ['recipes_all' => 'boolean'];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<Household, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'target_household_id');
    }
}
