<?php

namespace App\Services\Linked;

use App\Enums\Course;
use App\Models\Household;
use App\Models\MealOccasion;
use App\Models\MealOccasionHousehold;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\Scopes\HouseholdScope;
use App\Models\User;
use App\Services\Planning\WeekPlanner;
use App\Support\CurrentHousehold;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Repas commun (26.5) : une réception invite un foyer relié entier.
 *
 *  - le foyer invité répond (vient ou non, combien de personnes) ;
 *  - il choisit ce qu'il apporte : ce sont des repas de **son** planning, le même jour, marqués
 *    `for_occasion_id` — leurs ingrédients vont donc dans **sa** liste de courses et dans **son**
 *    rétroplanning, jamais dans ceux du foyer qui reçoit ;
 *  - le foyer qui reçoit voit « qui apporte quoi » et compte les personnes annoncées.
 */
class SharedMeals
{
    public function __construct(private readonly HouseholdLinks $links) {}

    /* ================================================================ Côté foyer qui reçoit */

    public function invite(MealOccasion $occasion, int $householdId, ?User $user = null): MealOccasionHousehold
    {
        if (! $this->links->areLinked((int) $occasion->household_id, $householdId)) {
            throw new InvalidArgumentException('Seul un foyer relié peut être invité.');
        }

        return MealOccasionHousehold::query()->firstOrCreate(
            ['meal_occasion_id' => $occasion->id, 'household_id' => $householdId],
            ['status' => 'invited', 'invited_by' => $user?->id],
        );
    }

    public function cancel(MealOccasion $occasion, int $householdId): void
    {
        MealOccasionHousehold::query()->where('meal_occasion_id', $occasion->id)->where('household_id', $householdId)->delete();

        // Les plats déjà prévus par ce foyer restent dans son planning, sans lien avec la réception.
        PlannedMeal::query()->withoutGlobalScope(HouseholdScope::class)
            ->where('for_occasion_id', $occasion->id)->where('household_id', $householdId)
            ->update(['for_occasion_id' => null]);
    }

    /** @return Collection<int, MealOccasionHousehold> foyers invités, avec leur nom */
    public function guestsOf(MealOccasion $occasion): Collection
    {
        return MealOccasionHousehold::query()->where('meal_occasion_id', $occasion->id)->with('household')->orderBy('id')->get();
    }

    /** Personnes annoncées par les foyers qui viennent. */
    public function linkedPeople(?MealOccasion $occasion): int
    {
        if (! $occasion || ! $occasion->exists) {
            return 0;
        }

        return (int) MealOccasionHousehold::query()->where('meal_occasion_id', $occasion->id)->where('status', 'accepted')->sum('people');
    }

    /** Plats apportés par les foyers invités. @return Collection<int, PlannedMeal> */
    public function broughtDishes(MealOccasion $occasion): Collection
    {
        return PlannedMeal::query()->withoutGlobalScope(HouseholdScope::class)
            ->where('for_occasion_id', $occasion->id)
            ->where('household_id', '!=', $occasion->household_id)
            ->with('recipe', 'household')
            ->orderBy('household_id')->orderBy('position')->get();
    }

    /* ================================================================ Côté foyer invité */

    /** Invitations reçues, à venir. @return Collection<int, MealOccasionHousehold> */
    public function invitationsFor(?int $householdId = null, ?Carbon $today = null): Collection
    {
        $householdId ??= CurrentHousehold::id();
        $today ??= Carbon::today();

        return MealOccasionHousehold::query()->where('household_id', $householdId)
            ->whereHas('occasion', fn ($q) => $q->withoutGlobalScope(HouseholdScope::class)->whereDate('date', '>=', $today->toDateString()))
            ->with(['occasion' => fn ($q) => $q->withoutGlobalScope(HouseholdScope::class)->with(['slot' => fn ($s) => $s->withoutGlobalScope(HouseholdScope::class)]), 'occasion.household'])
            ->get()
            ->sortBy(fn (MealOccasionHousehold $row) => $row->occasion->date->toDateString())
            ->values();
    }

    /** L'invitation, si elle s'adresse au foyer actif. */
    public function find(int $id): ?MealOccasionHousehold
    {
        return MealOccasionHousehold::query()->whereKey($id)->where('household_id', CurrentHousehold::id())->first();
    }

    public function respond(MealOccasionHousehold $row, bool $accept, ?int $people = null, ?string $message = null): void
    {
        $this->guard($row);

        $row->update([
            'status' => $accept ? 'accepted' : 'declined',
            'people' => $accept ? max(1, min(50, $people ?? $this->defaultPeople())) : null,
            'message' => mb_substr(trim((string) $message), 0, 255) ?: null,
            'responded_at' => now(),
        ]);

        if (! $accept) {
            PlannedMeal::query()->where('for_occasion_id', $row->meal_occasion_id)->update(['for_occasion_id' => null]);
        }
    }

    /** Ajoute un plat apporté : une recette de notre carnet, dans notre planning, le jour de la réception. */
    public function bring(MealOccasionHousehold $row, int $recipeId, ?Course $course = null, int|float|null $servings = null): PlannedMeal
    {
        $this->guard($row);

        if (! $row->isAccepted()) {
            throw new InvalidArgumentException('Répondez d\'abord que vous venez.');
        }

        $recipe = Recipe::query()->active()->find($recipeId) ?? throw new InvalidArgumentException('Choisissez une recette de votre carnet.');
        $occasion = $row->occasion;
        $slot = $this->matchingSlot($occasion);
        $servings ??= max(1, $this->dinersAtHost($occasion));

        $meal = app(WeekPlanner::class)->addRecipe($occasion->date, $slot->id, $recipe, $servings);
        $meal->update(['course' => $course?->value, 'for_occasion_id' => $occasion->id]);

        return $meal;
    }

    /** Nos plats pour cette réception. @return Collection<int, PlannedMeal> */
    public function myDishes(MealOccasionHousehold $row): Collection
    {
        return PlannedMeal::query()->where('for_occasion_id', $row->meal_occasion_id)->with('recipe')->orderBy('position')->get();
    }

    /** Le menu du foyer qui reçoit (ses plats), lu dans son propre foyer. @return Collection<int, PlannedMeal> */
    public function hostMenu(MealOccasion $occasion): Collection
    {
        return CurrentHousehold::run((int) $occasion->household_id, fn () => $occasion->meals());
    }

    /** Convives prévus par le foyer qui reçoit (portions proposées pour un plat apporté). */
    public function dinersAtHost(MealOccasion $occasion): float
    {
        return CurrentHousehold::run((int) $occasion->household_id, fn () => app(\App\Services\Planning\OccasionService::class)->diners($occasion->fresh()));
    }

    /** Créneau du même nom chez nous (les créneaux sont propres à chaque foyer), sinon le premier actif. */
    private function matchingSlot(MealOccasion $occasion): MealSlot
    {
        $name = MealSlot::query()->withoutGlobalScope(HouseholdScope::class)->whereKey($occasion->meal_slot_id)->value('name');

        return MealSlot::query()->where('name', $name)->first()
            ?? MealSlot::query()->active()->ordered()->first()
            ?? throw new InvalidArgumentException('Aucun créneau dans votre planning.');
    }

    private function defaultPeople(): int
    {
        return max(1, \App\Support\Settings::int('household_size', 2));
    }

    private function guard(MealOccasionHousehold $row): void
    {
        if ((int) $row->household_id !== (int) CurrentHousehold::id()) {
            throw new InvalidArgumentException('Cette invitation s\'adresse à un autre foyer.');
        }
    }

    public function householdName(int $id): string
    {
        return (string) Household::query()->whereKey($id)->value('name');
    }
}
