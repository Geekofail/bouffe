<?php

namespace App\Services\Shopping;

use App\Enums\ItemOrigin;
use App\Enums\ListStatus;
use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Store;
use App\Models\Unit;
use App\Services\QuantityParser;
use App\Support\NameNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Création, mise à jour et manipulation des listes de courses enregistrées.
 *
 * La liste est un instantané : modifier le planning ou une recette ne la change pas,
 * sauf si l'on demande explicitement « Mettre à jour depuis le planning » (regenerate).
 */
class ShoppingListManager
{
    use Concerns\PresentsLists;
    use Concerns\SyncsWithPlanning;

    public function __construct(
        private readonly ShoppingListGenerator $generator,
        private readonly ShoppingItemPresenter $presenter,
        private readonly QuantityParser $parser,
        private readonly StoreLayout $layout,
    ) {}

    /* ================================================================ Création */

    /**
     * @param  list<int>  $excludedMealIds
     */
    public function create(Carbon $from, Carbon $to, bool $includePast = false, array $excludedMealIds = [], ?string $name = null, bool $deductStock = true): ShoppingList
    {
        if ($from->gt($to)) {
            throw new InvalidArgumentException('La date de début doit précéder la date de fin.');
        }

        return DB::transaction(function () use ($from, $to, $includePast, $excludedMealIds, $name, $deductStock) {
            $list = ShoppingList::create([
                'name' => trim((string) $name) ?: $this->defaultName($from, $to),
                'store_id' => Store::preferred()?->id,     // 15.3 : ordre des rayons du magasin habituel
                'period_start' => $from,
                'period_end' => $to,
                'include_past' => $includePast,
                'excluded_meal_ids' => array_values(array_map('intval', $excludedMealIds)),
                'deduct_stock' => $deductStock,
                'created_by' => auth()->id(),
            ]);

            $this->syncGenerated($list);
            $this->addRecurringItems($list);
            $this->addStandingItems($list);
            $this->addRestockItems($list);

            return $list;
        });
    }

    public function defaultName(Carbon $from, Carbon $to): string
    {
        return $from->isSameDay($to)
            ? 'Courses du '.$from->locale('fr')->isoFormat('D MMMM')
            : 'Courses du '.$from->locale('fr')->isoFormat('D MMM').' au '.$to->locale('fr')->isoFormat('D MMM');
    }

    /* ================================================================ Mise à jour depuis le planning */

    /**
     * Règle R8 : le stock disponible à la date du premier repas est déduit de la quantité à acheter.
     *  - entièrement couvert → « Déjà en stock » (la quantité reste le besoin, pour « Acheter quand même ») ;
     *  - partiellement → quantité réduite ;
     *  - quantité inconnue ou unité non comparable → rien n'est déduit, note « vérifier » ;
     *  - article qui périme avant le repas → non compté, note.
     */
    /** @var array<int, float> stock réservé par des repas antérieurs à la liste (R24), par article */
    private array $reservedByItem = [];

    /* ================================================================ Articles */

    /**
     * Ajout manuel : « Lessive », « 2 baguettes »… Si le texte correspond à un ingrédient connu,
     * l'article est rangé dans son rayon.
     */
    public function addManual(ShoppingList $list, string $label, ?int $aisleId = null): ShoppingListItem
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label));

        if ($label === '') {
            throw new InvalidArgumentException('Indiquez l\'article à ajouter.');
        }

        $ingredient = $this->matchIngredient($label);

        return $list->items()->create([
            'ingredient_id' => $ingredient?->id,
            'label' => mb_strtoupper(mb_substr($label, 0, 1)).mb_substr($label, 1),
            'aisle_id' => $aisleId ?? $ingredient?->aisle_id ?? Aisle::where('name', 'Divers')->value('id'),
            'origin' => ItemOrigin::Manual,
        ]);
    }

    /** Liste en cours la plus récente, ou nouvelle liste pour les 7 prochains jours. */
    public function currentOrNew(): ShoppingList
    {
        return ShoppingList::query()->active()->orderByDesc('period_start')->orderByDesc('id')->first()
            ?? $this->create(Carbon::today(), Carbon::today()->addDays(6));
    }

    /**
     * Ingrédients manquants (ou partiels) d'une recette suggérée (10.2) ajoutés à la liste.
     *
     * @param  list<array{ingredient_id: int, name: string, status: string, missing: float|null, unit_id: int|null}>  $lines
     * @return array{added: list<string>, already: list<string>}
     */
    public function addMissing(ShoppingList $list, array $lines, string $recipeTitle): array
    {
        $added = [];
        $already = [];

        DB::transaction(function () use ($list, $lines, $recipeTitle, &$added, &$already) {
            foreach ($lines as $line) {
                if (! in_array($line['status'] ?? null, ['missing', 'partial'], true) || ! ($ingredient = Ingredient::find($line['ingredient_id']))) {
                    continue;
                }

                $exists = $list->items()->where('ingredient_id', $ingredient->id)
                    ->where('is_removed', false)->where('is_checked', false)
                    ->where(fn ($q) => $q->whereNull('stock_status')->orWhere('stock_status', '!=', 'covered'))
                    ->exists();

                if ($exists) {
                    $already[] = $ingredient->name;

                    continue;
                }

                $list->items()->create([
                    'ingredient_id' => $ingredient->id,
                    'label' => $ingredient->name,
                    'aisle_id' => $ingredient->aisle_id,
                    'quantity' => $line['missing'] === null ? null : round((float) $line['missing'], 3),
                    'unit_id' => $line['missing'] === null ? null : $line['unit_id'],
                    'origin' => ItemOrigin::Manual,
                    'stock_note' => mb_substr('Pour « '.$recipeTitle.' »', 0, 255),
                ]);
                $added[] = $ingredient->name;
            }
        });

        return ['added' => $added, 'already' => $already];
    }

    /** Cherche un ingrédient dont le nom est exactement le libellé (ou le libellé sans quantité en tête). */
    public function matchIngredient(string $label): ?Ingredient
    {
        $candidates = [NameNormalizer::normalize($label)];

        // « 2 baguettes », « 1 kg de pommes » → « baguettes », « pommes »
        if (preg_match('/^\d+([.,]\d+)?\s*(?:[a-zé.]+\s+(?:de|d\'|du|des)\s*)?(.+)$/iu', $label, $m)) {
            $candidates[] = NameNormalizer::normalize($m[2]);
        }

        $candidates = array_values(array_filter($candidates));

        return Ingredient::query()->whereIn('search_name', $candidates)->first()
            ?? \App\Models\IngredientAlias::query()->whereIn('search_name', $candidates)->first()?->ingredient;
    }

    public function toggleCheck(ShoppingListItem $item): ShoppingListItem
    {
        $checked = ! $item->is_checked;

        $item->update([
            'is_checked' => $checked,
            'checked_by' => $checked ? auth()->id() : null,
            'checked_at' => $checked ? now() : null,
        ]);

        return $item;
    }

    /** Quantité saisie à la main (« 3 », « 1,5 ») et unité ; vide = revenir au calcul. */
    public function updateQuantity(ShoppingListItem $item, ?string $quantity, ?int $unitId): ShoppingListItem
    {
        if (trim((string) $quantity) === '' && $item->origin->isFromPlanning()) {
            $item->update(['quantity_overridden' => false]);
            $this->regenerate($item->shoppingList);

            return $item->fresh();
        }

        $parsed = $this->parser->parse($quantity);

        if ($unitId !== null && ! Unit::whereKey($unitId)->exists()) {
            throw new InvalidArgumentException('Unité inconnue.');
        }

        $item->update([
            'quantity' => $parsed,
            'unit_id' => $parsed === null ? null : $unitId,
            'extra_quantities' => null,
            'quantity_overridden' => $item->origin->isFromPlanning(),
        ]);

        return $item;
    }

    public function setRemoved(ShoppingListItem $item, bool $removed): ShoppingListItem
    {
        $item->update(['is_removed' => $removed, 'is_checked' => false, 'checked_by' => null, 'checked_at' => null]);

        return $item;
    }

    /** Supprime un article ajouté à la main ; un article issu du planning est seulement retiré. */
    public function deleteItem(ShoppingListItem $item): void
    {
        if ($item->origin->isFromPlanning()) {
            $this->setRemoved($item, true);

            return;
        }

        $item->delete();
    }

    public function uncheckAll(ShoppingList $list): int
    {
        return $list->items()->where('is_checked', true)->update(['is_checked' => false, 'checked_by' => null, 'checked_at' => null]);
    }

    public function setStatus(ShoppingList $list, ListStatus $status): ShoppingList
    {
        $list->update(['status' => $status]);

        return $list;
    }

    /**
     * Listes « en cours » dont la période est finie depuis plus de N jours : passées en « terminée »
     * pour ne pas encombrer l'accueil et la page Courses (règle 4.4).
     */
    public function closeStaleLists(int $days = 30, ?Carbon $today = null): int
    {
        $today ??= Carbon::today();

        return ShoppingList::query()
            ->where('status', ListStatus::Active->value)
            ->where('period_end', '<', $today->copy()->subDays($days)->toDateString())
            ->update(['status' => ListStatus::Done->value]);
    }

    /* ================================================================ Affichage */

}
