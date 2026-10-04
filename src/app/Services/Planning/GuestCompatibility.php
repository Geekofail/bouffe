<?php

namespace App\Services\Planning;

use App\Enums\MealType;
use App\Enums\RestrictionType;
use App\Models\Guest;
use App\Models\MealOccasion;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Compatibilité d'une recette avec des invités (8.5) et historique « déjà servi » (8.6).
 *
 * La détection porte uniquement sur les ingrédients saisis dans la recette : un allergène caché
 * dans un produit transformé n'est pas détecté.
 */
class GuestCompatibility
{
    public const DANGER = 'danger';

    public const WARNING = 'warning';

    /**
     * @param  iterable<Guest>  $guests  restrictions (avec ingrédient / catégorie) chargées de préférence
     * @return list<array{level: string, guest: string, type: RestrictionType, subject: string, optional: bool, message: string, ingredient_id: int|null}>
     */
    public function conflicts(Recipe $recipe, iterable $guests): array
    {
        $recipe->loadMissing('ingredients', 'tags');
        // Une allergie compte aussi dans la pâte brisée ou la sauce utilisées par la recette (13.8).
        $lines = app(\App\Services\Recipes\SubRecipes::class)->lines($recipe)->groupBy('ingredient_id');
        $tagIds = $recipe->tags->pluck('id')->all();
        $conflicts = [];

        foreach ($guests as $guest) {
            $guest->loadMissing('restrictions.ingredient', 'restrictions.tag');

            foreach ($guest->restrictions as $restriction) {
                if ($restriction->type->usesIngredient()) {
                    $recipeLines = $lines->get($restriction->ingredient_id);

                    if (! $recipeLines) {
                        continue;
                    }

                    $optional = $recipeLines->every(fn ($line) => $line->is_optional);
                    $subject = (string) $restriction->ingredient?->name;
                    $contains = 'Contient'.($optional ? ' (facultatif)' : '')." : {$subject}";

                    $conflicts[] = [
                        'level' => $restriction->type === RestrictionType::Allergy ? self::DANGER : self::WARNING,
                        'guest' => $guest->name,
                        'type' => $restriction->type,
                        'subject' => $subject,
                        'optional' => $optional,
                        'ingredient_id' => $restriction->ingredient_id,
                        'message' => $restriction->type === RestrictionType::Allergy
                            ? "{$contains} — allergie de {$guest->name}"
                            : "{$contains} — {$guest->name} n'aime pas",
                    ];
                } elseif ($restriction->tag_id && ! in_array($restriction->tag_id, $tagIds, true)) {
                    $subject = (string) $restriction->tag?->name;

                    $conflicts[] = [
                        'level' => self::WARNING,
                        'guest' => $guest->name,
                        'type' => $restriction->type,
                        'subject' => $subject,
                        'optional' => false,
                        'ingredient_id' => null,
                        'message' => "Pas « {$subject} » — régime de {$guest->name}",
                    ];
                }
            }
        }

        // Lot 40 (40.4, R44) : un produit du stock que la recette utiliserait, et qui contient l'allergène de quelqu'un.
        array_push($conflicts, ...app(\App\Services\Stock\ProductAllergens::class)->recipeConflicts($recipe, $guests));

        usort($conflicts, fn (array $a, array $b) => [$a['level'] !== self::DANGER, $a['guest']] <=> [$b['level'] !== self::DANGER, $b['guest']]);

        return $conflicts;
    }

    /** @param  list<array{level: string}>  $conflicts */
    public function worstLevel(array $conflicts): ?string
    {
        if ($conflicts === []) {
            return null;
        }

        return collect($conflicts)->contains('level', self::DANGER) ? self::DANGER : self::WARNING;
    }

    /**
     * Dernière fois que chaque recette a été servie à chacun de ces invités (repas avant $before).
     *
     * @param  list<int>  $recipeIds
     * @param  list<int>  $guestIds
     * @return array<int, list<array{guest: string, date: Carbon}>> par recette, du plus récent au plus ancien
     */
    public function servedBefore(array $recipeIds, array $guestIds, Carbon $before): array
    {
        if ($recipeIds === [] || $guestIds === []) {
            return [];
        }

        $rows = DB::table('planned_meals as pm')
            ->join('meal_occasions as mo', function ($join) {
                $join->on('mo.date', '=', 'pm.date')->on('mo.meal_slot_id', '=', 'pm.meal_slot_id');
            })
            ->join('meal_occasion_guest as mog', 'mog.meal_occasion_id', '=', 'mo.id')
            ->join('guests as g', 'g.id', '=', 'mog.guest_id')
            ->where('pm.household_id', \App\Support\CurrentHousehold::id() ?? 0)   // R29
            ->where('mo.household_id', \App\Support\CurrentHousehold::id() ?? 0)
            ->where('pm.type', MealType::Recipe->value)
            ->whereIn('pm.recipe_id', $recipeIds)
            ->whereIn('mog.guest_id', $guestIds)
            ->where('pm.date', '<', $before->toDateString())
            ->groupBy('pm.recipe_id', 'g.id', 'g.name')
            ->select('pm.recipe_id', 'g.name', DB::raw('MAX(pm.date) as last_date'))
            ->get();

        return $rows->groupBy('recipe_id')
            ->map(fn (Collection $group) => $group
                ->map(fn ($row) => ['guest' => $row->name, 'date' => Carbon::parse($row->last_date)])
                ->sortByDesc(fn (array $r) => $r['date'])
                ->values()->all())
            ->all();
    }

    /**
     * Repas partagés avec un invité, du plus récent au plus ancien.
     *
     * @return Collection<int, array{occasion: MealOccasion, meals: Collection<int, PlannedMeal>}>
     */
    public function history(Guest $guest): Collection
    {
        $occasions = $guest->occasions()->with('slot')->orderByDesc('date')->get();

        if ($occasions->isEmpty()) {
            return collect();
        }

        $meals = PlannedMeal::query()
            ->whereIn('date', $occasions->map(fn (MealOccasion $o) => $o->date->toDateString())->unique()->all())
            ->with(['recipe', 'leftoverOf.recipe'])
            ->orderBy('position')
            ->get()
            ->groupBy(fn (PlannedMeal $m) => $m->date->toDateString().'|'.$m->meal_slot_id);

        return $occasions->map(fn (MealOccasion $occasion) => [
            'occasion' => $occasion,
            'meals' => $meals->get($occasion->cellKey(), collect()),
        ]);
    }
}
