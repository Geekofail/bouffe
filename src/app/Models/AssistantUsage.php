<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Une demande à l'assistant culinaire (lot 33, 33.5) : jetons envoyés et reçus, coût estimé en
 * euros. Le contenu de la demande n'est jamais enregistré.
 *
 * @property int $id
 * @property string $kind transform · ideas · complete · question
 * @property int $tokens_in
 * @property int $tokens_out
 * @property string $cost_estimate
 * @property bool $succeeded
 * @property Carbon|null $created_at
 */
#[Fillable(['user_id', 'kind', 'tokens_in', 'tokens_out', 'cost_estimate', 'succeeded', 'created_at'])]
class AssistantUsage extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    public const UPDATED_AT = null;

    public const KINDS = [
        'transform' => 'Transformer une recette',
        'ideas' => 'Que faire avec…',
        'complete' => 'Compléter un import',
        'question' => 'Question en cuisine',
    ];

    protected function casts(): array
    {
        return ['tokens_in' => 'integer', 'tokens_out' => 'integer', 'cost_estimate' => 'decimal:6', 'succeeded' => 'boolean', 'created_at' => 'datetime'];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
