<?php

namespace App\Services\Linked;

use App\Models\HouseholdPerson;
use App\Models\MealOccasion;
use App\Models\MealOccasionHousehold;
use App\Models\PersonRestriction;
use App\Models\Scopes\HouseholdScope;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Contraintes tenues par la personne (26.6) : quand un foyer relié vient à une réception, les
 * allergies et régimes de ses membres viennent de **leur** profil (noté dans leur foyer, R29) —
 * seulement pour ceux qui ont accepté de les partager (Mon compte).
 *
 * Chaque personne devient un « convive » de la même forme qu'un invité : `GuestCompatibility`
 * donne les mêmes alertes. Les catégories (régimes) sont propres à chaque foyer : elles sont
 * rapprochées par leur nom.
 */
class LinkedEaters
{
    /** @return Collection<int, LinkedEater> */
    public function forOccasion(?MealOccasion $occasion): Collection
    {
        if (! $occasion || ! $occasion->exists) {
            return collect();
        }

        $rows = MealOccasionHousehold::query()->where('meal_occasion_id', $occasion->id)->where('status', 'accepted')->with('household')->get();

        return $rows->flatMap(fn (MealOccasionHousehold $row) => $this->members($row))->values();
    }

    /**
     * Membres d'un foyer invité : ceux qui partagent leurs contraintes, avec elles ; les autres
     * sont signalés « non partagées » (on ne sait pas, on ne devine pas).
     *
     * @return Collection<int, LinkedEater>
     */
    public function members(MealOccasionHousehold $row): Collection
    {
        return $row->household ? $this->membersOf($row->household) : collect();
    }

    /**
     * Personnes d'un foyer relié, avec leurs contraintes partagées : réceptions (26.6) et séjours
     * (lot 34). Depuis le lot 39 : les comptes (par leur personne) et les personnes sans compte à
     * table chez eux — un enfant, dont un adulte de son foyer a choisi de partager les goûts (R40).
     *
     * @return Collection<int, LinkedEater>
     */
    public function membersOf(\App\Models\Household $household): Collection
    {
        $users = User::query()->whereIn('id', \Illuminate\Support\Facades\DB::table('household_user')->where('household_id', $household->id)->select('user_id'))->orderBy('name')->get();
        // Personnes et contraintes notées dans LEUR foyer (les filtres globaux visent le foyer actif : levés, puis refaits ici).
        $people = HouseholdPerson::query()->withoutGlobalScopes()->where('household_id', $household->id)->orderBy('position')->orderBy('id')->get();
        $people->each(fn (HouseholdPerson $person) => $person->setRelation('user', $person->user_id ? $users->firstWhere('id', $person->user_id) : null));
        $sharing = $people->filter(fn (HouseholdPerson $person) => $person->sharesTastes())->pluck('id');
        $restrictions = PersonRestriction::query()->withoutGlobalScopes()
            ->where('household_id', $household->id)
            ->whereIn('person_id', $sharing->all() ?: [0])
            ->with(['ingredient', 'tag' => fn ($q) => $q->withoutGlobalScope(HouseholdScope::class)])
            ->get()->groupBy('person_id');
        $hostTags = Tag::query()->pluck('id', 'name');

        $eaters = collect();

        foreach ($users as $user) {
            $person = $people->firstWhere('user_id', $user->id);
            $eaters->push($this->eater($user->name, $household, (bool) $user->share_restrictions, collect($person ? $restrictions->get($person->id, []) : []), $hostTags, $user->id));
        }

        // Sans compte : seulement les personnes dont un adulte a choisi de partager les goûts.
        foreach ($people->whereNull('user_id')->where('share_tastes', true) as $person) {
            $eaters->push($this->eater($person->name, $household, $person->share_tastes, collect($restrictions->get($person->id, [])), $hostTags, null));
        }

        return $eaters->values();
    }

    /** @param  Collection<int, PersonRestriction>  $restrictions */
    private function eater(string $name, \App\Models\Household $household, bool $shared, Collection $restrictions, Collection $hostTags, ?int $userId): LinkedEater
    {
        $mapped = $restrictions->map(function (PersonRestriction $r) use ($hostTags) {
            if ($r->tag_id) {
                $hostTag = $r->tag ? $hostTags->get($r->tag->name) : null;

                if (! $hostTag) {
                    return null;   // régime sans équivalent chez nous : pas d'alerte automatique possible
                }

                $copy = $r->replicate();
                $copy->tag_id = $hostTag;
                $copy->setRelation('tag', $r->tag);

                return $copy;
            }

            return $r;
        })->filter()->values();

        return new LinkedEater(
            name: $name.' ('.$household->name.')',
            shared: $shared,
            restrictions: $mapped,
            all: $restrictions,
            userId: $userId,
        );
    }
}
