<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Ticket de caisse (lot 23 — module 24).
 *
 * draft : photos envoyées, pas encore lues (ou saisie manuelle) ·
 * review : lu, en relecture (R27 : rien n'est enregistré ailleurs avant « Valider ») ·
 * validated : dépense, prix, stock et liste mis à jour.
 *
 * @property int $id
 * @property string $status
 * @property string|null $provider
 * @property list<string>|null $photo_paths
 * @property int $pages
 * @property int|null $store_id
 * @property string|null $store_name
 * @property Carbon|null $purchased_on
 * @property string|null $total
 * @property string|null $read_total
 * @property array|null $raw_result
 * @property string|null $error
 * @property int|null $shopping_list_id
 * @property Carbon|null $read_at
 * @property Carbon|null $validated_at
 * @property Carbon|null $photos_deleted_at
 * @property array|null $outcome
 */
#[Fillable(['status', 'provider', 'photo_paths', 'pages', 'store_id', 'store_name', 'purchased_on', 'total', 'read_total', 'raw_result', 'error', 'shopping_list_id', 'read_at', 'validated_at', 'photos_deleted_at', 'outcome', 'created_by'])]
class Receipt extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    public const DRAFT = 'draft';

    public const REVIEW = 'review';

    public const VALIDATED = 'validated';

    protected function casts(): array
    {
        return [
            'photo_paths' => 'array',
            'pages' => 'integer',
            'purchased_on' => 'date',
            'total' => 'decimal:2',
            'read_total' => 'decimal:2',
            'raw_result' => 'array',
            'read_at' => 'datetime',
            'validated_at' => 'datetime',
            'photos_deleted_at' => 'datetime',
            'outcome' => 'array',
        ];
    }

    public function setPurchasedOnAttribute(mixed $value): void
    {
        $this->attributes['purchased_on'] = $value ? Carbon::parse($value)->toDateString() : null;
    }

    /** @return HasMany<ReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ReceiptLine::class)->orderBy('position')->orderBy('id');
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<ShoppingList, $this> */
    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    /** @return HasOne<Expense, $this> */
    public function expense(): HasOne
    {
        return $this->hasOne(Expense::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isValidated(): bool
    {
        return $this->status === self::VALIDATED;
    }

    public function hasPhotos(): bool
    {
        return ! $this->photos_deleted_at && ! empty($this->photo_paths);
    }

    public function storeLabel(): string
    {
        return $this->store?->name ?? ($this->store_name ?: 'Magasin inconnu');
    }
}
