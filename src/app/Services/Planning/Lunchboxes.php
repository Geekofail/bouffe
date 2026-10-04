<?php

namespace App\Services\Planning;

use App\Models\PlannedMeal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Gamelles du midi (lot 32, 32.3).
 *
 * Une gamelle est un reste placé pour une personne du foyer (`planned_meals.is_lunchbox` +
 * `for_person_id` ; compte ou pas depuis le lot 39) : « gamelle de Pierre, mardi ». Elle se prépare **la veille au soir** : c'est ce
 * que liste l'accueil, et ce que reprennent la liste imprimable et les étiquettes.
 */
class Lunchboxes
{
    /**
     * Gamelles mangées entre deux dates (incluses), dans l'ordre des jours et des créneaux.
     *
     * @return Collection<int, PlannedMeal>
     */
    public function between(Carbon|string $from, Carbon|string $to): Collection
    {
        return PlannedMeal::query()
            ->where('is_lunchbox', true)
            ->between(Carbon::parse($from), Carbon::parse($to))
            ->whereNull('skipped_at')
            ->with(['forPerson', 'slot', 'leftoverOf.recipe', 'leftoverOf.slot'])
            ->get()
            ->sortBy(fn (PlannedMeal $meal) => [$meal->date->toDateString(), $meal->slot?->sort_order ?? 0, $meal->forPerson?->name ?? ''])
            ->values();
    }

    /**
     * Ce qu'il faut préparer ce soir-là : les gamelles du lendemain.
     *
     * @return Collection<int, PlannedMeal>
     */
    public function toPrepareOn(Carbon|string $evening): Collection
    {
        $day = Carbon::parse($evening)->addDay();

        return $this->between($day, $day);
    }

    /**
     * Gamelles regroupées par soir de préparation : [date du soir => gamelles].
     *
     * @return Collection<string, Collection<int, PlannedMeal>>
     */
    public function byEvening(Carbon|string $from, Carbon|string $to): Collection
    {
        return $this->between($from, $to)
            ->groupBy(fn (PlannedMeal $meal) => $meal->date->copy()->subDay()->toDateString());
    }

    /** Personnes du foyer à qui l'on peut préparer une gamelle (lot 39 : un enfant aussi). @return Collection<int, \App\Models\HouseholdPerson> */
    public function people(): Collection
    {
        return app(\App\Services\People\HouseholdPeople::class)->ensure();
    }

    /** « lundi 21/09, dîner » : d'où viennent les restes. */
    public function origin(PlannedMeal $meal): string
    {
        $source = $meal->leftoverOf;

        return $source
            ? $source->date->locale('fr')->isoFormat('dddd D/MM').($source->slot ? ', '.mb_strtolower($source->slot->name) : '')
            : '';
    }
}
