<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un appel au service de lecture (ticket ou recette) : suivi des coûts et plafond mensuel (24.7).
 *
 * @property int $id
 * @property string $purpose receipt · recipe
 * @property string $provider
 * @property int $pages
 * @property string $cost_estimate
 * @property bool $succeeded
 * @property Carbon|null $created_at
 */
#[Fillable(['purpose', 'provider', 'pages', 'cost_estimate', 'succeeded', 'receipt_id', 'user_id', 'created_at'])]
class OcrReading extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['pages' => 'integer', 'cost_estimate' => 'decimal:4', 'succeeded' => 'boolean'];
    }
}
