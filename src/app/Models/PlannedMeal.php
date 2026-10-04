<?php

namespace App\Models;

use App\Enums\MealType;
use Database\Factories\PlannedMealFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Élément planifié dans une case du planning (date × créneau).
 *
 *  - recipe   : une recette, pour N portions → alimente la liste de courses
 *  - leftover : les restes d'un repas précédent (leftover_of_id) → aucun achat
 *  - free     : texte libre (« Resto », « Chez les parents ») → aucun achat
 *
 * @property int $id
 * @property Carbon $date
 * @property int $meal_slot_id
 * @property int $position
 * @property MealType $type
 * @property int|null $recipe_id
 * @property int|null $leftover_of_id
 * @property string|null $free_text
 * @property float $servings portions, par demi-portion (lot 32, R33)
 * @property int|null $for_person_id gamelle de… (32.3 ; une personne du foyer depuis le lot 39)
 * @property bool $is_lunchbox
 * @property string|null $comment
 * @property Carbon|null $cooked_at
 * @property Carbon|null $prepared_at cuisiné à l'avance (14.8)
 * @property Carbon|null $skipped_at prévu mais pas fait (lot 21, R23)
 * @property bool $closed_automatically marqué mangé par la clôture automatique (R23)
 * @property string|null $stock_state retrait du stock : pending · done · ignored (22.2)
 * @property \App\Enums\Course|null $course place dans le menu (21.1)
 * @property-read Recipe|null $recipe
 * @property-read PlannedMeal|null $leftoverOf
 * @property-read MealSlot $slot
 */
#[Fillable(['date', 'meal_slot_id', 'position', 'course', 'for_occasion_id', 'prepared_at', 'skipped_at', 'closed_automatically', 'stock_state', 'type', 'recipe_id', 'leftover_of_id', 'free_text', 'servings', 'for_person_id', 'is_lunchbox', 'cook_user_id', 'cook_together', 'comment', 'cooked_at', 'created_by'])]
class PlannedMeal extends Model
{
    /** @use HasFactory<PlannedMealFactory> */
    use \App\Models\Concerns\BelongsToHousehold, HasFactory;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => MealType::class,
            'position' => 'integer',
            'servings' => 'float',
            'is_lunchbox' => 'boolean',
            'cooked_at' => 'datetime',
            'prepared_at' => 'datetime',
            'skipped_at' => 'datetime',
            'closed_automatically' => 'boolean',
            'course' => \App\Enums\Course::class,
            'cook_together' => 'boolean',
        ];
    }

    /** Toujours stocker la date seule (AAAA-MM-JJ), quel que soit le moteur de base. */
    public function setDateAttribute(mixed $value): void
    {
        $this->attributes['date'] = Carbon::parse($value)->toDateString();
    }

    /**
     * La recette peut venir d'un foyer relié, planifiée telle quelle (26.2) : lue sans le filtre du
     * foyer actif. Le repas, lui, reste filtré ; la recette a été vérifiée visible à la planification.
     *
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class)->withoutGlobalScope(Scopes\HouseholdScope::class);
    }

    /** Réception d'un foyer relié à laquelle ce plat est apporté (26.5). @return BelongsTo<MealOccasion, $this> */
    public function forOccasion(): BelongsTo
    {
        return $this->belongsTo(MealOccasion::class, 'for_occasion_id')->withoutGlobalScope(Scopes\HouseholdScope::class);
    }

    /** @return BelongsTo<MealSlot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(MealSlot::class, 'meal_slot_id');
    }

    /** @return BelongsTo<PlannedMeal, $this> */
    public function leftoverOf(): BelongsTo
    {
        return $this->belongsTo(PlannedMeal::class, 'leftover_of_id');
    }

    /** @return HasMany<PlannedMeal, $this> */
    public function leftovers(): HasMany
    {
        return $this->hasMany(PlannedMeal::class, 'leftover_of_id');
    }

    /** Gamelle de… (lot 32, 32.3 ; lot 39 : n'importe quelle personne du foyer). @return BelongsTo<HouseholdPerson, $this> */
    public function forPerson(): BelongsTo
    {
        return $this->belongsTo(HouseholdPerson::class, 'for_person_id');
    }

    /** Qui cuisine ce repas (14.7). @return BelongsTo<User, $this> */
    public function cook(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cook_user_id');
    }

    /** Qui fait quelle étape, et ce qui est fait (lot 41, 41.3). @return HasMany<MealTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(MealTask::class);
    }

    /** Réactions au repas (18.4). @return HasMany<MealReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(MealReaction::class);
    }

    /** « Pierre », « Ensemble » ou null si personne n'est désigné. */
    public function cookLabel(): ?string
    {
        return $this->cook_together ? 'Ensemble' : $this->cook?->name;
    }

    /** Rappels de préparation anticipée (14.6). @return HasMany<Reminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    /** Plat cuisiné à l'avance et rangé au stock (14.8). @return \Illuminate\Database\Eloquent\Relations\HasOne<StockItem, $this> */
    public function preparedDish(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StockItem::class)->whereNull('finished_at')->latest('id');
    }

    public function isSkipped(): bool
    {
        return $this->skipped_at !== null;
    }

    public function isPrepared(): bool
    {
        return $this->prepared_at !== null;
    }

    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
    }

    public function isRecipe(): bool
    {
        return $this->type === MealType::Recipe;
    }

    public function isLeftover(): bool
    {
        return $this->type === MealType::Leftover;
    }

    public function isFree(): bool
    {
        return $this->type === MealType::Free;
    }

    /** Titre affiché dans la case. */
    public function label(): string
    {
        return match ($this->type) {
            MealType::Recipe => $this->recipe?->title ?? 'Recette supprimée',
            MealType::Leftover => ($this->isLunchbox() ? $this->lunchboxLabel() : 'Restes').' : '.($this->leftoverOf?->recipe?->title ?? $this->leftoverOf?->free_text ?? 'repas précédent'),
            MealType::Free => (string) $this->free_text,
        };
    }

    /** Gamelle du midi (lot 32, 32.3) : des restes emportés par une personne. */
    public function isLunchbox(): bool
    {
        return $this->is_lunchbox && $this->isLeftover();
    }

    /** « Gamelle de Pierre », « Gamelle d'Anne ». */
    public function lunchboxLabel(): string
    {
        return self::lunchboxLabelFor($this->forPerson?->name);
    }

    public static function lunchboxLabelFor(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return 'Gamelle';
        }

        return preg_match('/^[aeiouyhàâäéèêëîïôöùûü]/iu', $name) ? 'Gamelle d\''.$name : 'Gamelle de '.$name;
    }

    /** Recette réellement mangée (y compris pour des restes). */
    public function eatenRecipe(): ?Recipe
    {
        return $this->isLeftover() ? $this->leftoverOf?->recipe : $this->recipe;
    }
}
