<?php

namespace App\Livewire\Settings;

use App\Models\BudgetAmount;
use App\Models\BudgetCategory;
use App\Models\RecurringExpense;
use App\Services\Budget\BudgetTracker;
use App\Services\Budget\RecurringExpenses;
use App\Support\Palette;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Paramètres → Budget (lot 17 : 17.3, refait au lot 22 : 23.2, 23.7, 23.8, C7).
 *
 * Les postes et leur budget mensuel (daté : changer un montant ne réécrit pas les mois passés),
 * le jour où commence la période, « qui a payé », les dépenses récurrentes.
 */
#[Title('Budget')]
class BudgetSettings extends Component
{
    /** @var array<int, array{name: string, color: string, budget: string}> */
    public array $rows = [];

    public string $newName = '';

    public string $newColor = 'stone';

    public int $startDay = 1;

    public bool $trackPayer = false;

    /* Nouvelle dépense récurrente */
    public string $recLabel = '';

    public string $recAmount = '';

    public ?int $recCategory = null;

    public string $recFrequency = 'monthly';

    public int $recDay = 1;

    public function mount(BudgetTracker $tracker): void
    {
        $this->loadRows($tracker);
        $this->startDay = $tracker->startDay();
        $this->trackPayer = $tracker->tracksPayer();
        $this->recCategory = BudgetCategory::query()->active()->ordered()->value('id');
    }

    private function loadRows(BudgetTracker $tracker): void
    {
        [$start] = $tracker->period();

        $this->rows = BudgetCategory::query()->active()->ordered()->get()
            ->mapWithKeys(fn (BudgetCategory $c) => [$c->id => [
                'name' => $c->name,
                'color' => $c->color,
                'budget' => self::format($tracker->budgetFor($c, $start)),
            ]])->all();

        unset($this->categories, $this->archived);
    }

    private static function format(?float $amount): string
    {
        return $amount ? rtrim(rtrim(number_format($amount, 2, ',', ''), '0'), ',') : '';
    }

    private static function parse(string $value): ?float
    {
        $value = str_replace([',', ' ', '€'], ['.', '', ''], trim($value));

        return $value === '' ? null : (float) $value;
    }

    /* ================================================================ Postes et budgets */

    public function saveCategories(BudgetTracker $tracker): void
    {
        foreach ($this->rows as $id => $row) {
            $this->rows[$id]['budget'] = str_replace([' ', '€'], '', trim((string) $row['budget']));
        }

        $this->validate([
            'rows.*.name' => ['required', 'string', 'max:80'],
            'rows.*.color' => ['required', Rule::in(Palette::keys())],
            'rows.*.budget' => ['nullable', 'regex:/^\d{1,6}([.,]\d{1,2})?$/'],
        ], [
            'rows.*.budget.regex' => 'Montant invalide.',
        ], ['rows.*.name' => 'nom', 'rows.*.budget' => 'budget']);

        [$start] = $tracker->period();

        foreach (BudgetCategory::query()->active()->whereIn('id', array_keys($this->rows))->get() as $category) {
            $row = $this->rows[$category->id];
            $category->update(['name' => trim($row['name']), 'color' => $row['color']]);

            $amount = self::parse((string) $row['budget']);

            // Seul un changement crée une ligne datée : l'historique reste lisible.
            if (round((float) $amount, 2) !== round((float) $tracker->budgetFor($category, $start), 2)) {
                $tracker->setBudget($category, $amount ?? 0.0);
            }
        }

        $this->loadRows($tracker);
        $this->dispatch('notify', message: 'Postes et budgets enregistrés.');
    }

    public function addCategory(BudgetTracker $tracker): void
    {
        $this->validate([
            'newName' => ['required', 'string', 'max:80'],
            'newColor' => ['required', Rule::in(Palette::keys())],
        ], [], ['newName' => 'nom du poste']);

        BudgetCategory::create([
            'name' => trim($this->newName),
            'kind' => 'other',
            'color' => $this->newColor,
            'sort_order' => (int) BudgetCategory::query()->max('sort_order') + 1,
        ]);

        $this->reset('newName', 'newColor');
        $this->loadRows($tracker);
        $this->dispatch('notify', message: 'Poste ajouté.');
    }

    public function move(int $id, int $by, BudgetTracker $tracker): void
    {
        $ordered = BudgetCategory::query()->active()->ordered()->pluck('id')->all();
        $index = array_search($id, $ordered, true);
        $target = $index === false ? null : $index + ($by < 0 ? -1 : 1);

        if ($target === null || ! isset($ordered[$target])) {
            return;
        }

        [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];

        foreach ($ordered as $position => $categoryId) {
            BudgetCategory::whereKey($categoryId)->update(['sort_order' => $position + 1]);
        }

        $this->loadRows($tracker);
    }

    /** Un poste archivé garde ses dépenses passées, mais n'est plus proposé à la saisie. */
    public function archive(int $id, BudgetTracker $tracker): void
    {
        $category = BudgetCategory::findOrFail($id);

        if ($category->kind === 'groceries') {
            $this->dispatch('notify', message: 'Le poste des courses alimentaires ne peut pas être archivé : les listes de courses y sont rattachées.', type: 'warning');

            return;
        }

        $category->update(['archived_at' => now()]);
        $this->loadRows($tracker);
        $this->dispatch('notify', message: "« {$category->name} » archivé.");
    }

    public function restore(int $id, BudgetTracker $tracker): void
    {
        BudgetCategory::findOrFail($id)->update(['archived_at' => null]);
        $this->loadRows($tracker);
    }

    #[Computed]
    public function archived(): Collection
    {
        return BudgetCategory::query()->whereNotNull('archived_at')->ordered()->get();
    }

    #[Computed]
    public function categories(): Collection
    {
        return BudgetCategory::query()->active()->ordered()->with('amounts')->get();
    }

    /* ================================================================ Période et « qui a payé » */

    public function saveOptions(BudgetTracker $tracker): void
    {
        $this->validate(['startDay' => ['required', 'integer', 'min:1', 'max:28']], [], ['startDay' => 'jour de début']);

        Settings::set('budget.period_start_day', $this->startDay);
        Settings::set('budget.track_payer', $this->trackPayer);

        $this->loadRows($tracker);
        $this->dispatch('notify', message: 'Réglages du budget enregistrés.');
    }

    /* ================================================================ Dépenses récurrentes (C7) */

    public function addRecurring(RecurringExpenses $recurring): void
    {
        $this->recAmount = str_replace([' ', '€'], '', trim($this->recAmount));

        $this->validate([
            'recLabel' => ['required', 'string', 'max:150'],
            'recAmount' => ['required', 'regex:/^\d{1,6}([.,]\d{1,2})?$/'],
            'recCategory' => ['required', 'integer', 'exists:budget_categories,id'],
            'recFrequency' => ['required', Rule::in(['monthly', 'weekly'])],
            'recDay' => ['required', 'integer', 'min:1', $this->recFrequency === 'weekly' ? 'max:7' : 'max:28'],
        ], ['recAmount.regex' => 'Montant invalide.'], [
            'recLabel' => 'libellé', 'recAmount' => 'montant', 'recCategory' => 'poste', 'recDay' => 'jour',
        ]);

        try {
            $rule = $recurring->create($this->recLabel, (float) self::parse($this->recAmount), (int) $this->recCategory, $this->recFrequency, $this->recDay);
        } catch (InvalidArgumentException $e) {
            $this->addError('recAmount', $e->getMessage());

            return;
        }

        $this->reset('recLabel', 'recAmount');
        $this->dispatch('notify', message: "« {$rule->label} » : première échéance le ".$rule->next_on->locale('fr')->isoFormat('D MMMM').'.');
    }

    public function updatedRecFrequency(): void
    {
        $this->recDay = 1;
    }

    public function toggleRecurring(int $id): void
    {
        $rule = RecurringExpense::findOrFail($id);
        $active = ! $rule->is_active;
        $data = ['is_active' => $active];

        // Reprise : on repart de la prochaine échéance, sans rattraper la pause.
        if ($active && $rule->next_on->lt(Carbon::today())) {
            $data['next_on'] = RecurringExpense::nextOccurrence($rule->frequency, $rule->day, Carbon::today());
        }

        $rule->update($data);
    }

    /** Supprime la règle ; les dépenses déjà comptées restent. */
    public function deleteRecurring(int $id): void
    {
        RecurringExpense::whereKey($id)->delete();
        $this->dispatch('notify', message: 'Dépense récurrente supprimée (les échéances passées restent comptées).');
    }

    public function render(BudgetTracker $tracker)
    {
        [$start, $end] = $tracker->period();

        return view('livewire.settings.budget', [
            'tracker' => $tracker,
            'periodLabel' => $tracker->periodLabel($start, $end),
            'recurring' => RecurringExpense::query()->with('category')->orderByDesc('is_active')->orderBy('label')->get(),
            'history' => BudgetAmount::query()->with('category')->where('valid_from', '>', '2000-01-01')
                ->orderByDesc('valid_from')->orderByDesc('id')->limit(8)->get(),
            'days' => ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'],
        ]);
    }
}
