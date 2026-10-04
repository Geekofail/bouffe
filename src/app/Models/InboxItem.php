<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une recette reçue dans « À trier » (lot 38, 38.3) : une adresse, un texte ou une photo de page.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $kind url · text · photo
 * @property string $via raccourci · partage · app
 * @property string|null $url
 * @property string|null $text
 * @property string|null $photo_path
 * @property string|null $title
 * @property string|null $image_url
 * @property array|null $payload
 * @property string $status pending · ready · failed · kept · discarded
 * @property string|null $error
 * @property int|null $recipe_id
 */
#[Fillable(['user_id', 'kind', 'via', 'url', 'text', 'photo_path', 'title', 'image_url', 'payload', 'status', 'error', 'recipe_id'])]
class InboxItem extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    public const PENDING = 'pending';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const KEPT = 'kept';

    public const DISCARDED = 'discarded';

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** Encore à trier : en attente, prêtes ou en échec. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::PENDING, self::READY, self::FAILED]);
    }

    public function label(): string
    {
        return $this->title
            ?: ($this->url ? (parse_url($this->url, PHP_URL_HOST) ?: $this->url) : null)
            ?: ($this->kind === 'photo' ? 'Photo d\'une recette' : \Illuminate\Support\Str::limit((string) $this->text, 60));
    }
}
