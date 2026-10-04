<?php

namespace App\Services\Planning;

use App\Enums\LocationType;
use App\Enums\MealType;
use App\Models\PlannedMeal;
use App\Models\StockItem;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Services\Shopping\ShoppingListGenerator;
use App\Services\Stock\MealStockService;
use App\Services\Stock\StockManager;
use App\Support\StockDefaults;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Batch cooking (14.8) : cuisiner plusieurs repas de la semaine en une seule session.
 *
 *  1. on choisit les repas à avancer ;
 *  2. les ingrédients sont regroupés (comme pour la liste de courses) et les recettes rangées de
 *     la plus longue à la plus courte, pour lancer d'abord ce qui mijote ;
 *  3. chaque plat terminé part au réfrigérateur ou au congélateur, **lié à son repas** : le jour J,
 *     « mangé » retire le plat du stock (et plus ses ingrédients), et un plat congelé déclenche
 *     le rappel « Décongeler » la veille (R21).
 */
class BatchCooking
{
    /** Au-delà, un plat cuisiné se congèle plutôt que d'attendre au réfrigérateur. */
    public const FRIDGE_DAYS = StockDefaults::PREPARED_FRIDGE_DAYS;

    public function __construct(
        private readonly ShoppingListGenerator $generator,
        private readonly MealStockService $mealStock,
        private readonly StockManager $stock,
    ) {}

    /**
     * Repas qu'on peut cuisiner à l'avance : recettes à venir, ni mangées ni déjà préparées.
     *
     * @return Collection<int, PlannedMeal>
     */
    public function candidates(?Carbon $from = null, int $days = 10): Collection
    {
        $from ??= Carbon::today();

        return PlannedMeal::query()
            ->where('type', MealType::Recipe->value)
            ->whereBetween('date', [$from->toDateString(), $from->copy()->addDays($days)->toDateString()])
            ->whereNull('cooked_at')
            ->whereNull('prepared_at')
            ->whereHas('recipe')
            ->with('recipe', 'slot')
            ->orderBy('date')->orderBy('meal_slot_id')->orderBy('position')
            ->get();
    }

    /**
     * La session : ingrédients regroupés et recettes dans l'ordre où les lancer.
     *
     * @param  Collection<int, PlannedMeal>  $meals
     * @return array{ingredients: Collection, recipes: Collection<int, array{meal: PlannedMeal, minutes: int, storage: string, reason: string}>, minutes: int}
     */
    public function session(Collection $meals, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $meals = new \Illuminate\Database\Eloquent\Collection($meals->filter(fn (PlannedMeal $m) => $m->isRecipe() && $m->recipe)->values()->all());
        $meals->loadMissing(['recipe.ingredients.ingredient.defaultUnit', 'recipe.ingredients.ingredient.aisle', 'recipe.ingredients.unit', 'recipe.steps', 'slot']);

        $recipes = $meals
            ->map(fn (PlannedMeal $meal) => [
                'meal' => $meal,
                'minutes' => (int) $meal->recipe->prep_minutes + (int) $meal->recipe->cook_minutes + (int) $meal->recipe->rest_minutes,
                ...$this->storageFor($meal, $today),
            ])
            // Le plus long d'abord : ce qui mijote cuit pendant qu'on prépare le reste.
            ->sortBy([['minutes', 'desc'], [fn ($row) => $row['meal']->date->timestamp, 'asc']])
            ->values();

        // Durée estimée : les préparations s'enchaînent, les cuissons se chevauchent.
        $minutes = (int) $meals->sum(fn (PlannedMeal $m) => (int) $m->recipe->prep_minutes)
            + (int) ($meals->max(fn (PlannedMeal $m) => (int) $m->recipe->cook_minutes + (int) $m->recipe->rest_minutes) ?? 0);

        return [
            'ingredients' => $this->generator->generate($meals),
            'recipes' => $recipes,
            'minutes' => $minutes,
        ];
    }

    /**
     * Ingrédients regroupés, en texte (« 500 g de farine »), rangés par rayon.
     *
     * @param  Collection<int, \App\Services\Shopping\ShoppingLine>  $lines
     * @return Collection<string, Collection<int, array{text: string, optional: bool}>>
     */
    public function ingredientTexts(Collection $lines): Collection
    {
        $presenter = app(\App\Services\Shopping\ShoppingItemPresenter::class);

        return $lines
            ->map(function (\App\Services\Shopping\ShoppingLine $line) use ($presenter) {
                $item = new \App\Models\ShoppingListItem([
                    'label' => $line->ingredient->name, 'quantity' => $line->quantity(), 'unit_id' => $line->unit()?->id,
                    'extra_quantities' => $line->extraQuantities(), 'origin' => 'generated',
                ]);
                $item->setRelation('ingredient', $line->ingredient);

                return [
                    'aisle' => $line->ingredient->aisle?->name ?? 'Autres',
                    'position' => $line->ingredient->aisle?->sort_order ?? 999,
                    'text' => $presenter->text($item),
                    'optional' => $line->isOptional,
                ];
            })
            ->sortBy('position')
            ->groupBy('aisle');
    }

    /**
     * Où ranger le plat : réfrigérateur s'il est mangé dans les 3 jours, congélateur sinon.
     *
     * @return array{storage: string, reason: string}
     */
    public function storageFor(PlannedMeal $meal, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $days = (int) $today->copy()->startOfDay()->diffInDays($meal->date->copy()->startOfDay(), false);
        $hasFreezer = StorageLocation::firstOfType(LocationType::Freezer) !== null;

        if ($days > self::FRIDGE_DAYS && $hasFreezer) {
            return ['storage' => 'freezer', 'reason' => 'mangé dans '.$days.' jours : au congélateur'];
        }

        return ['storage' => 'fridge', 'reason' => $days <= 0 ? 'mangé aujourd\'hui' : 'mangé dans '.$days.' jour'.($days > 1 ? 's' : '')];
    }

    /**
     * Un plat est prêt : ingrédients retirés du stock (au choix), plat rangé et lié au repas.
     */
    public function prepare(PlannedMeal $meal, string $storage = 'fridge', bool $deduct = true, int|float|null $portions = null): StockItem
    {
        if (! $meal->isRecipe() || ! $meal->recipe) {
            throw new InvalidArgumentException('Seul un repas « recette » peut être cuisiné à l\'avance.');
        }

        if ($meal->cooked_at || $meal->isPrepared()) {
            throw new InvalidArgumentException('Ce repas est déjà '.($meal->cooked_at ? 'mangé' : 'préparé').'.');
        }

        $type = $storage === 'freezer' ? LocationType::Freezer : LocationType::Fresh;
        $location = StorageLocation::firstOfType($type) ?? StorageLocation::firstOfType(LocationType::Fresh)
            ?? throw new InvalidArgumentException('Aucun emplacement de type réfrigérateur ou congélateur.');
        $frozen = $location->type === LocationType::Freezer;
        $portions = \App\Services\Planning\Appetites::clamp($portions ?? $meal->servings);

        return DB::transaction(function () use ($meal, $deduct, $location, $frozen, $portions) {
            if ($deduct) {
                // Retrait comme au moment d'un repas mangé (R9), mais sans lien avec le repas :
                // décocher « mangé » plus tard ne doit pas remettre ces ingrédients.
                $this->mealStock->apply($meal, $this->mealStock->plan($meal), linkToMeal: false);
            }

            $item = $this->stock->add([
                'label' => $meal->recipe->title.' ('.$meal->date->locale('fr')->isoFormat('ddd D MMM').')',
                'planned_meal_id' => $meal->id,
                'link_movement' => false,
                'quantity' => $portions,
                'unit_id' => Unit::firstWhere('code', 'portion')?->id,
                'storage_location_id' => $location->id,
                'expires_on' => $frozen
                    ? Carbon::today()->addMonths(StockDefaults::PREPARED_FREEZER_MONTHS)->toDateString()
                    : Carbon::today()->addDays(StockDefaults::PREPARED_FRIDGE_DAYS)->toDateString(),
                'expiry_type' => 'dlc',
                'note' => 'Cuisiné à l\'avance',
            ]);

            if ($frozen) {
                $item->forceFill(['frozen_on' => Carbon::today()->toDateString()])->save();
            }

            $meal->update(['prepared_at' => now()]);

            return $item->fresh(['location']);
        });
    }

    /** « Ce n'est finalement pas préparé » : le plat sort du stock, le repas redevient à cuisiner. */
    public function unprepare(PlannedMeal $meal): void
    {
        DB::transaction(function () use ($meal) {
            StockItem::query()->active()->where('planned_meal_id', $meal->id)->whereNull('ingredient_id')
                ->get()->each(fn (StockItem $item) => $this->stock->finish($item));

            $meal->update(['prepared_at' => null]);
        });
    }
}
