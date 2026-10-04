<?php

namespace App\Livewire\Layout;

use App\Models\Aisle;
use App\Models\Ingredient;
use App\Models\MealSlot;
use App\Services\QuantityFormatter;
use App\Services\Shopping\ShoppingListManager;
use App\Services\Stock\QuickAddParser;
use App\Services\Stock\StockManager;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Bouton « + » (12.2) : ajouter au stock, à la liste de courses, un repas aujourd'hui ou demain, une recette.
 */
class QuickAdd extends Component
{
    public bool $show = false;

    /** menu · stock · shopping · meal */
    public string $mode = 'menu';

    public string $text = '';

    /** Résultat de « J'ai utilisé… » (lot 21, 22.4). @var list<array{name: string, status: string, text: string}> */
    public array $used = [];

    /** Nom non reconnu au stock : on demande ingrédient ou plat préparé. */
    public ?string $unknownName = null;

    #[On('open-quick-add')]
    public function open(string $mode = 'menu'): void
    {
        $this->resetErrorBag();
        $this->reset('text', 'unknownName', 'used');
        $this->mode = in_array($mode, ['menu', 'stock', 'use', 'shopping', 'meal'], true) ? $mode : 'menu';
        $this->show = true;
    }

    public function choose(string $mode): void
    {
        $this->open($mode);
    }

    public function close(): void
    {
        $this->show = false;
    }

    /** « Une dépense » (lot 22, 23.1) : la fenêtre de saisie est commune à toute l'application. */
    public function expense(): void
    {
        $this->show = false;
        $this->dispatch('open-expense')->to(\App\Livewire\Budget\ExpenseForm::class);
    }

    /** « 6 œufs », « 500 g de haché », « restes de lasagnes » → stock. */
    public function addToStock(string $as = ''): void
    {
        $parser = app(QuickAddParser::class);
        $stock = app(StockManager::class);

        $text = trim($this->text);

        if ($text === '') {
            $this->addError('text', 'Tapez un produit, par exemple « 6 œufs ».');

            return;
        }

        $parsed = $parser->parse($text);
        $ingredient = $parsed['ingredient'];

        if (! $ingredient && $as === '') {
            $this->unknownName = $parsed['name'];

            return;
        }

        if (! $ingredient && $as === 'ingredient') {
            $name = mb_strtoupper(mb_substr($parsed['name'], 0, 1)).mb_substr($parsed['name'], 1);
            $ingredient = Ingredient::create(['name' => $name, 'aisle_id' => Aisle::where('name', 'Divers')->value('id') ?? Aisle::query()->orderBy('sort_order')->value('id')]);
        }

        try {
            $item = $stock->add([
                'ingredient_id' => $ingredient?->id,
                'label' => $ingredient ? null : $parsed['name'],
                'quantity' => $parsed['quantity'],
                'unit_id' => $parsed['unit']?->id,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->addError('text', $e->getMessage());

            return;
        }

        $quantity = $item->quantity !== null ? app(QuantityFormatter::class)->format((float) $item->quantity, $item->unit).' · ' : '';
        $this->dispatch('notify', message: "« {$item->name()} » ajouté au stock ({$quantity}{$item->location->name}).");
        $this->dispatch('stock-changed');
        $this->reset('text', 'unknownName');
    }

    /** « J'ai utilisé 2 œufs et 20 cl de lait » : retiré du stock, hors repas (lot 21, 22.4). */
    public function useFromStock(\App\Services\Stock\StockUsage $usage): void
    {
        $text = trim($this->text);

        if ($text === '') {
            $this->addError('text', 'Tapez ce que vous avez utilisé, par exemple « 2 œufs, 20 cl de lait ».');

            return;
        }

        $this->used = $usage->useText($text);

        if (collect($this->used)->contains(fn ($row) => in_array($row['status'], ['ok', 'partial'], true))) {
            $this->dispatch('stock-changed');
            $this->text = '';
        }
    }

    public function addToShopping(ShoppingListManager $manager): void
    {
        $text = trim($this->text);

        if ($text === '') {
            $this->addError('text', 'Tapez un article, par exemple « lessive ».');

            return;
        }

        $list = $manager->currentOrNew();

        try {
            $item = $manager->addManual($list, $text);
        } catch (InvalidArgumentException $e) {
            $this->addError('text', $e->getMessage());

            return;
        }

        $this->dispatch('notify', message: "« {$item->label} » ajouté à « {$list->name} ».");
        $this->reset('text');
    }

    public function render()
    {
        $today = Carbon::today();

        return view('livewire.layout.quick-add', [
            'mealSlots' => $this->show && $this->mode === 'meal' ? MealSlot::query()->active()->ordered()->get() : collect(),
            'days' => [$today, $today->copy()->addDay()],
        ]);
    }
}
