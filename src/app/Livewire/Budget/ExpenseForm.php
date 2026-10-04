<?php

namespace App\Livewire\Budget;

use App\Models\BudgetCategory;
use App\Models\Expense;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Store;
use App\Models\User;
use App\Services\Budget\BudgetTracker;
use App\Services\Planning\OccasionService;
use App\Services\Planning\WeekPlanner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Saisie d'une dépense (lot 22 — 23.1, 23.3, 23.4), ouverte de partout : bouton +, tableau de bord
 * du budget, repas « Restaurant » du planning.
 */
class ExpenseForm extends Component
{
    public bool $show = false;

    #[Locked]
    public ?int $expenseId = null;

    #[Locked]
    public ?int $mealId = null;

    public string $amount = '';

    public ?int $categoryId = null;

    /** Magasin connu (identifiant) ou vide. */
    public string $storeId = '';

    /** Lieu libre quand ce n'est pas un magasin connu : « Chez Mario ». */
    public string $place = '';

    public string $spentOn = '';

    public int|string $persons = '';

    public string $paidBy = '';

    public string $listId = '';

    public string $note = '';

    public bool $split = false;

    /** @var list<array{category_id: int|string|null, amount: string}> */
    public array $splits = [];

    /** Repas pris dehors : l'ajouter aussi au planning (23.4). */
    public bool $addToPlanning = false;

    public string $slotId = '';

    /** Une dépense identique existe : il faut confirmer (R26). */
    public ?string $duplicateWarning = null;

    #[On('open-expense')]
    public function open(?int $expenseId = null, ?int $mealId = null, ?string $kind = null): void
    {
        if (! auth()->user()?->canEdit()) {
            return;   // lecture seule : les dépenses se consultent, sans se saisir
        }

        $this->resetErrorBag();
        $this->reset('amount', 'storeId', 'place', 'persons', 'paidBy', 'listId', 'note', 'split', 'splits', 'addToPlanning', 'duplicateWarning', 'expenseId', 'mealId');
        $this->spentOn = Carbon::today()->toDateString();
        $this->categoryId = BudgetCategory::query()->active()->where('kind', $kind ?? 'groceries')->value('id')
            ?? BudgetCategory::query()->active()->ordered()->value('id');
        $this->slotId = (string) (MealSlot::query()->active()->orderByDesc('sort_order')->value('id') ?? '');
        $this->paidBy = app(BudgetTracker::class)->tracksPayer() ? (string) auth()->id() : '';

        if ($expenseId && ($expense = Expense::with('splits')->find($expenseId))) {
            $this->fillFrom($expense);
        } elseif ($mealId && ($meal = PlannedMeal::find($mealId))) {
            // Repas « Restaurant » du planning → dépense restaurant, datée du repas, pour les convives.
            $this->mealId = $meal->id;
            $this->categoryId = BudgetCategory::query()->active()->where('kind', 'restaurant')->value('id') ?? $this->categoryId;
            $this->spentOn = min($meal->date->toDateString(), Carbon::today()->toDateString());
            $this->place = (string) $meal->free_text;
            $this->persons = app(OccasionService::class)->peopleAt($meal->date, $meal->meal_slot_id);
        }

        $this->suggestList();
        $this->show = true;
    }

    private function fillFrom(Expense $expense): void
    {
        $this->expenseId = $expense->id;
        $this->mealId = $expense->planned_meal_id;
        $this->amount = number_format((float) $expense->amount, 2, ',', '');
        $this->categoryId = $expense->budget_category_id;
        $this->storeId = (string) ($expense->store_id ?? '');
        $this->place = (string) $expense->place;
        $this->spentOn = $expense->spent_on->toDateString();
        $this->persons = $expense->persons ?? '';
        $this->paidBy = (string) ($expense->paid_by ?? '');
        $this->listId = (string) ($expense->shopping_list_id ?? '');
        $this->note = (string) $expense->note;

        if ($expense->splits->count() > 1) {
            $this->split = true;
            $this->splits = $expense->splits->map(fn ($s) => ['category_id' => $s->budget_category_id, 'amount' => number_format((float) $s->amount, 2, ',', '')])->all();
        }
    }

    public function close(): void
    {
        $this->show = false;
    }

    public function updatedStoreId(): void
    {
        $this->suggestCategory();
        $this->suggestList();
    }

    public function updatedPlace(): void
    {
        $this->suggestCategory();
    }

    /** 23.1 : le poste de la dernière dépense au même endroit (« Chez Mario » → Restaurant). */
    private function suggestCategory(): void
    {
        if ($this->expenseId || $this->mealId || $this->split) {
            return;
        }

        $query = Expense::query()->whereHas('category', fn ($q) => $q->whereNull('archived_at'));

        if ($this->storeId !== '') {
            $query->where('store_id', (int) $this->storeId);
        } elseif (trim($this->place) !== '') {
            $query->whereNull('store_id')->where('place', trim($this->place));
        } else {
            return;
        }

        if ($category = $query->latest('spent_on')->latest('id')->value('budget_category_id')) {
            $this->categoryId = (int) $category;
        }
    }

    public function updatedSpentOn(): void
    {
        $this->suggestList();
    }

    /** Ticket de courses : proposer la liste cochée ce jour-là (R26). */
    private function suggestList(): void
    {
        if ($this->expenseId || $this->listId !== '' || ! $this->spentOn) {
            return;
        }

        $list = app(BudgetTracker::class)->suggestList($this->storeId !== '' ? (int) $this->storeId : null, $this->spentOn);
        $this->listId = (string) ($list?->id ?? '');
    }

    public function toggleSplit(): void
    {
        $this->split = ! $this->split;

        if ($this->split && $this->splits === []) {
            $household = BudgetCategory::query()->active()->where('kind', 'household')->value('id');
            $this->splits = [
                ['category_id' => $this->categoryId, 'amount' => $this->amount],
                ['category_id' => $household ?? $this->categoryId, 'amount' => ''],
            ];
        }
    }

    public function addSplit(): void
    {
        $this->splits[] = ['category_id' => $this->categoryId, 'amount' => ''];
    }

    public function removeSplit(int $index): void
    {
        unset($this->splits[$index]);
        $this->splits = array_values($this->splits);
    }

    public function save(BudgetTracker $tracker, WeekPlanner $planner): void
    {
        abort_unless(auth()->user()?->canEdit(), 403);

        $this->validate([
            'amount' => ['required', 'string', 'max:12'],
            'categoryId' => ['required', 'integer', 'exists:budget_categories,id'],
            'spentOn' => ['required', 'date'],
            'place' => ['nullable', 'string', 'max:150'],
            'persons' => ['nullable', 'integer', 'min:1', 'max:99'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['amount' => 'montant', 'categoryId' => 'poste', 'spentOn' => 'date', 'persons' => 'personnes']);

        $data = [
            'amount' => $this->amount,
            'spent_on' => $this->spentOn,
            'budget_category_id' => (int) $this->categoryId,
            'store_id' => $this->storeId !== '' ? (int) $this->storeId : null,
            'place' => $this->storeId !== '' ? null : $this->place,
            'persons' => $this->persons !== '' ? (int) $this->persons : null,
            'paid_by' => $this->paidBy !== '' ? (int) $this->paidBy : null,
            'shopping_list_id' => $this->listId !== '' ? (int) $this->listId : null,
            'planned_meal_id' => $this->mealId,
            'note' => $this->note,
        ];

        $splits = [];

        if ($this->split) {
            foreach ($this->splits as $row) {
                $value = (float) str_replace(',', '.', (string) $row['amount']);

                if ($row['category_id'] && $value > 0) {
                    $splits[(int) $row['category_id']] = ($splits[(int) $row['category_id']] ?? 0) + $value;
                }
            }
        }

        // R26 : même lieu, même jour, même montant → on demande confirmation une fois.
        if ($this->duplicateWarning === null && ($twin = $tracker->duplicateOf($data, $this->expenseId))) {
            $this->duplicateWarning = 'Une dépense identique existe déjà ('.$twin->placeLabel().', '.$twin->spent_on->locale('fr')->isoFormat('D MMM').'). Enregistrer quand même ?';

            return;
        }

        try {
            $expense = $tracker->record($data, $splits, $this->expenseId ? Expense::find($this->expenseId) : null);
        } catch (InvalidArgumentException $e) {
            $this->addError($this->split ? 'splits' : 'amount', $e->getMessage());

            return;
        }

        // 23.4 : repas pris dehors ajouté au planning, dans la case choisie.
        if ($this->addToPlanning && ! $expense->planned_meal_id && $expense->category->isEatingOut() && $this->slotId !== '') {
            $meal = $planner->addFree($expense->spent_on, (int) $this->slotId, $expense->place ?: $expense->category->name);
            $meal->update(['cooked_at' => now()]);
            $expense->update(['planned_meal_id' => $meal->id]);
        }

        $this->show = false;
        $this->dispatch('expenses-changed');
        $this->dispatch('notify', message: 'Dépense de '.$tracker->money((float) $expense->amount).' enregistrée ('.$expense->category->name.').');
    }

    public function delete(): void
    {
        abort_unless(auth()->user()?->canEdit(), 403);

        if ($this->expenseId && ($expense = Expense::find($this->expenseId))) {
            $expense->delete();
            $this->show = false;
            $this->dispatch('expenses-changed');
            $this->dispatch('notify', message: 'Dépense supprimée.');
        }
    }

    #[Computed]
    public function categories(): Collection
    {
        return BudgetCategory::query()->active()->ordered()->get();
    }

    #[Computed]
    public function stores(): Collection
    {
        return Store::query()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function lists(): Collection
    {
        return \App\Models\ShoppingList::query()->latest('id')->limit(8)->get(['id', 'name']);
    }

    public function render()
    {
        $category = $this->categoryId ? $this->categories->firstWhere('id', $this->categoryId) : null;

        return view('livewire.budget.expense-form', [
            'eatingOut' => $category?->isEatingOut() ?? false,
            'isGroceries' => in_array($category?->kind, ['groceries', 'household'], true),
            'tracksPayer' => app(BudgetTracker::class)->tracksPayer(),
            'users' => User::query()->inHousehold()->orderBy('name')->get(['id', 'name']),
            'slots' => MealSlot::query()->active()->orderBy('sort_order')->get(['id', 'name']),
        ]);
    }
}
