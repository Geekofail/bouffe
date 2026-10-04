<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * « Choisis ton dîner de mercredi » (lot 39, 39.3, R42) : trois recettes proposées par un adulte.
 *
 * @property int $id
 * @property int|null $person_id
 * @property Carbon $date
 * @property int $meal_slot_id
 * @property list<int> $recipe_ids
 * @property bool $allow_plan
 * @property int|null $chosen_recipe_id
 * @property Carbon|null $chosen_at
 * @property int|null $wish_id
 * @property int|null $planned_meal_id
 * @property int|null $created_by
 */
#[Fillable(['person_id', 'date', 'meal_slot_id', 'recipe_ids', 'allow_plan', 'chosen_recipe_id', 'chosen_at', 'wish_id', 'planned_meal_id', 'created_by'])]
class ChildChoice extends Model
{
    use \App\Models\Concerns\BelongsToHousehold;

    protected function casts(): array
    {
        return ['date' => 'date', 'recipe_ids' => 'array', 'allow_plan' => 'boolean', 'chosen_at' => 'datetime'];
    }

    /** @return BelongsTo<HouseholdPerson, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(HouseholdPerson::class, 'person_id');
    }

    /** @return BelongsTo<MealSlot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(MealSlot::class, 'meal_slot_id');
    }

    /** @return BelongsTo<Recipe, $this> */
    public function chosenRecipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'chosen_recipe_id');
    }

    /** Choix encore à faire, aujourd'hui ou plus tard. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('chosen_at')->whereDate('date', '>=', today()->toDateString());
    }

    /** @return Collection<int, Recipe> les recettes proposées, dans l'ordre */
    public function recipes(): Collection
    {
        $ids = array_map('intval', (array) $this->recipe_ids);
        $recipes = Recipe::query()->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn (int $id) => $recipes->get($id))->filter()->values();
    }

    /** « dîner de mercredi » */
    public function dayLabel(): string
    {
        return mb_strtolower((string) $this->slot?->name).' de '.$this->date->locale('fr')->isoFormat('dddd');
    }
}
