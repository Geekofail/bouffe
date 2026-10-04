<?php

namespace App\Services\Planning;

use App\Enums\RestrictionType;
use App\Models\HouseholdPerson;
use App\Models\Ingredient;
use App\Models\MealOccasion;
use App\Models\MealSlot;
use App\Models\PersonRestriction;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Le foyer (module 18) : qui vit ici, ce que chacun ne mange pas. Depuis le lot 39, les goûts
 * sont ceux des **personnes** du foyer, compte ou pas : un enfant a ses allergies.
 *
 * Les contraintes des membres du foyer ont exactement la même forme que celles des invités
 * (lot 6) : `GuestCompatibility` traite les deux sans distinction, et les alertes de
 * planification sont donc identiques.
 */
class HouseholdService
{
    /** @return Collection<int, User> membres du foyer (comptes), contraintes de leur personne chargées */
    public function members(): Collection
    {
        return User::query()->inHousehold()
            ->with('restrictions.ingredient', 'restrictions.tag')
            ->orderBy('name')
            ->get();
    }

    /**
     * Les personnes à table d'habitude (lot 39), goûts chargés.
     *
     * @return Collection<int, HouseholdPerson>
     */
    public function people(): Collection
    {
        return HouseholdPerson::query()->atTable()->ordered()
            ->with('restrictions.ingredient', 'restrictions.tag')
            ->get();
    }

    /**
     * Personnes présentes à une case : celles à table d'habitude, moins les absents saisis pour la
     * case et ceux qui mangent à la cantine ce midi-là.
     *
     * @return Collection<int, HouseholdPerson>
     */
    public function presentAt(?MealOccasion $occasion, Carbon|string|null $date = null, MealSlot|int|null $slot = null): Collection
    {
        [$absentUsers, $absentPersons] = app(OccasionService::class)->absences($occasion, $date, $slot);

        return $this->people()
            ->reject(fn (HouseholdPerson $person) => ($person->user_id !== null && in_array($person->user_id, $absentUsers, true)) || in_array($person->id, $absentPersons, true))
            ->values();
    }

    /**
     * Tout le monde à table : personnes du foyer présentes **et** invités. C'est cette liste qui sert
     * aux alertes (`GuestCompatibility::conflicts`).
     *
     * @return Collection<int, HouseholdPerson|\App\Models\Guest|\App\Services\Linked\LinkedEater>
     */
    public function eaters(?MealOccasion $occasion, Carbon|string|null $date = null, MealSlot|int|null $slot = null): Collection
    {
        $withRestrictions = $this->presentAt($occasion, $date, $slot)->filter(fn (HouseholdPerson $person) => $person->restrictions->isNotEmpty());

        // Lot 26 (26.6) : membres des foyers reliés qui viennent, avec les contraintes qu'ils partagent.
        $linked = app(\App\Services\Linked\LinkedEaters::class)->forOccasion($occasion)->filter(fn ($eater) => $eater->restrictions->isNotEmpty());

        return $withRestrictions->concat($occasion?->guests ?? collect())->concat($linked)->values();
    }

    /** Raccourci quand on n'a que la case. */
    public function eatersAt(Carbon|string $date, MealSlot|int $slot): Collection
    {
        return $this->eaters(app(OccasionService::class)->find($date, $slot), $date, $slot);
    }

    /** Y a-t-il au moins une contrainte dans le foyer ? (évite un travail inutile) */
    public function hasRestrictions(): bool
    {
        return PersonRestriction::query()->exists();
    }

    /* ================================================================ Écriture */

    /** Ajoute un goût ou une allergie à une personne (ou au compte, par sa personne). */
    public function addRestriction(HouseholdPerson|User $person, RestrictionType $type, ?int $subjectId, ?string $note = null): PersonRestriction
    {
        if ($person instanceof User) {
            $person = app(\App\Services\People\HouseholdPeople::class)->forUser($person);
        }

        if ($type->usesIngredient()) {
            $ingredient = Ingredient::find($subjectId) ?? throw new InvalidArgumentException('Choisissez un ingrédient.');
            $exists = $person->restrictions()->where('type', $type->value)->where('ingredient_id', $ingredient->id)->exists();

            if ($exists) {
                throw new InvalidArgumentException("« {$ingredient->name} » est déjà noté pour {$person->name}.");
            }

            return $person->restrictions()->create(['type' => $type->value, 'ingredient_id' => $ingredient->id, 'note' => $note]);
        }

        $tag = Tag::find($subjectId) ?? throw new InvalidArgumentException('Choisissez une catégorie.');
        $exists = $person->restrictions()->where('type', $type->value)->where('tag_id', $tag->id)->exists();

        if ($exists) {
            throw new InvalidArgumentException("« {$tag->name} » est déjà noté pour {$person->name}.");
        }

        return $person->restrictions()->create(['type' => $type->value, 'tag_id' => $tag->id, 'note' => $note]);
    }

    public function removeRestriction(PersonRestriction $restriction): void
    {
        $restriction->delete();
    }
}
