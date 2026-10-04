<?php

namespace App\Services\Pricing;

use App\Enums\UnitType;
use App\Models\Ingredient;
use App\Models\IngredientPrice;
use App\Models\Store;
use App\Models\Unit;
use App\Services\UnitConverter;
use Illuminate\Support\Carbon;

/**
 * Prix de référence et coûts (15.7, règle R17).
 *
 * Un prix est toujours relevé tel qu'on l'a payé : « 2,49 € les 500 g ». Pour pouvoir s'en
 * servir ailleurs, il est ramené à l'**unité de base** de sa famille : €/g, €/ml ou €/pièce.
 * C'est ce prix-là qui est gardé sur l'ingrédient, et qui sert à chiffrer une recette ou une liste.
 *
 * Deux principes tiennent tout le reste :
 *  - un prix saisi à la main n'est jamais écrasé par un relevé de caisse (`reference_price_locked`) ;
 *  - ce qu'on ne sait pas chiffrer est **compté et annoncé** (« ≥ 6,40 € · 3 prix manquants »),
 *    jamais deviné.
 */
class PriceBook
{
    public function __construct(private readonly UnitConverter $converter) {}

    /* ================================================================ Relevés */

    /**
     * Enregistre un prix et met à jour le prix de référence de l'ingrédient.
     *
     * @param  float  $price  ce qui a été payé, en euros
     * @param  float|null  $quantity  pour quelle quantité (null = l'unité par défaut de l'ingrédient, 1)
     */
    public function record(
        Ingredient $ingredient,
        float $price,
        ?float $quantity = null,
        ?Unit $unit = null,
        ?Store $store = null,
        ?Carbon $on = null,
        string $source = IngredientPrice::MANUAL,
        bool $promo = false,
    ): IngredientPrice {
        $unit ??= $ingredient->defaultUnit;
        $quantity = $quantity !== null && $quantity > 0 ? $quantity : 1.0;
        $on ??= Carbon::today();

        [$unitPrice, $baseUnit] = $this->toUnitPrice($price, $quantity, $unit, $ingredient);

        $record = IngredientPrice::create([
            'ingredient_id' => $ingredient->id,
            'store_id' => $store?->id,
            'price' => round($price, 2),
            'quantity' => $quantity,
            'unit_id' => $unit?->id,
            'unit_price' => $unitPrice,
            'observed_on' => $on,
            'source' => $source,
            'is_promo' => $promo,
            'created_by' => auth()->id(),
        ]);

        // Un relevé plus ancien que le prix retenu (saisi après coup) ne le remplace pas.
        $older = $ingredient->reference_price_on !== null && $on->lt($ingredient->reference_price_on);

        // Lot 30 (R35) : un prix en promotion n'est pas le prix habituel, il ne devient pas la référence.
        if ($unitPrice !== null && $baseUnit !== null && ! $ingredient->reference_price_locked && ! $older && ! $promo) {
            $ingredient->forceFill([
                'reference_price' => $unitPrice,
                'reference_price_unit_id' => $baseUnit->id,
                'reference_price_on' => $on,
            ])->save();
        }

        return $record;
    }

    /**
     * Supprime un relevé (lot 27) : le prix de référence, s'il n'a pas été fixé à la main, reprend
     * le dernier relevé restant — ou disparaît s'il n'en reste aucun. Un prix faux ne survit pas à
     * la suppression de sa source.
     */
    public function forget(IngredientPrice $price): void
    {
        $ingredient = $price->ingredient;
        $price->delete();

        if (! $ingredient || $ingredient->reference_price_locked) {
            return;
        }

        $latest = IngredientPrice::query()->where('ingredient_id', $ingredient->id)->whereNotNull('unit_price')
            ->where('is_promo', false)
            ->with('unit')->orderByDesc('observed_on')->orderByDesc('id')->first();
        $unit = $latest ? $this->baseUnitFor($latest->unit) : null;

        $ingredient->forceFill([
            'reference_price' => $unit ? $latest->unit_price : null,
            'reference_price_unit_id' => $unit?->id,
            'reference_price_on' => $unit ? $latest->observed_on : null,
        ])->save();
    }

    /** Prix fixé à la main : les relevés suivants ne l'écrasent plus. */
    public function setReference(Ingredient $ingredient, ?float $price, ?Unit $unit): void
    {
        if ($price === null || $price <= 0) {
            $ingredient->forceFill([
                'reference_price' => null,
                'reference_price_unit_id' => null,
                'reference_price_locked' => false,
                'reference_price_on' => null,
            ])->save();

            return;
        }

        $unit ??= $ingredient->defaultUnit;
        [$unitPrice, $baseUnit] = $this->toUnitPrice($price, 1.0, $unit, $ingredient);

        $ingredient->forceFill([
            'reference_price' => $unitPrice,
            'reference_price_unit_id' => $baseUnit?->id,
            'reference_price_locked' => true,
            'reference_price_on' => Carbon::today(),
        ])->save();
    }

    /**
     * Ramène « prix pour telle quantité dans telle unité » à un prix par unité de base.
     *
     * @return array{0: float|null, 1: Unit|null}
     */
    public function toUnitPrice(float $price, float $quantity, ?Unit $unit, Ingredient $ingredient): array
    {
        if ($quantity <= 0) {
            return [null, null];
        }

        // Sans unité, on raisonne à la pièce : « 1,20 € la salade ».
        if ($unit === null) {
            return [round($price / $quantity, 6), $this->pieceUnit()];
        }

        if ($unit->type->isConvertible()) {
            $base = $this->converter->toBase($quantity, $unit);

            return $base && $base > 0
                ? [round($price / $base, 6), $this->baseUnit($unit->type)]
                : [null, null];
        }

        // Comptage (pièce, tranche, boîte…) : le prix reste attaché à cette unité-là.
        return [round($price / $quantity, 6), $unit];
    }

    /* ================================================================ Coûts */

    /**
     * Coût d'une quantité d'ingrédient, ou null si on ne sait pas le dire honnêtement.
     */
    public function costOf(Ingredient $ingredient, ?float $quantity, ?Unit $unit): ?float
    {
        if ($ingredient->reference_price === null || $ingredient->reference_price_unit_id === null) {
            return null;
        }

        $reference = $ingredient->referencePriceUnit;

        if (! $reference) {
            return null;
        }

        // Pas de quantité : on ne devine pas (« un peu de sel » ne se chiffre pas).
        if ($quantity === null || $quantity <= 0) {
            return null;
        }

        $unit ??= $ingredient->defaultUnit;

        if ($unit === null) {
            return $reference->type === UnitType::Piece ? $quantity * (float) $ingredient->reference_price : null;
        }

        if (! $this->converter->canConvert($unit, $reference, $ingredient)) {
            return null;
        }

        return $this->converter->convert($quantity, $unit, $reference, $ingredient) * (float) $ingredient->reference_price;
    }

    /**
     * Prix de référence affiché à l'humain : « 4,98 €/kg », « 1,20 €/pièce ».
     */
    public function referenceLabel(Ingredient $ingredient): ?string
    {
        if ($ingredient->reference_price === null) {
            return null;
        }

        return $this->unitPriceLabel((float) $ingredient->reference_price, $ingredient->referencePriceUnit);
    }

    /** Prix par unité de base affiché à l'humain : 0,00498 €/g → « 4,98 € / kg ». */
    public function unitPriceLabel(float $unitPrice, ?Unit $unit): string
    {
        [$price, $label] = $this->displayUnitPrice($unitPrice, $unit);

        return $this->money($price).' / '.$label;
    }

    /**
     * Prix ramené à une unité lisible : le kilo et le litre plutôt que le gramme et le millilitre.
     *
     * @return array{0: float, 1: string}
     */
    public function displayUnitPrice(float $unitPrice, ?Unit $unit): array
    {
        return match (true) {
            $unit?->code === 'g' => [$unitPrice * 1000, 'kg'],
            $unit?->code === 'ml' => [$unitPrice * 1000, 'l'],
            default => [$unitPrice, $unit?->label ?? 'pièce'],
        };
    }

    /**
     * Unité dans laquelle un relevé saisi en `$unit` est ramené (lot 27) : g ou ml pour une masse ou
     * un volume, la pièce sans unité, l'unité elle-même pour le reste (boîte, sachet…). Deux relevés
     * ne se comparent que s'ils partagent cette unité.
     */
    public function baseUnitFor(?Unit $unit): ?Unit
    {
        if ($unit === null) {
            return $this->pieceUnit();
        }

        return $unit->type->isConvertible() ? $this->baseUnit($unit->type) : $unit;
    }

    /** « 4,98 € » — en français, virgule décimale et espace avant l'euro. */
    public function money(?float $amount, int $decimals = 2): string
    {
        return number_format((float) $amount, $decimals, ',', "\u{202F}")."\u{00A0}€";
    }

    /* ================================================================ Utilitaires */

    private function baseUnit(UnitType $type): ?Unit
    {
        $code = $type->baseUnitCode();

        return $code ? $this->unitByCode($code) : null;
    }

    private function pieceUnit(): ?Unit
    {
        return $this->unitByCode('piece');
    }

    /** @var array<string, Unit|null> */
    private array $unitsByCode = [];

    private function unitByCode(string $code): ?Unit
    {
        return $this->unitsByCode[$code] ??= Unit::firstWhere('code', $code);
    }
}
