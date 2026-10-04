<?php

namespace App\Services\Stock;

use App\Enums\MovementType;
use App\Enums\StockMode;
use App\Models\Ingredient;
use App\Models\StockUsageRule;
use App\Models\Unit;
use App\Services\QuantityFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Consommations hors repas (lot 21 — 22.4) : le goûter, le café, le dépannage.
 *
 *  - « J'ai utilisé 2 œufs et 20 cl de lait » depuis le bouton + ;
 *  - consommations régulières : « 1 l de lait tous les 3 jours », retirées par la tâche planifiée.
 *
 * Le retrait se fait toujours sur l'article qui périme le premier.
 */
class StockUsage
{
    /** Après une longue absence de la tâche, on ne retire pas plus d'une semaine de consommation. */
    public const MAX_PERIODS = 7;

    public function __construct(
        private readonly QuickAddParser $parser,
        private readonly StockAvailability $availability,
        private readonly StockManager $stock,
        private readonly QuantityFormatter $formatter,
    ) {}

    /**
     * « 2 œufs, 20 cl de lait et un peu de beurre »
     *
     * @return list<array{name: string, status: string, text: string}>
     *                                                                 status : ok · partial (pas assez en stock) · unknown (quantité non chiffrable) · missing (rien en stock) · notfound
     */
    public function useText(string $text): array
    {
        $parts = preg_split('/\s*(?:,|;|\n|\bet\b|\+)\s*/u', trim($text)) ?: [];
        $results = [];

        foreach (array_filter(array_map('trim', $parts)) as $part) {
            $parsed = $this->parser->parse(preg_replace('/^(?:un peu|une pincée|un fond)\s+(?:de |d\'|d’)?/iu', '', $part));
            $parsed['ingredient'] ??= $this->resolve($parsed['name']);

            if (! $parsed['ingredient']) {
                $results[] = ['name' => $parsed['name'] ?: $part, 'status' => 'notfound', 'text' => 'ingrédient inconnu'];

                continue;
            }

            $results[] = ['name' => $parsed['ingredient']->name, ...$this->take($parsed['ingredient'], $parsed['quantity'], $parsed['unit'], 'Utilisé hors repas')];
        }

        return $results;
    }

    /**
     * « lait » quand le stock contient « Lait demi-écrémé » : un seul ingrédient en stock dont le nom
     * commence ainsi suffit à lever l'ambiguïté.
     */
    public function resolve(string $name): ?Ingredient
    {
        $normalized = \App\Support\NameNormalizer::normalize($name);

        if ($normalized === '') {
            return null;
        }

        $candidates = Ingredient::query()
            ->where('search_name', 'like', $normalized.'%')
            ->whereHas('stockItems', fn ($q) => $q->whereNull('finished_at'))
            ->limit(2)->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * Retire une quantité d'un ingrédient, article le plus proche de sa date d'abord.
     *
     * @return array{status: string, text: string}
     */
    public function take(Ingredient $ingredient, ?float $quantity, ?Unit $unit, string $reason): array
    {
        if ($ingredient->stock_mode === StockMode::None) {
            return ['status' => 'unknown', 'text' => 'non suivi dans le stock'];
        }

        $items = $this->availability->items($ingredient, includeFrozen: false);

        if ($items->isEmpty()) {
            return ['status' => 'missing', 'text' => 'rien en stock'];
        }

        if ($ingredient->stock_mode === StockMode::Presence || $quantity === null) {
            return ['status' => 'unknown', 'text' => 'quantité à ajuster à la main ('.$this->availability->describe($items->first()).')'];
        }

        $unit ??= $ingredient->defaultUnit;
        $remaining = $quantity;
        $taken = 0.0;

        DB::transaction(function () use ($items, $ingredient, $unit, &$remaining, &$taken, $reason) {
            foreach ($items as $item) {
                if ($remaining <= 0.0005) {
                    break;
                }

                $available = $this->availability->amountIn($item, $unit, $ingredient);

                if ($available === null || $available <= 0) {
                    continue;
                }

                $takeInUnit = min($available, $remaining);
                $takeInItemUnit = $this->availability->convert($takeInUnit, $unit, $item->unit, $ingredient) ?? 0.0;
                $left = round((float) $item->quantity - $takeInItemUnit, 3);

                $left <= 0.0005
                    ? $this->stock->finish($item, MovementType::Consume, $reason)
                    : $this->stock->setQuantity($item, $left);

                $remaining -= $takeInUnit;
                $taken += $takeInUnit;
            }
        });

        if ($taken <= 0) {
            return ['status' => 'unknown', 'text' => 'quantités non comparables, à ajuster à la main'];
        }

        $left = $this->availability->forNeed($ingredient, $unit)['amount'];
        $fmt = fn (float $q) => app(\App\Services\IngredientLineFormatter::class)->format($q, $unit, $ingredient)['text'];
        $text = '−'.$fmt($taken).' (reste '.($left > 0.0005 ? $fmt($left) : 'rien').')';

        return $remaining > 0.0005
            ? ['status' => 'partial', 'text' => $text.' — il en manquait '.$fmt($remaining)]
            : ['status' => 'ok', 'text' => $text];
    }

    /* ================================================================ Consommations régulières */

    /**
     * Applique les consommations régulières dues (tâche planifiée, une fois par jour).
     *
     * @return int règles appliquées
     */
    public function applyRules(?Carbon $today = null): int
    {
        $today ??= Carbon::today();
        $count = 0;

        foreach (StockUsageRule::query()->where('is_active', true)->with('ingredient.defaultUnit', 'unit')->get() as $rule) {
            // Première fois : on commence à compter, sans rien retirer.
            if (! $rule->last_applied_on) {
                $rule->update(['last_applied_on' => $today->toDateString()]);

                continue;
            }

            $periods = intdiv((int) $rule->last_applied_on->diffInDays($today), max(1, $rule->every_days));

            if ($periods < 1 || ! $rule->ingredient) {
                continue;
            }

            $this->take($rule->ingredient, (float) $rule->quantity * min($periods, self::MAX_PERIODS), $rule->unit, 'Consommation régulière');
            $rule->update(['last_applied_on' => $rule->last_applied_on->copy()->addDays($periods * $rule->every_days)->toDateString()]);
            $count++;
        }

        return $count;
    }
}
