<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * « Qui apporte quoi » (lot 42, 42.2) : une ligne de la liste « à apporter » d'une réception ou d'un
 * séjour. Sans `by_label`, personne ne l'a encore prise. Inscrite depuis Bouffe (un foyer, un
 * compte) ou par le lien envoyé à ceux qui n'ont pas Bouffe (`token_hash` : la clé de leur
 * navigateur, qui leur permet de se raviser).
 *
 * @property int $id
 * @property int|null $meal_occasion_id
 * @property int|null $stay_id
 * @property string $label
 * @property int|null $ingredient_id
 * @property int|null $planned_meal_id
 * @property int|null $stay_meal_id
 * @property string|null $by_label
 * @property int|null $by_household_id
 * @property int|null $by_user_id
 * @property string|null $token_hash
 */
#[Fillable(['household_id', 'meal_occasion_id', 'stay_id', 'label', 'ingredient_id', 'planned_meal_id', 'stay_meal_id', 'by_label', 'by_household_id', 'by_user_id', 'token_hash', 'created_by'])]
class Contribution extends Model
{
    use Concerns\BelongsToHousehold;

    public const MAX_PER_EVENT = 60;

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** Le plat du foyer qui reçoit. @return BelongsTo<PlannedMeal, $this> */
    public function plannedMeal(): BelongsTo
    {
        return $this->belongsTo(PlannedMeal::class)->withoutGlobalScope(Scopes\HouseholdScope::class);
    }

    /** @return BelongsTo<StayMeal, $this> */
    public function stayMeal(): BelongsTo
    {
        return $this->belongsTo(StayMeal::class);
    }

    /** @return BelongsTo<Household, $this> */
    public function byHousehold(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'by_household_id');
    }

    public function isTaken(): bool
    {
        return $this->by_label !== null && $this->by_label !== '';
    }
}
