<?php

namespace App\Models;

use App\Enums\RestrictionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Goût ou allergie d'une personne du foyer (18.1 ; lot 39 : rattachée à la personne, compte ou pas).
 *
 * Même forme que GuestRestriction, pour que GuestCompatibility traite de la même façon un invité
 * et une personne du foyer.
 *
 * @property int $id
 * @property int $person_id
 * @property RestrictionType $type
 * @property int|null $ingredient_id
 * @property int|null $tag_id
 * @property string|null $note
 */
#[Fillable(['person_id', 'type', 'ingredient_id', 'tag_id', 'note'])]
class PersonRestriction extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    protected function casts(): array
    {
        return ['type' => RestrictionType::class];
    }

    /** @return BelongsTo<HouseholdPerson, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(HouseholdPerson::class, 'person_id');
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
