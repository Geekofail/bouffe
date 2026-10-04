<?php

namespace App\Livewire\Receipts;

use App\Enums\StockMode;
use App\Models\BudgetCategory;
use App\Models\Ingredient;
use App\Models\Receipt;
use App\Models\ReceiptLine;
use App\Models\ShoppingList;
use App\Models\Store;
use App\Models\Unit;
use App\Services\Budget\BudgetTracker;
use App\Services\Receipts\OcrService;
use App\Services\Receipts\ReadingFailed;
use App\Services\Receipts\ReceiptService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Un ticket : relecture (24.3) puis, une fois validé, ce qu'il a rempli (24.4, 24.6).
 */
class Show extends Component
{
    #[Locked]
    public int $receiptId;

    /* En-tête */
    public string $storeId = '';

    public string $newStore = '';

    public string $purchasedOn = '';

    public string $total = '';

    public string $listId = '';

    public bool $replaceExpense = true;

    /** @var list<array<string, mixed>> */
    public array $lines = [];

    /** Écart au total accepté (R27 : on peut toujours valider). */
    public bool $gapWarning = false;

    public function mount(Receipt $receipt): void
    {
        $this->receiptId = $receipt->id;
        $this->loadForm();
    }

    private function receipt(): Receipt
    {
        return Receipt::with('lines.ingredient', 'store')->findOrFail($this->receiptId);
    }

    private function loadForm(): void
    {
        $receipt = $this->receipt();
        $this->storeId = $receipt->store_id ? (string) $receipt->store_id : ($receipt->store_name ? 'new' : '');
        $this->newStore = (string) ($receipt->store_id ? '' : $receipt->store_name);
        $this->purchasedOn = $receipt->purchased_on?->toDateString() ?? Carbon::today()->toDateString();
        $this->total = $receipt->total !== null ? self::money((float) $receipt->total) : '';
        $this->listId = (string) ($receipt->shopping_list_id ?? '');
        $this->lines = $receipt->lines->map(fn (ReceiptLine $l) => $this->row($l))->all();
        unset($this->consistency);
    }

    /** @return array<string, mixed> */
    private function row(ReceiptLine $line): array
    {
        return [
            'id' => $line->id,
            'label' => $line->label,
            'suggested' => (string) $line->suggested_name,
            'kind' => $line->kind,
            'count' => $line->count !== null ? self::number((float) $line->count) : '',
            'weight' => $line->weight !== null ? self::number((float) $line->weight) : '',
            'amount' => self::money((float) $line->amount),
            'discount' => (float) $line->discount !== 0.0 ? self::money((float) $line->discount) : '',
            'ingredient' => (string) $line->ingredient?->name,
            'ingredient_id' => $line->ingredient_id,
            'pack_quantity' => $line->pack_quantity !== null ? self::number((float) $line->pack_quantity) : '',
            'pack_unit_id' => (string) ($line->pack_unit_id ?? ''),
            'confidence' => $line->confidence,
            'doubtful' => $line->doubtful,
            'to_stock' => $line->to_stock,
            'category_id' => (string) ($line->budget_category_id ?? ''),
        ];
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, ',', '');
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', ''), '0'), ',');
    }

    private static function parse(mixed $value): ?float
    {
        $value = str_replace([' ', '€', ','], ['', '', '.'], trim((string) $value));

        return is_numeric($value) ? (float) $value : null;
    }

    private function editable(): bool
    {
        return auth()->user()->canEdit() && ! $this->receipt()->isValidated();
    }

    /* ================================================================ Relecture : enregistrement au fil de l'eau */

    public function updated(string $property): void
    {
        if (! $this->editable()) {
            return;
        }

        if (preg_match('/^lines\.(\d+)\./', $property, $m)) {
            $this->saveLine((int) $m[1], $property);
        } elseif (in_array($property, ['storeId', 'newStore', 'purchasedOn', 'total', 'listId'], true)) {
            $this->saveHeader();
        }

        $this->gapWarning = false;
        unset($this->consistency);
    }

    private function saveHeader(): void
    {
        $date = null;

        try {
            $date = $this->purchasedOn !== '' ? Carbon::parse($this->purchasedOn) : null;
        } catch (\Throwable) {
            $this->addError('purchasedOn', 'Date invalide.');
        }

        $total = self::parse($this->total);

        $this->receipt()->update([
            'store_id' => ctype_digit($this->storeId) ? (int) $this->storeId : null,
            'store_name' => $this->storeId === 'new' ? (trim($this->newStore) ?: null) : $this->receipt()->store_name,
            'purchased_on' => $date,
            'total' => $total !== null && $total > 0 ? round($total, 2) : null,
            'shopping_list_id' => ctype_digit($this->listId) ? (int) $this->listId : null,
        ]);
    }

    private function saveLine(int $index, string $property): void
    {
        $row = $this->lines[$index] ?? null;
        $line = $row ? ReceiptLine::where('receipt_id', $this->receiptId)->find($row['id']) : null;

        if (! $line) {
            return;
        }

        $field = substr($property, strlen("lines.{$index}."));
        $data = [
            'label' => mb_substr(trim((string) $row['label']), 0, 200) ?: $line->label,
            'kind' => array_key_exists($row['kind'], ReceiptLine::KINDS) ? $row['kind'] : 'article',
            'count' => self::parse($row['count']),
            'weight' => self::parse($row['weight']),
            'amount' => self::parse($row['amount']) ?? 0,
            'discount' => self::parse($row['discount']) ?? 0,
            'pack_quantity' => self::parse($row['pack_quantity']),
            'pack_unit_id' => ctype_digit((string) $row['pack_unit_id']) ? (int) $row['pack_unit_id'] : null,
            'to_stock' => (bool) $row['to_stock'],
            'budget_category_id' => ctype_digit((string) $row['category_id']) ? (int) $row['category_id'] : null,
        ];

        if ($field === 'ingredient') {
            $name = trim((string) $row['ingredient']);
            $ingredient = $name === '' ? null : Ingredient::findByName($name);
            $data['ingredient_id'] = $ingredient?->id;
            $data['confidence'] = $ingredient ? 'manual' : 'none';
            $data['to_stock'] = $ingredient && $data['kind'] === 'article' && $ingredient->stock_mode !== StockMode::None;
            $this->lines[$index]['ingredient_id'] = $ingredient?->id;
            $this->lines[$index]['confidence'] = $data['confidence'];
            $this->lines[$index]['to_stock'] = $data['to_stock'];
            $this->lines[$index]['ingredient'] = $ingredient?->name ?? $name;

            if ($name !== '' && ! $ingredient) {
                $this->addError("lines.{$index}.ingredient", 'Ingrédient inconnu : choisissez-le dans la liste.');
            }
        }

        if ($field === 'kind') {
            $household = BudgetCategory::query()->where('kind', 'household')->whereNull('archived_at')->value('id');
            $groceries = BudgetCategory::groceries()?->id;

            if ($data['kind'] !== 'article') {
                $data['to_stock'] = false;
            }

            $data['budget_category_id'] = $data['kind'] === 'non_food' ? ($household ?? $groceries) : ($line->kind === 'non_food' ? $groceries : $data['budget_category_id']);
            $this->lines[$index]['to_stock'] = $data['to_stock'];
            $this->lines[$index]['category_id'] = (string) ($data['budget_category_id'] ?? '');
        }

        $line->update($data);
    }

    public function addLine(): void
    {
        abort_unless($this->editable(), 403);

        $receipt = $this->receipt();
        $line = $receipt->lines()->create([
            'position' => (int) $receipt->lines()->max('position') + 1,
            'label' => 'Article',
            'kind' => 'article',
            'amount' => 0,
            'confidence' => 'manual',
            'to_stock' => false,
            'budget_category_id' => BudgetCategory::groceries()?->id,
        ]);

        $this->lines[] = $this->row($line);
        unset($this->consistency);
    }

    public function removeLine(int $index): void
    {
        abort_unless($this->editable(), 403);

        if (isset($this->lines[$index])) {
            ReceiptLine::where('receipt_id', $this->receiptId)->whereKey($this->lines[$index]['id'])->delete();
            unset($this->lines[$index]);
            $this->lines = array_values($this->lines);
            unset($this->consistency);
        }
    }

    /** Le total prend la somme des lignes (« le ticket semble incomplet » corrigé à la main). */
    public function useSum(): void
    {
        abort_unless($this->editable(), 403);

        $this->total = self::money($this->consistency['sum']);
        $this->saveHeader();
        unset($this->consistency);
    }

    /* ================================================================ Actions */

    public function retry(ReceiptService $receipts): void
    {
        abort_unless($this->editable(), 403);
        \App\Support\TimeLimit::atLeast(180);

        try {
            $receipts->read($this->receipt());
            $this->loadForm();
            $this->dispatch('notify', message: 'Ticket lu : vérifiez les lignes puis validez.');
        } catch (ReadingFailed|InvalidArgumentException $e) {
            session()->flash('receipt-error', $e->getMessage());
        }
    }

    public function manual(ReceiptService $receipts): void
    {
        abort_unless($this->editable(), 403);
        $receipts->manual($this->receipt());
        $this->loadForm();
    }

    public function validateReceipt(ReceiptService $receipts, BudgetTracker $tracker): void
    {
        abort_unless($this->editable(), 403);
        $this->saveHeader();

        if ($this->storeId === 'new' && trim($this->newStore) === '') {
            $this->addError('newStore', 'Nom du magasin, ou choisissez « Magasin inconnu ».');

            return;
        }

        $consistency = $this->consistency;

        // R27 : un écart se confirme ; un ticket saisi sans lignes n'a que son total, rien à confirmer.
        if (! $consistency['ok'] && ! $this->gapWarning && $consistency['total'] !== null && $this->lines !== []) {
            $this->gapWarning = true;

            return;
        }

        try {
            $outcome = $receipts->validate($this->receipt(), [
                'replace_expense' => $this->replaceExpense,
                'new_store' => $this->storeId === 'new' ? $this->newStore : null,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->addError('validate', $e->getMessage());

            return;
        }

        $this->gapWarning = false;
        $this->loadForm();
        $this->dispatch('expenses-changed');
        $this->dispatch('notify', message: 'Ticket validé : dépense de '.$tracker->money((float) $this->receipt()->total)
            .($outcome['stocked'] ? ', '.$outcome['stocked'].' article'.($outcome['stocked'] > 1 ? 's' : '').' au stock' : '').'.');
    }

    public function resolveNotBought(bool $keep, ReceiptService $receipts): void
    {
        abort_unless(auth()->user()->canEdit(), 403);
        $count = $receipts->resolveNotBought($this->receipt(), $keep);
        $this->dispatch('notify', message: $keep
            ? "{$count} article".($count > 1 ? 's' : '').' gardé'.($count > 1 ? 's' : '').' dans « Quand je passe ».'
            : "{$count} article".($count > 1 ? 's' : '').' retiré'.($count > 1 ? 's' : '').' de la liste.');
    }

    public function discard(ReceiptService $receipts)
    {
        abort_unless($this->editable(), 403);
        $receipts->discard($this->receipt());
        $this->dispatch('notify', message: 'Ticket supprimé.');

        return $this->redirectRoute('receipts.index', navigate: true);
    }

    /* ================================================================ Données de la page */

    /** @return array{sum: float, total: float|null, gap: float|null, ok: bool} */
    #[Computed]
    public function consistency(): array
    {
        return app(ReceiptService::class)->consistency($this->receipt());
    }

    #[Computed]
    public function stores(): Collection
    {
        return Store::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function lists(): Collection
    {
        return ShoppingList::query()->latest('id')->limit(8)->get(['id', 'name', 'status']);
    }

    #[Computed]
    public function categories(): Collection
    {
        return BudgetCategory::query()->active()->ordered()->get(['id', 'name', 'color']);
    }

    #[Computed]
    public function units(): Collection
    {
        return Unit::query()->whereIn('code', ['g', 'kg', 'ml', 'cl', 'l', 'piece'])->orderBy('sort_order')->get(['id', 'code', 'label']);
    }

    /** @return list<string> */
    #[Computed]
    public function ingredientNames(): array
    {
        return Ingredient::query()->orderBy('name')->pluck('name')->all();
    }

    public function render(ReceiptService $receipts, OcrService $ocr, BudgetTracker $tracker)
    {
        $receipt = $this->receipt()->load('expense', 'shoppingList', 'lines.stockItem', 'lines.shoppingListItem', 'lines.ingredient');
        $outcome = (array) $receipt->outcome;

        return view('livewire.receipts.show', [
            'receipt' => $receipt,
            'tracker' => $tracker,
            'status' => $ocr->status(),
            'existing' => $receipt->isValidated() ? null : $receipts->existingExpense($receipt),
            'notBought' => \App\Models\ShoppingListItem::query()->whereIn('id', (array) ($outcome['not_bought'] ?? []))
                ->where('is_removed', false)->where('is_checked', false)->get(),
        ])->title('Ticket — '.$receipt->storeLabel());
    }
}
