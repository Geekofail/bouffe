<?php

namespace App\Services\Receipts;

use App\Enums\StockMode;
use App\Enums\UnitType;
use App\Models\BudgetCategory;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\IngredientPrice;
use App\Models\Receipt;
use App\Models\ReceiptLine;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\StandingItem;
use App\Models\Store;
use App\Models\Unit;
use App\Services\Budget\BudgetTracker;
use App\Services\Pricing\PriceBook;
use App\Services\Stock\ShelfLifeLearner;
use App\Services\Stock\StockManager;
use App\Support\NameNormalizer;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Tickets de caisse (lot 23, module 24) : de la photo à la validation.
 *
 * photo(s) → lecture (R27) → rapprochement (R28) → relecture → « Valider » : dépense répartie par
 * poste (23), prix par magasin (R17), stock (rangement du lot 18), articles de la liste cochés,
 * correspondances apprises (24.5), compte rendu des achats imprévus et oubliés (24.6).
 */
class ReceiptService
{
    public function __construct(
        private readonly ReceiptFiles $files,
        private readonly OcrService $ocr,
        private readonly ReceiptInterpreter $interpreter,
        private readonly ReceiptMatcher $matcher,
        private readonly BudgetTracker $tracker,
        private readonly PriceBook $prices,
        private readonly StockManager $stock,
        private readonly ShelfLifeLearner $learner,
    ) {}

    /* ================================================================ Photo et lecture (24.1, 24.2) */

    /** @param  list<UploadedFile>  $files */
    public function create(array $files): Receipt
    {
        $paths = $this->files->store($files);

        return Receipt::create([
            'status' => Receipt::DRAFT,
            'photo_paths' => $paths,
            'pages' => count($paths),
            'created_by' => auth()->id(),
        ]);
    }

    /** Lit le ticket et prépare la relecture. Rien d'autre n'est enregistré (R27). */
    public function read(Receipt $receipt): Receipt
    {
        if ($receipt->isValidated()) {
            throw new InvalidArgumentException('Ce ticket est déjà validé.');
        }

        try {
            $reading = $this->ocr->readReceipt($receipt);
        } catch (ReadingFailed $e) {
            $receipt->update(['error' => mb_substr($e->getMessage(), 0, 255)]);

            throw $e;
        }

        $data = $this->interpreter->interpret($reading);
        $store = $this->storeFor($data['store_name']);
        $lines = $this->matcher->match($data['lines'], $store);

        DB::transaction(function () use ($receipt, $reading, $data, $store, $lines) {
            $receipt->lines()->delete();

            foreach ($lines as $line) {
                $receipt->lines()->create($line);
            }

            $receipt->update([
                'status' => Receipt::REVIEW,
                'provider' => $reading->provider,
                'pages' => $reading->pages,
                'store_id' => $store?->id,
                'store_name' => $data['store_name'],
                'purchased_on' => $data['date'] ?? Carbon::today(),
                'total' => $data['total'],
                'read_total' => $data['total'],
                'raw_result' => CardScrubber::deep($reading->raw),
                'read_at' => now(),
                'error' => null,
            ]);

            $receipt->update(['shopping_list_id' => $this->suggestList($receipt->fresh('lines'))?->id]);
        });

        return $receipt->fresh(['lines', 'store']);
    }

    /** Principe 5 : sans service, le ticket se saisit à la main sur le même écran. */
    public function manual(Receipt $receipt): Receipt
    {
        $receipt->update([
            'status' => Receipt::REVIEW,
            'purchased_on' => $receipt->purchased_on ?? Carbon::today(),
            'error' => null,
        ]);

        return $receipt;
    }

    /** Magasin connu dont le nom apparaît dans celui lu sur le ticket (« CACTUS BERELDANGE » → Cactus). */
    public function storeFor(?string $name): ?Store
    {
        $normalized = ' '.NameNormalizer::normalize($name).' ';

        if (trim($normalized) === '') {
            return null;
        }

        return Store::query()->get()
            ->filter(fn (Store $s) => ($key = NameNormalizer::normalize($s->name)) !== '' && str_contains($normalized, ' '.$key.' '))
            ->sortByDesc(fn (Store $s) => mb_strlen($s->name))
            ->first();
    }

    /** Liste en cours qui partage le plus d'ingrédients avec le ticket. */
    public function suggestList(Receipt $receipt): ?ShoppingList
    {
        $ingredientIds = $receipt->lines->pluck('ingredient_id')->filter()->unique();

        if ($ingredientIds->isEmpty()) {
            return null;
        }

        return ShoppingList::query()->active()
            ->when($receipt->store_id, fn ($q) => $q->where(fn ($w) => $w->where('store_id', $receipt->store_id)->orWhereNull('store_id')))
            ->where('created_at', '>=', now()->subDays(21))
            ->withCount(['items as matches' => fn ($q) => $q->whereIn('ingredient_id', $ingredientIds)->where('is_removed', false)])
            ->latest('id')->limit(5)->get()
            ->filter(fn (ShoppingList $l) => $l->matches > 0)
            ->sortByDesc('matches')
            ->first();
    }

    /** @return array{sum: float, total: float|null, gap: float|null, ok: bool} */
    public function consistency(Receipt $receipt): array
    {
        return $this->interpreter->consistency($receipt->lines, $receipt->total !== null ? (float) $receipt->total : null);
    }

    /** Dépense déjà saisie à la main qui ressemble à ce ticket (R26) : elle sera remplacée. */
    public function existingExpense(Receipt $receipt): ?Expense
    {
        if ($receipt->total === null || ! $receipt->purchased_on) {
            return null;
        }

        return Expense::query()
            ->whereNull('receipt_id')
            ->where('source', 'manual')
            ->whereDate('spent_on', $receipt->purchased_on->toDateString())
            ->where('amount', (float) $receipt->total)
            ->when($receipt->store_id, fn ($q) => $q->where(fn ($w) => $w->where('store_id', $receipt->store_id)->orWhereNull('store_id')))
            ->first();
    }

    /* ================================================================ Validation (24.4, 24.5, 24.6) */

    /**
     * @param  array{replace_expense?: bool, new_store?: string|null}  $options
     * @return array<string, mixed> compte rendu
     */
    public function validate(Receipt $receipt, array $options = []): array
    {
        $receipt->load('lines.ingredient.defaultUnit', 'lines.packUnit');

        if ($receipt->isValidated()) {
            throw new InvalidArgumentException('Ce ticket est déjà validé.');
        }

        if ($receipt->total === null || (float) $receipt->total <= 0) {
            throw new InvalidArgumentException('Indiquez le total payé.');
        }

        if (! $receipt->purchased_on || $receipt->purchased_on->gt(Carbon::today())) {
            throw new InvalidArgumentException('Indiquez la date d\'achat (pas dans le futur).');
        }

        return DB::transaction(function () use ($receipt, $options) {
            if (! $receipt->store_id && ($name = trim((string) ($options['new_store'] ?? ''))) !== '') {
                $store = Store::create(['name' => mb_substr($name, 0, 100), 'sort_order' => (int) Store::max('sort_order') + 1]);
                $receipt->update(['store_id' => $store->id]);
            }

            $store = $receipt->store_id ? Store::find($receipt->store_id) : null;
            $list = $receipt->shopping_list_id ? ShoppingList::find($receipt->shopping_list_id) : null;
            $date = $receipt->purchased_on->copy();
            $outcome = ['prices' => 0, 'stocked' => 0, 'checked' => 0, 'off_list' => [], 'not_bought' => [], 'messages' => []];

            // --- Dépense (23), répartie par poste
            $replace = ($options['replace_expense'] ?? true) ? $this->existingExpense($receipt) : null;
            $expense = $this->tracker->record([
                'amount' => (float) $receipt->total,
                'spent_on' => $date,
                'budget_category_id' => BudgetCategory::groceries()?->id ?? BudgetCategory::query()->value('id'),
                'store_id' => $store?->id,
                'place' => $store ? null : $receipt->store_name,
                'shopping_list_id' => $list?->id,
                'receipt_id' => $receipt->id,
                'source' => 'receipt',
            ], $this->splits($receipt), $replace);
            $expense->update(['source' => 'receipt', 'receipt_id' => $receipt->id]);
            $outcome['expense_id'] = $expense->id;
            $outcome['replaced_expense'] = $replace !== null;

            $listItems = $list ? $list->items()->where('is_removed', false)->get() : collect();
            $used = [];

            foreach ($receipt->lines as $line) {
                if ($line->kind !== 'article' || ! $line->ingredient) {
                    if ($line->kind === 'article' && $list) {
                        $outcome['off_list'][] = $line->label;
                    }

                    continue;
                }

                $ingredient = $line->ingredient;
                [$quantity, $unit] = $this->quantity($line, $ingredient);

                // --- Prix (R17) : seulement si l'on sait pour quelle quantité il a été payé.
                if ($line->paid() > 0 && ($quantity !== null || $this->pieceUnit($ingredient))) {
                    // Lot 30 (R35) : une ligne avec remise est un prix en promotion.
                    $price = $this->prices->record($ingredient, $line->paid(), $quantity, $unit, $store, $date, IngredientPrice::RECEIPT, (float) $line->discount < 0);
                    $line->ingredient_price_id = $price->id;
                    $outcome['prices']++;
                }

                // --- Liste de courses : l'article correspondant est coché
                $item = $listItems->first(fn (ShoppingListItem $i) => $i->ingredient_id === $ingredient->id && ! in_array($i->id, $used, true));

                if ($item) {
                    $used[] = $item->id;
                    $line->shopping_list_item_id = $item->id;

                    if (! $item->is_checked) {
                        $item->update(['is_checked' => true, 'checked_at' => now(), 'checked_by' => auth()->id()]);
                        $outcome['checked']++;
                    }
                } elseif ($list) {
                    $outcome['off_list'][] = $ingredient->name;
                }

                // --- Stock (rangement du lot 18 : emplacement et date appris)
                if ($line->to_stock && $ingredient->stock_mode !== StockMode::None) {
                    $learned = $ingredient->stock_mode === StockMode::Presence ? [] : $this->learner->suggest($ingredient);
                    $stockItem = $this->stock->add(array_filter([
                        'ingredient_id' => $ingredient->id,
                        'quantity' => $quantity,
                        'unit_id' => $unit?->id,
                        'storage_location_id' => $learned['storage_location_id'] ?? null,
                        'expires_on' => $learned['expires_on'] ?? null,
                        'shopping_list_item_id' => $item?->id,
                        'shopping_list_id' => $list?->id,
                    ], fn ($v) => $v !== null));
                    $line->stock_item_id = $stockItem->id;
                    $outcome['stocked']++;
                    $item?->update(['stocked_at' => now()]);
                }

                $line->save();
            }

            // --- 24.6 : ce qui était sur la liste et n'a pas été acheté
            if ($list) {
                $outcome['not_bought'] = $list->items()->where('is_removed', false)->where('is_checked', false)->pluck('id')->all();
            }

            // --- 24.5 : apprentissage
            foreach ($receipt->lines as $line) {
                $this->matcher->learn($line, $store);
            }

            $receipt->update(['status' => Receipt::VALIDATED, 'validated_at' => now(), 'outcome' => $outcome]);

            if (Settings::int('receipts.keep_months', 12) === 0) {
                $this->deletePhotos($receipt);
            }

            return $outcome;
        });
    }

    /**
     * Répartition par poste (23.3) : chaque ligne comptée va à son poste ; ce qui ne tombe pas
     * juste (écart accepté au total, poste négatif) revient aux courses alimentaires.
     *
     * @return array<int, float>
     */
    public function splits(Receipt $receipt): array
    {
        $groceries = BudgetCategory::groceries()?->id;
        $splits = [];

        foreach ($receipt->lines as $line) {
            if (in_array($line->kind, ReceiptLine::COUNTED, true)) {
                $category = $line->budget_category_id ?? $groceries;
                $splits[$category] = round(($splits[$category] ?? 0) + $line->paid(), 2);
            }
        }

        foreach ($splits as $category => $amount) {
            if ($category !== $groceries && $amount <= 0) {
                $splits[$groceries] = ($splits[$groceries] ?? 0) + $amount;
                unset($splits[$category]);
            }
        }

        $splits[$groceries] = round(($splits[$groceries] ?? 0) + (float) $receipt->total - array_sum($splits), 2);

        if ($splits[$groceries] <= 0) {
            return [];   // répartition impossible : tout sur les courses
        }

        return array_filter($splits, fn ($v) => $v > 0);
    }

    /**
     * Quantité achetée : poids lu, ou nombre × contenu du paquet, ou nombre de pièces.
     *
     * @return array{0: float|null, 1: Unit|null}
     */
    public function quantity(ReceiptLine $line, Ingredient $ingredient): array
    {
        $count = $line->count !== null ? (float) $line->count : 1.0;

        if ($line->weight !== null && (float) $line->weight > 0) {
            return [(float) $line->weight, Unit::firstWhere('code', 'g')];
        }

        if ($line->pack_quantity !== null && (float) $line->pack_quantity > 0 && $line->packUnit) {
            return [round($count * (float) $line->pack_quantity, 3), $line->packUnit];
        }

        if ($unit = $this->pieceUnit($ingredient)) {
            return [$count, $unit];
        }

        return [null, null];   // quantité inconnue : l'article entre au stock sans quantité
    }

    private function pieceUnit(Ingredient $ingredient): ?Unit
    {
        $unit = $ingredient->defaultUnit;

        return $unit && $unit->type === UnitType::Piece ? $unit : null;
    }

    /** « Les garder pour la prochaine fois ? » (24.6) */
    public function resolveNotBought(Receipt $receipt, bool $keep): int
    {
        $ids = (array) ($receipt->outcome['not_bought'] ?? []);
        $items = ShoppingListItem::query()->whereIn('id', $ids)->where('is_checked', false)->where('is_removed', false)->get();

        DB::transaction(function () use ($items, $keep, $receipt) {
            foreach ($items as $item) {
                if ($keep) {
                    StandingItem::create([
                        'label' => $item->label,
                        'ingredient_id' => $item->ingredient_id,
                        'aisle_id' => $item->aisle_id,
                        'store_id' => $receipt->store_id,
                        'note' => 'Pas trouvé le '.$receipt->purchased_on?->locale('fr')->isoFormat('D MMMM'),
                        'created_by' => auth()->id(),
                    ]);
                }

                $item->update(['is_removed' => true]);
            }

            $receipt->update(['outcome' => array_merge((array) $receipt->outcome, ['not_bought' => [], 'not_bought_kept' => $keep ? $items->count() : 0])]);
        });

        return $items->count();
    }

    /* ================================================================ Conservation (24.8) */

    public function deletePhotos(Receipt $receipt): void
    {
        $this->files->delete($receipt);
        $receipt->update(['photos_deleted_at' => now()]);
    }

    /** Photos plus anciennes que la durée choisie : supprimées ; lignes et montants restent. */
    public function purgePhotos(?Carbon $today = null): int
    {
        $months = Settings::int('receipts.keep_months', 12);
        $limit = ($today ?? Carbon::today())->copy()->subMonthsNoOverflow(max(0, $months));
        $count = 0;

        Receipt::query()->whereNull('photos_deleted_at')->whereNotNull('photo_paths')
            ->where(fn ($q) => $months === 0 ? $q->where('status', Receipt::VALIDATED) : $q->where('created_at', '<', $limit))
            ->each(function (Receipt $receipt) use (&$count) {
                $this->deletePhotos($receipt);
                $count++;
            });

        return $count;
    }

    /** Un ticket non validé peut être abandonné (photos comprises). */
    public function discard(Receipt $receipt): void
    {
        if ($receipt->isValidated()) {
            throw new InvalidArgumentException('Un ticket validé ne se supprime pas : sa dépense et son stock existent déjà.');
        }

        $this->files->delete($receipt);
        $receipt->delete();
    }

    /** Tickets récents pour la liste. @return Collection<int, Receipt> */
    public function recent(int $limit = 30): Collection
    {
        return Receipt::query()->with('store', 'expense')->withCount('lines')->latest('id')->limit($limit)->get();
    }
}
