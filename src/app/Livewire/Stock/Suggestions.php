<?php

namespace App\Livewire\Stock;

use App\Models\MealSlot;
use App\Models\Recipe;
use App\Models\Tag;
use App\Services\Planning\OccasionService;
use App\Services\Planning\WeekPlanner;
use App\Services\Shopping\ShoppingListManager;
use App\Services\Stock\RecipeSuggester;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * « Que cuisiner ? » (module 10) : recettes faisables avec le stock.
 */
#[Title('Que cuisiner ?')]
class Suggestions extends Component
{
    #[Url(as: 'portions', except: 0)]
    public int|float|string $servings = 0;

    /** @var list<int> */
    #[Url(as: 'categories', except: [])]
    public array $tagIds = [];

    #[Url(as: 'duree', except: '')]
    public string $maxMinutes = '';

    /** Ingrédients à utiliser (« vider le frigo »). @var list<int> */
    #[Url(as: 'utiliser', except: [])]
    public array $mustUse = [];

    #[Url(as: 'tout-le-stock', except: false)]
    public bool $ignorePlanning = false;

    /** Case du planning d'où l'on vient. */
    #[Url(as: 'date', except: '')]
    public string $date = '';

    #[Url(as: 'creneau', except: 0)]
    public int $slotId = 0;

    public bool $showOthers = false;

    public bool $showAllIngredients = false;

    /* Fenêtre « Planifier » */
    public ?int $planRecipeId = null;

    public string $planDate = '';

    public ?int $planSlotId = null;

    public int|float|string $planServings = 2;

    public function mount(): void
    {
        if (! $this->date || ! strtotime($this->date)) {
            $this->date = '';
            $this->slotId = 0;
        }

        if ((float) $this->servings < 0.5) {
            $this->servings = $this->date && $this->slotId && MealSlot::whereKey($this->slotId)->exists()
                ? app(OccasionService::class)->servingsAt($this->date, $this->slotId)
                : app(OccasionService::class)->householdSize();
        }

        $this->tagIds = array_values(array_map('intval', $this->tagIds));
        $this->mustUse = array_values(array_map('intval', $this->mustUse));
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['servings', 'maxMinutes', 'ignorePlanning'], true)) {
            unset($this->results);
        }
    }

    public function toggleTag(int $tagId): void
    {
        $this->tagIds = in_array($tagId, $this->tagIds, true) ? array_values(array_diff($this->tagIds, [$tagId])) : [...$this->tagIds, $tagId];
    }

    public function toggleMustUse(int $ingredientId): void
    {
        $this->mustUse = in_array($ingredientId, $this->mustUse, true) ? array_values(array_diff($this->mustUse, [$ingredientId])) : [...$this->mustUse, $ingredientId];
    }

    public function resetFilters(): void
    {
        $this->reset('tagIds', 'maxMinutes', 'mustUse', 'ignorePlanning', 'showOthers');
    }

    /* ================================================================ Actions */

    public function openPlan(int $recipeId): void
    {
        $recipe = Recipe::active()->findOrFail($recipeId);

        $this->resetErrorBag();
        $this->planRecipeId = $recipe->id;
        $this->planDate = $this->date ?: Carbon::today()->toDateString();
        $this->planSlotId = $this->slotId ?: MealSlot::query()->active()->ordered()->get()->last()?->id;
        $this->planServings = $this->servingsValue();
    }

    public function closePlan(): void
    {
        $this->planRecipeId = null;
    }

    public function plan(WeekPlanner $planner): void
    {
        $this->validate([
            'planDate' => 'required|date',
            'planSlotId' => 'required|integer|exists:meal_slots,id',
            'planServings' => 'required|numeric|min:0.5|max:50',
        ], [], ['planDate' => 'date', 'planSlotId' => 'créneau', 'planServings' => 'portions']);

        $recipe = Recipe::active()->findOrFail($this->planRecipeId);

        try {
            $meal = $planner->addRecipe($this->planDate, (int) $this->planSlotId, $recipe, \App\Services\Planning\Appetites::clamp($this->planServings));
        } catch (InvalidArgumentException $e) {
            $this->addError('planDate', $e->getMessage());

            return;
        }

        $this->planRecipeId = null;

        if ($this->date) {
            session()->flash('status', "« {$recipe->title} » ajoutée au planning.");
            $this->redirectRoute('planner.week', ['semaine' => $planner->weekStart($meal->date)->toDateString()], navigate: true);

            return;
        }

        unset($this->results);
        $label = $meal->date->locale('fr')->isoFormat('dddd D MMMM').' · '.mb_strtolower($meal->slot->name);
        $this->dispatch('notify', message: "« {$recipe->title} » planifiée {$label}.");
    }

    public function addMissing(int $recipeId, RecipeSuggester $suggester, ShoppingListManager $manager): void
    {
        $recipe = Recipe::active()->findOrFail($recipeId);
        $pool = $suggester->pool();

        if (! $this->ignorePlanning) {
            $suggester->reserve($pool);
        }

        $lines = $suggester->evaluate($recipe, $this->servingsValue(), $pool)['missing'];

        if ($lines === []) {
            $this->dispatch('notify', message: 'Rien ne manque pour cette recette.');

            return;
        }

        $list = $manager->currentOrNew();
        $result = $manager->addMissing($list, $lines, $recipe->title);
        $count = count($result['added']);

        $message = $count > 0
            ? $count.' article'.($count > 1 ? 's ajoutés' : ' ajouté')." à « {$list->name} » : ".implode(', ', $result['added']).'.'
            : "Déjà dans « {$list->name} ».";

        $this->dispatch('notify', message: $message);
    }

    #[On('stock-changed')]
    public function refresh(): void
    {
        unset($this->results, $this->stockIngredients);
    }

    /* ================================================================ Données */

    /** @return array{feasible: Collection, almost: Collection, others: Collection, reserved: int} */
    #[Computed]
    public function results(): array
    {
        return app(RecipeSuggester::class)->suggest([
            'servings' => $this->servingsValue(),
            'tagIds' => $this->tagIds,
            'maxMinutes' => (int) $this->maxMinutes ?: null,
            'mustUse' => $this->mustUse,
            'ignorePlanning' => $this->ignorePlanning,
        ]);
    }

    /**
     * Ingrédients en stock pour « doit utiliser », ceux qui périment le plus tôt d'abord.
     *
     * @return Collection<int, array{id: int, name: string, days: int|null}>
     */
    #[Computed]
    public function stockIngredients(): Collection
    {
        $pool = app(RecipeSuggester::class)->pool();
        unset($pool['tracked']);

        return collect($pool)
            ->map(fn (array $entries, int $id) => [
                'id' => $id,
                'name' => $entries[0]['item']->ingredient->name,
                'days' => collect($entries)->pluck('days')->filter(fn ($d) => $d !== null)->min(),
            ])
            ->sortBy(fn (array $row) => [$row['days'] === null ? 1 : 0, $row['days'] ?? 0, \App\Support\NameNormalizer::normalize($row['name'])])
            ->values();
    }

    #[Computed]
    public function tags(): Collection
    {
        return Tag::query()->ordered()->whereHas('recipes', fn ($q) => $q->whereNull('archived_at'))->get();
    }

    #[Computed]
    public function activeSlots(): Collection
    {
        return MealSlot::query()->active()->ordered()->get();
    }

    public function render()
    {
        $slot = $this->slotId ? MealSlot::find($this->slotId) : null;

        return view('livewire.stock.suggestions', [
            'context' => $this->date && $slot
                ? ucfirst(Carbon::parse($this->date)->locale('fr')->isoFormat('dddd D MMMM')).' · '.mb_strtolower($slot->name)
                : null,
            'planRecipe' => $this->planRecipeId ? Recipe::find($this->planRecipeId) : null,
        ]);
    }

    private function servingsValue(): float
    {
        return \App\Services\Planning\Appetites::clamp((float) $this->servings ?: 2);
    }
}
