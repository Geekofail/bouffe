<?php

namespace App\Services\Shopping;

use App\Enums\ItemOrigin;
use App\Models\Ingredient;
use App\Models\ShoppingListItem;
use App\Models\Unit;
use App\Services\IngredientLineFormatter;
use App\Services\QuantityFormatter;
use Illuminate\Support\Collection;

/**
 * Texte affiché pour un article : « 3 oignons », « 1,2 kg de pommes de terre + 1 boîte ».
 * Les quantités sont arrondies au supérieur (contexte liste de courses).
 */
class ShoppingItemPresenter
{
    /** @var Collection<int, Unit>|null */
    private ?Collection $units = null;

    public function __construct(
        private readonly IngredientLineFormatter $lines,
        private readonly QuantityFormatter $quantities,
    ) {}

    public function text(ShoppingListItem $item): string
    {
        if ($item->origin === ItemOrigin::Manual || $item->origin === ItemOrigin::Recurring) {
            if ($item->quantity === null) {
                return $item->label;
            }
        }

        $ingredient = $item->ingredient ?? new Ingredient(['name' => $item->label]);
        $quantity = $item->quantity === null ? null : (float) $item->quantity;
        $text = $this->lines->format($quantity, $this->unit($item->unit_id), $ingredient, QuantityFormatter::SHOPPING)['text'];

        foreach ((array) $item->extra_quantities as $extra) {
            $text .= ' + '.$this->quantities->format((float) $extra['q'], $this->unit($extra['unit_id'] ?? null), QuantityFormatter::SHOPPING);
        }

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /** @var array<int, true>|null ingrédients en stock (lot 40) */
    private ?array $inStock = null;

    private ?Collection $eaters = null;

    /**
     * Lot 40 (40.1) : « ou : crème de soja, en stock » — un remplacement déjà à la maison, sans ce
     * qui heurte l'allergie de quelqu'un du foyer (R43). Rien pour un article coché ou déjà couvert.
     */
    public function alternative(ShoppingListItem $item): ?string
    {
        if (! $item->ingredient_id || $item->is_checked || $item->stock_status === 'covered') {
            return null;
        }

        $this->inStock ??= \App\Models\StockItem::query()->active()->whereNotNull('ingredient_id')->distinct()->pluck('ingredient_id')->flip()->map(fn () => true)->all();

        if ($this->inStock === []) {
            return null;
        }

        $this->eaters ??= app(\App\Services\Planning\HouseholdService::class)->people();
        $names = app(\App\Services\Recipes\Substitutions::class)->for((int) $item->ingredient_id, null, $this->eaters)
            ->filter(fn ($substitution) => isset($this->inStock[$substitution->substitute_id]))
            ->map(fn ($substitution) => mb_strtolower($substitution->substitute->name))
            ->take(2)->values();

        return $names->isEmpty() ? null : 'ou : '.$names->join(', ', ' ou ').', en stock';
    }

    /** « 400 g » ou « 2 c. à soupe » pour une ligne de provenance. */
    public function sourceQuantity(?float $quantity, ?int $unitId): string
    {
        if ($quantity === null) {
            return 'à convenance';
        }

        return $this->quantities->format($quantity, $this->unit($unitId), QuantityFormatter::RECIPE);
    }

    private function unit(?int $id): ?Unit
    {
        if ($id === null) {
            return null;
        }

        $this->units ??= Unit::all()->keyBy('id');

        return $this->units->get($id);
    }
}
