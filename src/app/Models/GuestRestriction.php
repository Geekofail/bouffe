<?php

namespace App\Models;

use App\Enums\RestrictionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $guest_id
 * @property RestrictionType $type
 * @property int|null $ingredient_id
 * @property int|null $tag_id
 * @property string|null $note
 * @property-read Ingredient|null $ingredient
 * @property-read Tag|null $tag
 */
#[Fillable(['type', 'ingredient_id', 'tag_id', 'note'])]
class GuestRestriction extends Model
{
    protected function casts(): array
    {
        return ['type' => RestrictionType::class];
    }

    /** @return BelongsTo<Guest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** @return BelongsTo<Tag, $this> */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    /** « noix », « Végétarien » */
    public function subject(): string
    {
        return $this->type->usesIngredient()
            ? (string) $this->ingredient?->name
            : (string) $this->tag?->name;
    }
}
