<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un repas du planning d'un séjour (34.1). Ses portions se calculent d'après les présents du jour,
 * sauf si on les a fixées.
 *
 * @property int $id
 * @property int $stay_id
 * @property Carbon $date
 * @property int $meal_slot_id
 * @property int|null $recipe_id
 * @property string|null $free_text
 * @property float|null $servings
 * @property int|null $household_id foyer qui l'a prévu (lot 42, 42.1) ; vide = le foyer qui organise
 */
#[Fillable(['household_id', 'date', 'meal_slot_id', 'position', 'recipe_id', 'free_text', 'servings'])]
class StayMeal extends Model
{
    protected function casts(): array
    {
        return ['date' => 'date', 'servings' => 'float', 'position' => 'integer'];
    }

    /** @return BelongsTo<Stay, $this> */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    /**
     * Lot 42 (42.1) : un foyer qui co-organise prévoit des plats de **son** carnet. Le titre se lit
     * donc sans le filtre du foyer actif ; la recette elle-même ne s'ouvre que chez son foyer.
     *
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class)->withoutGlobalScope(Scopes\HouseholdScope::class);
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** Un créneau du foyer qui organise (lot 42 : lu aussi par ceux qui co-organisent). @return BelongsTo<MealSlot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(MealSlot::class, 'meal_slot_id')->withoutGlobalScope(Scopes\HouseholdScope::class);
    }

    public function label(): string
    {
        return $this->recipe?->title ?? (string) ($this->free_text ?: 'Recette supprimée');
    }
}
