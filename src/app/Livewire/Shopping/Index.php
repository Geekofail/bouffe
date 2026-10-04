<?php

namespace App\Livewire\Shopping;

use App\Enums\ListStatus;
use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\StandingItem;
use App\Services\Planning\WeekPlanner;
use App\Services\Shopping\ShoppingListGenerator;
use App\Services\Shopping\ShoppingListManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Listes de courses')]
class Index extends Component
{
    use \App\Livewire\Concerns\OffersUndo;

    /** Ouvre directement la fenêtre de génération pour la semaine donnée (lien depuis le planning). */
    #[Url(as: 'generer', except: '')]
    public string $generateFor = '';

    /** Liste pour un repas précis (« 12,13 ») : lien « Liste de courses pour ce repas » des convives. */
    #[Url(as: 'repas', except: '')]
    public string $mealsFor = '';

    public bool $showGenerate = false;

    public string $from = '';

    public string $to = '';

    public bool $includePast = false;

    /** Règle R8 : déduire ce qui est déjà en stock. */
    public bool $deductStock = true;

    public string $name = '';

    /** @var list<int> repas décochés */
    public array $excludedMealIds = [];

    /** Liste « quand je passe » (15.8). */
    public string $standingLabel = '';

    public function mount(ShoppingListManager $manager): void
    {
        $manager->closeStaleLists();

        if ($this->generateFor !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->generateFor)) {
            $ids = array_values(array_filter(array_map('intval', explode(',', $this->mealsFor))));
            $ids === [] ? $this->openGenerate($this->generateFor) : $this->openForMeals($this->generateFor, $ids);
        }
    }

    /* ================================================================ Génération */

    public function openGenerate(?string $weekOf = null): void
    {
        $planner = app(WeekPlanner::class);
        $today = Carbon::today();
        $weekStart = $planner->weekStart($weekOf ?: $today);

        // Semaine en cours : à partir d'aujourd'hui ; semaine future : lundi → dimanche.
        $this->from = $weekStart->copy()->max($today)->toDateString();
        $this->to = $weekStart->copy()->addDays(6)->toDateString();

        if (Carbon::parse($this->to)->lt($today)) {
            $this->from = $weekStart->toDateString();
            $this->includePast = true;
        } else {
            $this->includePast = false;
        }

        $this->reset('name', 'excludedMealIds');
        $this->resetErrorBag();
        $this->showGenerate = true;
    }

    public function preset(string $preset): void
    {
        $planner = app(WeekPlanner::class);
        $today = Carbon::today();

        [$from, $to] = match ($preset) {
            'next-week' => [$planner->weekStart($today)->addWeek(), $planner->weekStart($today)->addWeek()->addDays(6)],
            'next-7-days' => [$today->copy(), $today->copy()->addDays(6)],
            default => [$today->copy(), $planner->weekStart($today)->addDays(6)],
        };

        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
        $this->excludedMealIds = [];
    }

    /**
     * Période réduite au jour du repas, seuls les plats choisis cochés.
     *
     * @param  list<int>  $mealIds
     */
    public function openForMeals(string $date, array $mealIds): void
    {
        $day = Carbon::parse($date);
        $this->openGenerate($date);

        $this->from = $this->to = $day->toDateString();
        $this->includePast = $day->lt(Carbon::today());
        $this->excludedMealIds = app(ShoppingListGenerator::class)->meals($day, $day, includePast: true)
            ->pluck('id')->diff($mealIds)->values()->all();

        $occasion = \App\Models\MealOccasion::query()->whereDate('date', $day->toDateString())
            ->whereIn('meal_slot_id', \App\Models\PlannedMeal::query()->whereIn('id', $mealIds)->pluck('meal_slot_id'))->first();
        $this->name = mb_substr('Courses — '.($occasion?->title ?: 'repas du '.$day->locale('fr')->isoFormat('ddd D MMM')), 0, 100);
    }

    public function closeGenerate(): void
    {
        $this->showGenerate = false;
        $this->generateFor = '';
        $this->mealsFor = '';
    }

    public function toggleMeal(int $mealId): void
    {
        $this->excludedMealIds = in_array($mealId, $this->excludedMealIds, true)
            ? array_values(array_diff($this->excludedMealIds, [$mealId]))
            : [...$this->excludedMealIds, $mealId];
    }

    /**
     * Repas « recette » de la période (avant exclusions), avec indication « passé ».
     *
     * @return Collection<int, \App\Models\PlannedMeal>
     */
    #[Computed]
    public function periodMeals(): Collection
    {
        if (! $this->validPeriod()) {
            return collect();
        }

        return app(ShoppingListGenerator::class)->meals(Carbon::parse($this->from), Carbon::parse($this->to), includePast: true);
    }

    #[Computed]
    public function preview(): array
    {
        if (! $this->validPeriod()) {
            return ['meals' => 0, 'ingredients' => 0];
        }

        $generator = app(ShoppingListGenerator::class);
        $meals = $generator->meals(Carbon::parse($this->from), Carbon::parse($this->to), $this->includePast, $this->excludedMealIds);

        return ['meals' => $meals->count(), 'ingredients' => $generator->generate($meals)->count()];
    }

    public function generate(ShoppingListManager $manager): void
    {
        $this->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'name' => 'nullable|string|max:100',
        ], [], ['from' => 'date de début', 'to' => 'date de fin', 'name' => 'nom']);

        try {
            $list = $manager->create(Carbon::parse($this->from), Carbon::parse($this->to), $this->includePast, $this->excludedMealIds, $this->name, $this->deductStock);
        } catch (InvalidArgumentException $e) {
            $this->addError('to', $e->getMessage());

            return;
        }

        $this->redirectRoute('shopping.show', ['shoppingList' => $list], navigate: true);
    }

    public function defaultName(): string
    {
        return $this->validPeriod()
            ? app(ShoppingListManager::class)->defaultName(Carbon::parse($this->from), Carbon::parse($this->to))
            : '';
    }

    private function validPeriod(): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from)
            && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->to)
            && $this->from <= $this->to;
    }

    /* ================================================ Liste « quand je passe » (15.8) */

    /**
     * Ce qui n'appartient à aucune semaine : piles, ampoules, un cadeau. Ça attend ici et
     * part tout seul dans la prochaine liste créée, au lieu de traîner sur un papier.
     */
    public function addStanding(): void
    {
        $this->standingLabel = trim($this->standingLabel);

        $this->validate(
            ['standingLabel' => 'required|string|max:200'],
            [],
            ['standingLabel' => 'article'],
        );

        $ingredient = Ingredient::query()->where('search_name', \App\Support\NameNormalizer::normalize($this->standingLabel))->first();

        StandingItem::create([
            'label' => mb_strtoupper(mb_substr($this->standingLabel, 0, 1)).mb_substr($this->standingLabel, 1),
            'ingredient_id' => $ingredient?->id,
            'aisle_id' => $ingredient?->aisle_id,
            'created_by' => auth()->id(),
        ]);

        $this->reset('standingLabel');
        unset($this->standing);
    }

    public function removeStanding(int $id): void
    {
        StandingItem::whereKey($id)->delete();
        unset($this->standing);
    }

    /** @return Collection<int, StandingItem> */
    #[Computed]
    public function standing(): Collection
    {
        return StandingItem::query()->waiting()->orderBy('id')->get();
    }

    /* ================================================================ Listes */

    public function delete(int $listId): void
    {
        $list = ShoppingList::findOrFail($listId);

        // Lot 30 (R32) : la liste revient avec ses articles, ses coches et ses liens (stock, dépenses).
        $this->undoable('shopping.delete-list', "Liste « {$list->name} » supprimée", function (\App\Services\Undo\UndoRecorder $r) use ($list) {
            $r->track('shopping_lists', [$list->id]);
            $list->delete();
        });
    }

    /** Après « Annuler » (lot 30) : les listes sont relues. */
    #[On('bouffe-undone')]
    public function afterUndo(): void {}

    public function render()
    {
        $lists = ShoppingList::query()
            ->withCount([
                'items as total_count' => fn ($q) => $q->where('is_removed', false),
                'items as checked_count' => fn ($q) => $q->where('is_removed', false)->where('is_checked', true),
            ])
            ->orderByDesc('period_start')->orderByDesc('id')
            ->get();

        return view('livewire.shopping.index', [
            'activeLists' => $lists->where('status', ListStatus::Active)->values(),
            'doneLists' => $lists->where('status', ListStatus::Done)->values(),
            'today' => Carbon::today()->toDateString(),
        ]);
    }
}
