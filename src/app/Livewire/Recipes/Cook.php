<?php

namespace App\Livewire\Recipes;

use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\RecipeCookNote;
use App\Services\IngredientLineFormatter;
use App\Services\Planning\WeekPlanner;
use App\Services\QuantityScaler;
use App\Services\Recipes\StepTimers;
use App\Support\NameNormalizer;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Mode cuisine (13.4) : une étape à la fois, en grand, avec les ingrédients de l'étape,
 * des minuteurs et l'écran maintenu allumé. Se termine par « mangé » et une note de cuisine.
 */
#[Layout('layouts.cook')]
class Cook extends Component
{
    #[Locked]
    public Recipe $recipe;

    /** 0 = mise en place · 1..n = étapes · n+1 = fin */
    #[Url(as: 'etape', except: 0)]
    public int $step = 0;

    #[Url(as: 'portions', except: 0)]
    public float $servings = 0;

    #[Url(as: 'repas', except: 0)]
    public int $mealId = 0;

    /** Lignes d'ingrédients cochées (mise en place). @var list<int> */
    public array $checked = [];

    /** Étapes terminées. @var list<int> */
    public array $done = [];

    public string $note = '';

    public bool $saved = false;

    public function mount(Recipe $recipe): void
    {
        abort_if($recipe->isArchived(), 404);

        $this->recipe = $recipe;
        $meal = $this->mealId ? PlannedMeal::find($this->mealId) : null;

        if (! $meal || ! $meal->eatenRecipe()?->is($recipe)) {
            $this->mealId = 0;
            $meal = null;
        }

        if ($this->servings < 0.5) {
            $this->servings = $meal?->servings ?: $recipe->servings;
        }

        $this->servings = \App\Services\Planning\Appetites::clamp($this->servings);
        $this->step = max(0, min($this->lastStep(), $this->step));
    }

    /* ================================================================ Navigation */

    public function goTo(int $step): void
    {
        $this->step = max(0, min($this->lastStep(), $step));
    }

    public function next(): void
    {
        if ($this->step >= 1 && $this->step <= $this->steps->count()) {
            $this->done = array_values(array_unique([...$this->done, $this->step]));
        }

        $this->goTo($this->step + 1);
    }

    public function previous(): void
    {
        $this->goTo($this->step - 1);
    }

    public function toggleLine(int|string $lineId): void
    {
        $lineId = (string) $lineId;
        $this->checked = array_map('strval', $this->checked);

        $checking = ! in_array($lineId, $this->checked, true);

        $this->checked = $checking
            ? [...$this->checked, $lineId]
            : array_values(array_diff($this->checked, [$lineId]));

        // Retrait au fil du mode cuisine (lot 21, 22.6) : seulement pour un repas du planning, en cochant.
        if ($checking && $this->mealId && \App\Support\Settings::bool('stock.cook_mode_live', false)) {
            $this->takeFromStock($lineId);
        }
    }

    private function takeFromStock(string $lineId): void
    {
        $line = $this->lines->firstWhere(fn (array $l) => (string) $l['id'] === $lineId);
        $meal = $this->meal;

        if (! $line || ! $line['ingredient_id'] || ! $meal || $meal->cooked_at) {
            return;
        }

        // Le retrait porte sur les portions du repas, pas sur celles affichées : c'est le repas qui est cuisiné.
        $taken = app(\App\Services\Stock\MealStockService::class)->applyIngredient($meal, (int) $line['ingredient_id']);

        if ($taken) {
            $this->dispatch('notify', message: $line['name'].' : '.$taken);
            $this->dispatch('stock-changed');
        }
    }

    public function toggleStep(int $step): void
    {
        $this->done = in_array($step, $this->done, true)
            ? array_values(array_diff($this->done, [$step]))
            : [...$this->done, $step];
    }

    /** Lot 40 (40.1, R43) : un remplacement utilisé est noté dans la note de cuisine, la recette ne change pas. */
    public function useSubstitute(int $ingredientId, int $substituteId): void
    {
        $from = \App\Models\Ingredient::find($ingredientId);
        $to = \App\Models\Ingredient::find($substituteId);

        if (! $from || ! $to) {
            return;
        }

        $sentence = $to->name.' au lieu de '.mb_strtolower($from->name).'.';

        if (! str_contains($this->note, $sentence)) {
            $this->note = trim($this->note.' '.$sentence);
        }

        $this->dispatch('notify', message: 'Noté pour la note de cuisine : « '.$sentence.' » (à enregistrer à la fin).');
    }

    /** Lot 40 (40.2) : une note du foyer sur l'étape affichée. */
    public string $stepNote = '';

    public function addStepNote(int $stepNumber, \App\Services\Recipes\StepNotes $notes): void
    {
        if (! auth()->user()?->canEdit()) {
            return;
        }

        try {
            $notes->add($this->recipe, $stepNumber, $this->stepNote);
        } catch (\InvalidArgumentException $e) {
            $this->addError('stepNote', $e->getMessage());

            return;
        }

        $this->reset('stepNote');
        $this->resetErrorBag('stepNote');
        unset($this->steps);
        $this->dispatch('notify', message: 'Note ajoutée à l\'étape '.$stepNumber.'.');
    }

    public function deleteStepNote(int $noteId, \App\Services\Recipes\StepNotes $notes): void
    {
        if (! auth()->user()?->canEdit()) {
            return;
        }

        $notes->delete(\App\Models\RecipeStepNote::query()->where('recipe_id', $this->recipe->id)->findOrFail($noteId));
        unset($this->steps);
    }

    /**
     * Lot 40 (40.1) : remplacements des ingrédients, sans ce qui heurte quelqu'un à table (R43).
     *
     * @return Collection<int, list<array{substitute_id: int, name: string, quantity: string, note: string|null, ratio: string, in_stock: bool}>>
     */
    #[Computed]
    public function substitutes(): Collection
    {
        $household = app(\App\Services\Planning\HouseholdService::class);
        $eaters = $this->meal ? $household->eatersAt($this->meal->date, $this->meal->meal_slot_id) : $household->eaters(null);
        $ids = $this->lines->pluck('ingredient_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $found = app(\App\Services\Recipes\Substitutions::class)->forMany($ids, $this->recipe, $eaters);

        if ($found->isEmpty()) {
            return collect();
        }

        $inStock = \App\Models\StockItem::query()->active()->whereIn('ingredient_id', $found->flatten()->pluck('substitute_id'))->pluck('ingredient_id')->flip();
        $lines = $this->recipe->ingredients->keyBy('ingredient_id');
        $scaler = app(QuantityScaler::class);
        $formatter = app(IngredientLineFormatter::class);
        $service = app(\App\Services\Recipes\Substitutions::class);

        return $found->map(fn (Collection $list, int $ingredientId) => $list->take(3)->map(function ($substitution) use ($lines, $scaler, $formatter, $service, $inStock) {
            $line = $lines->get($substitution->ingredient_id);
            $quantity = '';
            $text = $substitution->substitute->name;

            // Une quantité seulement quand l'unité le permet (« 160 g de beurre » → « 130 g de margarine »).
            if ($line && $line->unit?->type->isConvertible() && $line->quantity !== null) {
                $scaled = $scaler->scale($line->quantity, $this->recipe->servings, $this->servings);
                $parts = $formatter->format($service->quantity($scaled === null ? null : (float) $scaled, $substitution), $line->unit, $substitution->substitute);
                $quantity = $parts['quantity'];
                $text = $parts['text'];
            }

            return [
                'substitute_id' => $substitution->substitute_id,
                'name' => $substitution->substitute->name,
                'text' => $text,
                'quantity' => $quantity,
                'note' => $substitution->note,
                'ratio' => $substitution->ratioLabel(),
                'detail' => collect([$quantity === '' ? $substitution->ratioLabel() : null, $substitution->note])->filter()->join(' · '),
                'in_stock' => $inStock->has($substitution->substitute_id),
            ];
        })->sortByDesc('in_stock')->values()->all());
    }

    /** Lot 39 (39.4) : corrige le repérage d'une étape (« avec un adulte » ou non). */
    public function correctAdult(int $stepNumber): void
    {
        if (! auth()->user()?->canEdit() || ! $this->recipe->kid_friendly) {
            return;
        }

        $step = $this->recipe->steps->values()->get($stepNumber - 1);

        if (! $step) {
            return;
        }

        $adults = app(\App\Services\Recipes\AdultSteps::class);
        $adults->correct($step, ! $adults->needsAdult($step));
        $this->recipe->load('steps');
        unset($this->steps);
    }

    public function changeServings(int $delta): void
    {
        $this->servings = \App\Services\Planning\Appetites::clamp(max(0.5, $this->servings + $delta));
        unset($this->lines, $this->steps);
    }

    /* ================================================================ Fin */

    public function markCooked(WeekPlanner $planner): void
    {
        $meal = $this->meal;

        if (! $meal || $meal->cooked_at) {
            return;
        }

        $planner->toggleCooked($meal);
        unset($this->meal);
        $this->dispatch('meal-cooked', mealId: $meal->id, cooked: true)->to(\App\Livewire\Stock\MealStockDialog::class);
        $this->dispatch('notify', message: 'Repas marqué comme mangé.');
    }

    public function saveNote(): void
    {
        $this->validate(['note' => 'required|string|max:500'], [], ['note' => 'note']);

        RecipeCookNote::create([
            'recipe_id' => $this->recipe->id,
            'user_id' => auth()->id(),
            'planned_meal_id' => $this->mealId ?: null,
            'note' => trim($this->note),
            'servings' => $this->servings,
        ]);

        $this->reset('note');
        $this->saved = true;
        $this->dispatch('notify', message: 'Note de cuisine enregistrée.');
    }

    /* ================================================================ Données */

    #[Computed]
    public function meal(): ?PlannedMeal
    {
        return $this->mealId ? PlannedMeal::with('slot')->find($this->mealId) : null;
    }

    /**
     * Ingrédients mis à l'échelle, avec le numéro des étapes qui les citent.
     *
     * @return Collection<int, array{id: int, ingredient_id: int|null, name: string, quantity: string, preparation: string|null, optional: bool, group: string, steps: list<int>}>
     */
    #[Computed]
    public function lines(): Collection
    {
        $scaler = app(QuantityScaler::class);
        $formatter = app(IngredientLineFormatter::class);
        $instructions = $this->recipe->steps->map(fn ($step) => NameNormalizer::normalize($step->instruction));

        // Les sous-recettes (13.8) sont dépliées, groupées sous leur nom.
        $this->recipe->unsetRelation('ingredients');

        return app(\App\Services\Recipes\SubRecipes::class)->lines($this->recipe)->values()->map(function ($line, $index) use ($scaler, $formatter, $instructions) {
            $parts = $formatter->format($scaler->scale($line->quantity, $this->recipe->servings, $this->servings), $line->unit, $line->ingredient);
            $needle = $line->ingredient?->search_name;

            return [
                'id' => $line->id ?? 'sub-'.$index,
                'ingredient_id' => $line->ingredient_id,
                'name' => $parts['name'],
                'ingredient_name' => (string) $line->ingredient?->name,
                'quantity' => $parts['quantity'],
                'preparation' => $line->preparation,
                'optional' => (bool) $line->is_optional,
                'group' => $line->via_recipe ? 'Pour : '.$line->via_recipe : (string) $line->group_name,
                'steps' => $needle ? $instructions->filter(fn (string $text) => str_contains($text, $needle))->keys()->map(fn ($i) => $i + 1)->values()->all() : [],
            ];
        });
    }

    /**
     * Étapes avec leurs minuteurs (13.5) et les ingrédients cités.
     *
     * @return Collection<int, array{number: int, group: string|null, text: string, timers: list<array{minutes: int, label: string}>, lines: Collection}>
     */
    #[Computed]
    public function steps(): Collection
    {
        $timers = app(StepTimers::class);
        $adults = app(\App\Services\Recipes\AdultSteps::class);
        // Notes du foyer (40.2) : elles suivent l'étape, pas son numéro.
        $notes = app(\App\Services\Recipes\StepNotes::class)->byStep($this->recipe);
        // Photos d'étapes (31.2) : montrées au bon moment.
        $photos = app(\App\Services\Recipes\RecipePhotos::class)->byStep($this->recipe);

        return $this->recipe->steps->values()->map(fn ($step, $i) => [
            'photos' => $photos->get($i + 1, collect()),
            'number' => $i + 1,
            'group' => $step->group_name,
            'text' => $step->instruction,
            'id' => $step->id,
            'notes' => $notes->get($i + 1, collect()),
            // Lot 39 (39.4) : avec un enfant, les étapes pour un adulte sont signalées.
            'adult' => $this->recipe->kid_friendly && $adults->needsAdult($step),
            'adult_reason' => $this->recipe->kid_friendly ? ($step->adult_help === null ? $adults->reason($step->instruction) : null) : null,
            'adult_corrected' => $step->adult_help !== null,
            'timers' => $timers->extract($step->instruction),
            'lines' => $this->lines->filter(fn (array $line) => in_array($i + 1, $line['steps'], true))->values(),
        ]);
    }

    #[Computed]
    public function notes(): Collection
    {
        return $this->recipe->cookNotes()->with('user')->limit(3)->get();
    }

    #[Computed]
    public function assistantAvailable(): bool
    {
        return (bool) auth()->user()?->canEdit() && app(\App\Services\Assistant\AssistantService::class)->available();
    }

    public function lastStep(): int
    {
        return $this->recipe->steps->count() + 1;
    }

    public function render()
    {
        return view('livewire.recipes.cook')->title('Cuisiner · '.$this->recipe->title);
    }
}
