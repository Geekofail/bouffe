<?php

namespace App\Services\Seasons;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Saisons des fruits et légumes (17.1, règle R18).
 *
 * Un ingrédient sans mois renseignés n'est **pas** hors saison : il n'est simplement pas
 * concerné (farine, pâtes, viande). C'est ce qui évite de transformer un calendrier indicatif
 * en jugement permanent sur les recettes.
 *
 * Une recette est :
 *  - **de saison** si tous ses fruits et légumes non facultatifs le sont ce mois-là ;
 *  - **hors saison** si son ingrédient principal (le plus lourd parmi les fruits et légumes)
 *    ne l'est pas ;
 *  - **neutre** sinon — aucun fruit ni légume daté, ou seulement des ingrédients secondaires
 *    hors saison, ce qui ne mérite pas d'étiquette.
 */
class SeasonCalendar
{
    public const IN_SEASON = 'season';

    public const OFF_SEASON = 'off';

    public const NEUTRAL = 'neutral';

    /** Bonus de score donné au remplissage automatique pour une recette de saison (R15). */
    public const FILLER_BONUS = 10;

    public const FILLER_PENALTY = -8;

    public static function monthName(int $month): string
    {
        return ucfirst(Carbon::create(2026, max(1, min(12, $month)), 1)->locale('fr')->isoFormat('MMMM'));
    }

    /** @return array<int, string> 1 => Janvier … */
    public static function months(): array
    {
        return collect(range(1, 12))->mapWithKeys(fn (int $m) => [$m => self::monthName($m)])->all();
    }

    public function hasSeason(Ingredient $ingredient): bool
    {
        return is_array($ingredient->season_months) && $ingredient->season_months !== [];
    }

    public function isInSeason(Ingredient $ingredient, ?int $month = null): bool
    {
        $month ??= (int) Carbon::today()->month;

        // Pas de mois renseignés : disponible toute l'année, ou non concerné.
        return ! $this->hasSeason($ingredient) || in_array($month, $ingredient->season_months, true);
    }

    /**
     * État d'une recette pour un mois donné.
     *
     * @return array{status: string, offenders: list<string>, main: string|null, month: int}
     */
    public function recipeStatus(Recipe $recipe, ?Carbon $date = null): array
    {
        $month = (int) ($date ?? Carbon::today())->month;
        $recipe->loadMissing(['ingredients.ingredient', 'ingredients.unit']);

        $seasonal = $recipe->ingredients
            ->filter(fn (RecipeIngredient $line) => ! $line->is_optional && $line->ingredient && $this->hasSeason($line->ingredient));

        if ($seasonal->isEmpty()) {
            return ['status' => self::NEUTRAL, 'offenders' => [], 'main' => null, 'month' => $month];
        }

        $offenders = $seasonal
            ->reject(fn (RecipeIngredient $line) => $this->isInSeason($line->ingredient, $month))
            ->values();

        if ($offenders->isEmpty()) {
            return ['status' => self::IN_SEASON, 'offenders' => [], 'main' => null, 'month' => $month];
        }

        // L'ingrédient principal : le plus lourd parmi les fruits et légumes de la recette.
        $main = $seasonal->sortByDesc(fn (RecipeIngredient $line) => $this->weight($line))->first();
        $mainIsOff = $main && $offenders->contains(fn (RecipeIngredient $line) => $line->id === $main->id);

        return [
            'status' => $mainIsOff ? self::OFF_SEASON : self::NEUTRAL,
            'offenders' => $offenders->map(fn (RecipeIngredient $line) => (string) $line->ingredient->name)->all(),
            'main' => $main?->ingredient?->name,
            'month' => $month,
        ];
    }

    /**
     * États de plusieurs recettes d'un coup (index des recettes, planning).
     *
     * @param  Collection<int, Recipe>  $recipes
     * @return array<int, array{status: string, offenders: list<string>, main: string|null, month: int}>
     */
    public function forRecipes(Collection $recipes, ?Carbon $date = null): array
    {
        $recipes->loadMissing(['ingredients.ingredient', 'ingredients.unit']);

        return $recipes->mapWithKeys(fn (Recipe $recipe) => [$recipe->id => $this->recipeStatus($recipe, $date)])->all();
    }

    /** Identifiants des recettes de saison ce mois-ci (filtre de l'index). */
    public function recipeIdsInSeason(Collection $recipes, ?Carbon $date = null): array
    {
        return collect($this->forRecipes($recipes, $date))
            ->filter(fn (array $status) => $status['status'] === self::IN_SEASON)
            ->keys()->map(fn ($id) => (int) $id)->all();
    }

    /** Bonus (ou malus) appliqué par le remplissage automatique de la semaine. */
    public function fillerBonus(Recipe $recipe, ?Carbon $date = null): int
    {
        return match ($this->recipeStatus($recipe, $date)['status']) {
            self::IN_SEASON => self::FILLER_BONUS,
            self::OFF_SEASON => self::FILLER_PENALTY,
            default => 0,
        };
    }

    /** « Fraises et asperges ne sont pas de saison en octobre. » */
    public function explain(array $status): ?string
    {
        if ($status['offenders'] === []) {
            return null;
        }

        $names = collect($status['offenders'])->map(fn (string $name) => mb_strtolower($name));
        $list = $names->count() === 1
            ? $names->first()
            : $names->slice(0, -1)->join(', ').' et '.$names->last();

        return ucfirst($list).' : pas la saison en '.mb_strtolower(self::monthName($status['month'])).'.';
    }

    /** Ingrédients de saison ce mois-ci, pour la page « Que cuisiner ». */
    public function ingredientsInSeason(?int $month = null): Collection
    {
        $month ??= (int) Carbon::today()->month;

        return Ingredient::query()
            ->whereNotNull('season_months')
            ->orderBy('name')
            ->get()
            ->filter(fn (Ingredient $ingredient) => $this->hasSeason($ingredient) && $this->isInSeason($ingredient, $month))
            ->values();
    }

    /** Poids approximatif d'une ligne, pour désigner l'ingrédient principal. */
    private function weight(RecipeIngredient $line): float
    {
        $quantity = $line->quantity === null ? 0.0 : (float) $line->quantity;

        if ($quantity <= 0) {
            return 0.0;
        }

        $unit = $line->unit;

        if ($unit === null) {
            return $quantity * (float) ($line->ingredient->piece_weight_g ?? 100);
        }

        return match (true) {
            $unit->type->isConvertible() && $unit->factor_to_base !== null => $quantity * (float) $unit->factor_to_base,
            default => $quantity * (float) ($line->ingredient->piece_weight_g ?? 100),
        };
    }
}
