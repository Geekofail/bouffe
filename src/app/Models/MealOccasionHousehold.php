<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Foyer relié invité à une réception (26.5) : il répond, dit combien ils seront, et apporte des plats
 * (repas de son propre planning marqués `for_occasion_id`).
 *
 * @property int $id
 * @property int $meal_occasion_id
 * @property int $household_id
 * @property string $status invited · accepted · declined
 * @property int|null $people
 * @property string|null $message
 * @property Carbon|null $responded_at
 */
#[Fillable(['meal_occasion_id', 'household_id', 'status', 'people', 'message', 'invited_by', 'responded_at'])]
class MealOccasionHousehold extends Model
{
    public const STATUSES = ['invited' => 'Invité', 'accepted' => 'Vient', 'declined' => 'Ne vient pas'];

    protected function casts(): array
    {
        return ['people' => 'integer', 'responded_at' => 'datetime'];
    }

    /** L'occasion appartient au foyer qui reçoit : lue sans le filtre du foyer actif. @return BelongsTo<MealOccasion, $this> */
    public function occasion(): BelongsTo
    {
        return $this->belongsTo(MealOccasion::class, 'meal_occasion_id')->withoutGlobalScope(Scopes\HouseholdScope::class);
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }
}
