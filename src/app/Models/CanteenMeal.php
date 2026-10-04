<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un midi de cantine (lot 39, 39.2, R41) : ce qui y est servi, ou « pas de cantine ce jour-là »
 * (vacances, malade). Un jour de cantine sans ligne est « cantine » sans détail.
 *
 * @property int $id
 * @property int $person_id
 * @property Carbon $date
 * @property string $status canteen · home
 * @property string|null $label
 * @property list<string>|null $families
 * @property string $source manual · paste · photo
 */
#[Fillable(['person_id', 'date', 'status', 'label', 'families', 'source'])]
class CanteenMeal extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    public const CANTEEN = 'canteen';

    public const HOME = 'home';

    protected function casts(): array
    {
        return ['date' => 'date', 'families' => 'array'];
    }

    /** @return BelongsTo<HouseholdPerson, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(HouseholdPerson::class, 'person_id');
    }
}
