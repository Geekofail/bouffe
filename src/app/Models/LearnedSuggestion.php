<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Proposition apprise, appliquée ou refusée (30.4, 30.5, R36).
 * Les propositions en attente ne sont pas enregistrées : elles se recalculent à l'affichage.
 *
 * @property int $ingredient_id
 * @property string $kind
 * @property array $proposed
 * @property string $status
 * @property Carbon|null $silenced_until
 */
#[Fillable(['ingredient_id', 'kind', 'proposed', 'status', 'silenced_until', 'decided_by'])]
class LearnedSuggestion extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    public const APPLIED = 'applied';

    public const DISMISSED = 'dismissed';

    protected function casts(): array
    {
        return [
            'proposed' => 'array',
            'silenced_until' => 'datetime',
        ];
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
