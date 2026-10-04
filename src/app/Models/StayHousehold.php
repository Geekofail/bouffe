<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un foyer relié invité à co-organiser un séjour (lot 42, 42.1, R45). Le séjour reste rangé chez
 * le foyer qui l'organise ; un foyer qui a accepté y prévoit des repas, note ses dépenses, gère ses
 * participants et ce qu'il emporte de **son** stock.
 *
 * @property int $id
 * @property int $stay_id
 * @property int $household_id
 * @property string $status invited · accepted · declined · removed · left
 * @property Carbon|null $responded_at
 * @property Carbon|null $departed_at
 * @property Carbon|null $returned_at
 */
#[Fillable(['stay_id', 'household_id', 'status', 'invited_by', 'responded_at', 'departed_at', 'returned_at'])]
class StayHousehold extends Model
{
    public const STATUSES = [
        'invited' => 'Invité',
        'accepted' => 'Co-organise',
        'declined' => 'A refusé',
        'removed' => 'Retiré',
        'left' => 'A quitté',
    ];

    protected function casts(): array
    {
        return ['responded_at' => 'datetime', 'departed_at' => 'datetime', 'returned_at' => 'datetime'];
    }

    /** Le séjour appartient au foyer qui organise : lu sans le filtre du foyer actif. @return BelongsTo<Stay, $this> */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class)->withoutGlobalScope(Scopes\HouseholdScope::class);
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

    /** Retiré par l'organisateur, ou parti de lui-même : ses dépenses restent, marquées (R45). */
    public function isGone(): bool
    {
        return in_array($this->status, ['removed', 'left'], true);
    }
}
