<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Poste de budget (lot 22, 23.2) : « Courses alimentaires », « Restaurant »…
 *
 * @property int $id
 * @property string $name
 * @property string $kind groceries · household · restaurant · takeaway · work · drinks · other
 * @property string $color
 * @property int $sort_order
 * @property Carbon|null $archived_at
 */
#[Fillable(['name', 'kind', 'color', 'sort_order', 'archived_at'])]
class BudgetCategory extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    public const KINDS = [
        'groceries' => 'Courses alimentaires',
        'household' => 'Droguerie et maison',
        'restaurant' => 'Restaurant',
        'takeaway' => 'À emporter / livraison',
        'work' => 'Midi au travail',
        'drinks' => 'Boissons',
        'other' => 'Autre',
    ];

    /** Postes de départ d'un foyer (question Q31) : [nom, genre, couleur]. */
    public const DEFAULTS = [
        ['Courses alimentaires', 'groceries', 'orange'],
        ['Droguerie et maison', 'household', 'sky'],
        ['Restaurant', 'restaurant', 'violet'],
        ['À emporter / livraison', 'takeaway', 'amber'],
        ['Midi au travail', 'work', 'green'],
        ['Boissons', 'drinks', 'pink'],
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'archived_at' => 'datetime'];
    }

    /** @return HasMany<BudgetAmount, $this> */
    public function amounts(): HasMany
    {
        return $this->hasMany(BudgetAmount::class)->orderByDesc('valid_from');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /** Un repas pris dehors : on peut compter un coût par personne et le relier au planning. */
    public function isEatingOut(): bool
    {
        return in_array($this->kind, ['restaurant', 'takeaway', 'work'], true);
    }

    public static function groceries(): ?self
    {
        return static::query()->where('kind', 'groceries')->orderBy('id')->first();
    }
}
