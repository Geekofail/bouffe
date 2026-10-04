<?php

namespace App\Services\Stock;

use App\Enums\ExpiryType;
use App\Enums\MovementType;
use App\Enums\StockMode;
use App\Models\Ingredient;
use App\Models\LearnedSuggestion;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Services\Activity\ActivityLog;
use App\Services\QuantityFormatter;
use App\Support\CurrentHousehold;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bouffe apprend (30.4, 30.5, règle R36).
 *
 * Trois réglages d'ingrédient se déduisent de ce qui se passe vraiment à la maison :
 *  - **stock minimum** : « Vous achetez du lait chaque semaine : garder au moins 1 l ? » ;
 *  - **durée après ouverture** : « La crème liquide ouverte est jetée 3 fois sur 4 avant 5 jours :
 *    passer de 5 à 3 jours ? » ;
 *  - **conservation au frais** : même chose pour un produit jeté avant sa durée de conservation.
 *
 * R36 : au moins 4 observations sur 8 semaines ; jamais appliqué seul (« Appliquer » ou « Non merci ») ;
 * « Non merci » fait taire la proposition 90 jours pour ce produit ; un réglage modifié à la main
 * depuis moins de 30 jours n'est pas remis en question.
 *
 * Les propositions ne sont pas enregistrées : elles se recalculent à chaque affichage. Seuls les
 * choix (appliqué, refusé) le sont.
 */
class Learnings
{
    public const MIN_OBSERVATIONS = 4;

    public const WEEKS = 8;

    public const SILENCE_DAYS = 90;

    public const MANUAL_DAYS = 30;

    /** Part des produits jetés trop tôt à partir de laquelle la durée est remise en question. */
    public const WASTE_SHARE = 0.5;

    public const KINDS = ['min_stock', 'after_opening', 'shelf_life'];

    public function __construct(private readonly QuantityFormatter $formatter) {}

    /**
     * Propositions en attente, les plus parlantes d'abord.
     *
     * @return Collection<int, array{key: string, kind: string, ingredient: Ingredient, value: float|int, unit_id: int|null, text: string, detail: string, action: string}>
     */
    public function pending(?Carbon $today = null): Collection
    {
        $today ??= Carbon::today();
        $blocked = $this->blocked($today);

        return collect([...$this->minimumSuggestions($today), ...$this->durationSuggestions($today)])
            ->reject(fn (array $s) => isset($blocked[$s['kind'].':'.$s['ingredient']->id]))
            ->sortByDesc('weight')
            ->values();
    }

    /** Applique une proposition encore valable. @return string message */
    public function apply(string $kind, int $ingredientId, ?Carbon $today = null): string
    {
        $suggestion = $this->find($kind, $ingredientId, $today);
        $ingredient = $suggestion['ingredient'];

        $ingredient->forceFill(match ($kind) {
            'min_stock' => ['min_stock_quantity' => $suggestion['value'], 'min_stock_unit_id' => $suggestion['unit_id']],
            'after_opening' => ['days_after_opening' => (int) $suggestion['value']],
            'shelf_life' => ['shelf_life_days' => (int) $suggestion['value']],
        })->save();

        $this->decide($suggestion, LearnedSuggestion::APPLIED);
        app(ActivityLog::class)->record('stock.learned', 'a appliqué : '.$suggestion['action']);

        return ucfirst($suggestion['action']).'.';
    }

    /** « Non merci » : plus rien pour ce produit et ce réglage pendant 90 jours. */
    public function dismiss(string $kind, int $ingredientId, ?Carbon $today = null): void
    {
        $this->decide($this->find($kind, $ingredientId, $today), LearnedSuggestion::DISMISSED);
    }

    /* ================================================================ Stock minimum (30.4) */

    /** @return list<array> */
    private function minimumSuggestions(Carbon $today): array
    {
        $since = $today->copy()->subWeeks(self::WEEKS);

        $purchases = StockMovement::query()
            ->where('type', MovementType::In->value)
            ->whereNotNull('ingredient_id')
            ->where('created_at', '>=', $since)
            ->get(['ingredient_id', 'quantity', 'unit_id'])
            ->groupBy('ingredient_id')
            ->filter(fn (Collection $rows) => $rows->count() >= self::MIN_OBSERVATIONS);

        if ($purchases->isEmpty()) {
            return [];
        }

        $ingredients = Ingredient::query()->whereIn('id', $purchases->keys())->get()->keyBy('id');
        $units = Unit::query()->get()->keyBy('id');
        $suggestions = [];

        foreach ($purchases as $ingredientId => $rows) {
            $ingredient = $ingredients->get($ingredientId);

            if (! $ingredient || $ingredient->stock_mode === StockMode::None || (float) $ingredient->min_stock_quantity > 0) {
                continue;
            }

            $count = $rows->count();
            $often = $count >= self::WEEKS - 1 ? 'chaque semaine' : "{$count} fois en ".self::WEEKS.' semaines';
            $name = '« '.$ingredient->name.' »';

            if ($ingredient->stock_mode === StockMode::Presence) {
                $value = 1.0;
                $unit = null;
                $keep = 'toujours en avoir';
            } else {
                // La quantité achetée habituellement, dans l'unité la plus utilisée.
                $unitId = $rows->whereNotNull('quantity')->countBy('unit_id')->sortDesc()->keys()->first();
                $quantities = $rows->whereNotNull('quantity')->where('unit_id', $unitId)->pluck('quantity')->map(fn ($q) => (float) $q)->sort()->values();

                if ($quantities->count() < self::MIN_OBSERVATIONS / 2) {
                    continue;
                }

                $value = round((float) $quantities[intdiv($quantities->count(), 2)], 3);
                $unit = $unitId ? $units->get($unitId) : null;
                $keep = 'garder au moins '.$this->formatter->format($value, $unit, QuantityFormatter::SHOPPING);
            }

            $suggestions[] = [
                'key' => 'min_stock:'.$ingredient->id,
                'kind' => 'min_stock',
                'ingredient' => $ingredient,
                'value' => $value,
                'unit_id' => $unit?->id,
                'text' => "Vous achetez {$name} {$often} : {$keep} ?",
                'detail' => "{$count} achats rangés dans le stock depuis ".self::WEEKS.' semaines.',
                'action' => $keep === 'toujours en avoir' ? "toujours avoir {$name} (stock minimum)" : "{$keep} de {$name}",
                'weight' => $count,
            ];
        }

        return $suggestions;
    }

    /* ================================================================ Durées (30.5) */

    /** @return list<array> */
    private function durationSuggestions(Carbon $today): array
    {
        $since = $today->copy()->subWeeks(self::WEEKS);

        $items = StockItem::query()
            ->whereNotNull('ingredient_id')->whereNotNull('finished_at')
            ->where('finished_at', '>=', $since)
            ->whereNull('frozen_on')
            ->get(['id', 'ingredient_id', 'opened_on', 'created_at', 'finished_at']);

        if ($items->isEmpty()) {
            return [];
        }

        $wasted = StockMovement::query()->whereIn('stock_item_id', $items->pluck('id'))
            ->where('type', MovementType::Waste->value)->pluck('stock_item_id')->flip();
        $ingredients = Ingredient::query()->whereIn('id', $items->pluck('ingredient_id')->unique())->get()->keyBy('id');
        $suggestions = [];

        foreach ($items->groupBy('ingredient_id') as $ingredientId => $rows) {
            $ingredient = $ingredients->get($ingredientId);

            if (! $ingredient) {
                continue;
            }

            // Après ouverture : produits ouverts, puis finis ou jetés.
            $opened = $rows->whereNotNull('opened_on');
            $configured = (int) $ingredient->days_after_opening;

            if ($configured > 1 && $opened->count() >= self::MIN_OBSERVATIONS) {
                $suggestions[] = $this->durationSuggestion($ingredient, 'after_opening', $opened, $wasted, $configured,
                    fn (StockItem $i) => (int) $i->opened_on->copy()->startOfDay()->diffInDays($i->finished_at->copy()->startOfDay()));
            }

            // Au frais, sans ouverture : durée de conservation.
            $closed = $rows->whereNull('opened_on');
            $shelf = (int) $ingredient->shelf_life_days;

            if ($shelf > 1 && $ingredient->shelf_life_type !== ExpiryType::None && $closed->count() >= self::MIN_OBSERVATIONS) {
                $suggestions[] = $this->durationSuggestion($ingredient, 'shelf_life', $closed, $wasted, $shelf,
                    fn (StockItem $i) => (int) $i->created_at->copy()->startOfDay()->diffInDays($i->finished_at->copy()->startOfDay()));
            }
        }

        return array_values(array_filter($suggestions));
    }

    private function durationSuggestion(Ingredient $ingredient, string $kind, Collection $rows, Collection $wasted, int $configured, callable $days): ?array
    {
        // Jetés avant la durée réglée : le produit ne tient pas aussi longtemps qu'on le croit.
        $early = $rows->filter(fn (StockItem $i) => $wasted->has($i->id) && $days($i) < $configured)->map($days)->sort()->values();
        $total = $rows->count();

        if ($early->count() < 2 || $early->count() / $total < self::WASTE_SHARE) {
            return null;
        }

        $proposed = max(1, (int) $early[intdiv($early->count() - 1, 2)]);

        if ($proposed >= $configured) {
            return null;
        }

        $name = '« '.$ingredient->name.' »';
        $days = $proposed.' jour'.($proposed > 1 ? 's' : '');
        $when = $kind === 'after_opening' ? "moins de {$configured} jours après ouverture" : "avant {$configured} jours";
        $setting = $kind === 'after_opening' ? 'la durée après ouverture' : 'la conservation';

        return [
            'key' => $kind.':'.$ingredient->id,
            'kind' => $kind,
            'ingredient' => $ingredient,
            'value' => $proposed,
            'unit_id' => null,
            'text' => "{$name} : {$early->count()} fois sur {$total}, à la poubelle {$when}. Passer {$setting} de {$configured} à {$days} ?",
            'detail' => $total.' produits finis ou jetés depuis '.self::WEEKS.' semaines.',
            'action' => ($kind === 'after_opening' ? 'durée après ouverture' : 'conservation')." de {$name} : {$days}",
            'weight' => $early->count() * 2,
        ];
    }

    /* ================================================================ Outils */

    /** Propositions refusées depuis moins de 90 jours, ou réglages modifiés à la main depuis moins de 30 jours. @return array<string, true> */
    private function blocked(Carbon $today): array
    {
        $blocked = [];

        foreach (LearnedSuggestion::query()->where('status', LearnedSuggestion::DISMISSED)->where('silenced_until', '>', $today)->get() as $row) {
            $blocked[$row->kind.':'.$row->ingredient_id] = true;
        }

        $recent = DB::table('household_ingredient_settings')
            ->where('household_id', CurrentHousehold::id())
            ->where('updated_at', '>=', $today->copy()->subDays(self::MANUAL_DAYS))
            ->pluck('ingredient_id');

        foreach ($recent as $ingredientId) {
            foreach (self::KINDS as $kind) {
                $blocked[$kind.':'.$ingredientId] = true;
            }
        }

        return $blocked;
    }

    private function find(string $kind, int $ingredientId, ?Carbon $today): array
    {
        $suggestion = $this->pending($today)->firstWhere('key', $kind.':'.$ingredientId);

        if (! $suggestion) {
            throw new InvalidArgumentException('Cette proposition n\'est plus d\'actualité.');
        }

        return $suggestion;
    }

    private function decide(array $suggestion, string $status): void
    {
        LearnedSuggestion::create([
            'ingredient_id' => $suggestion['ingredient']->id,
            'kind' => $suggestion['kind'],
            'proposed' => ['value' => $suggestion['value'], 'unit_id' => $suggestion['unit_id'], 'text' => $suggestion['text']],
            'status' => $status,
            'silenced_until' => $status === LearnedSuggestion::DISMISSED ? now()->addDays(self::SILENCE_DAYS) : null,
            'decided_by' => auth()->id(),
        ]);
    }
}
