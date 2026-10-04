<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Argent d'un séjour (34.3) : une dépense avancée par un groupe, ou un remboursement coché
 * « remboursé » (R37 : Bouffe n'effectue aucun paiement).
 *
 * @property int $id
 * @property string $kind expense · refund
 * @property string $group_label
 * @property string|null $to_group_label
 * @property string $label
 * @property string $amount
 * @property Carbon $paid_on
 * @property int|null $household_id foyer qui l'a notée (lot 42) ; vide = le foyer qui organise
 */
#[Fillable(['household_id', 'kind', 'group_label', 'to_group_label', 'label', 'amount', 'paid_on', 'created_by'])]
class StayPayment extends Model
{
    public const EXPENSE = 'expense';

    public const REFUND = 'refund';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date'];
    }

    /** @return BelongsTo<Stay, $this> */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function isRefund(): bool
    {
        return $this->kind === self::REFUND;
    }
}
