<?php

namespace App\Livewire\Planner;

use App\Models\MealOccasion;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\Tag;
use App\Services\Planning\GuestCompatibility;
use App\Services\Planning\OccasionService;
use App\Services\Planning\WeekPlanner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Fenêtre « Ajouter un repas » dans une case du planning :
 * recette (recherche + suggestions), restes disponibles ou repas libre.
 */
class MealPicker extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;

    public const FREE_SHORTCUTS = ['Restaurant', 'Chez la famille', 'Chez des amis', 'Pizza', 'Sandwich', 'Rien de prévu'];

    public bool $show = false;

    public string $date = '';

    public ?int $slotId = null;

    public string $tab = 'recipe';

    public string $search = '';

    /** @var list<int> */
    public array $tagIds = [];

    public int|float|string $servings = 2;

    public string $freeText = '';

    /** Restes emportés en gamelle par une personne du foyer (32.3) ; vide : pour toute la table. */
    public string $lunchboxFor = '';

    /** Masquer les recettes en conflit avec les contraintes des invités. */
    public bool $compatibleOnly = false;

    /** Recettes déjà servies à ces invités en fin de liste. */
    public bool $neverServedFirst = false;

    /**
     * « Idées pour cette case » (lot 37, 37.4) : trois propositions du remplissage automatique.
     *
     * @var list<array<string, mixed>>
     */
    public array $ideas = [];

    /** Recettes déjà proposées (« Autre idée » en tire d'autres). @var list<int> */
    public array $ideasSeen = [];

    #[On('open-meal-picker')]
    public function open(string $date, int $slotId): void
    {
        $this->resetErrorBag();
        $this->reset('search', 'tagIds', 'freeText', 'compatibleOnly', 'neverServedFirst', 'lunchboxFor', 'ideas', 'ideasSeen');
        $this->date = Carbon::parse($date)->toDateString();
        $this->slotId = $slotId;
        $this->servings = app(OccasionService::class)->servingsAt($this->date, $slotId);
        unset($this->occasion);
        $this->tab = 'recipe';
        $this->show = true;

        if (auth()->user()->canEdit()) {
            $this->loadIdeas();
        }
    }

    /** « Autre idée » : trois autres propositions ; quand tout a été vu, on recommence. */
    public function otherIdeas(): void
    {
        $this->loadIdeas();
    }

    private function loadIdeas(): void
    {
        if (! $this->slotId) {
            return;
        }

        $filler = app(\App\Services\Planning\WeekFiller::class);
        $ideas = $filler->ideasFor($this->date, $this->slotId, 3, $this->ideasSeen);

        if ($ideas === [] && $this->ideasSeen !== []) {
            $this->ideasSeen = [];
            $ideas = $filler->ideasFor($this->date, $this->slotId, 3);
        }

        $this->ideas = $ideas;
        $this->ideasSeen = array_values(array_unique([...$this->ideasSeen, ...array_column($ideas, 'recipe_id')]));
    }

    public function close(): void
    {
        $this->show = false;
    }

    public function toggleTag(int $tagId): void
    {
        $this->tagIds = in_array($tagId, $this->tagIds, true)
            ? array_values(array_diff($this->tagIds, [$tagId]))
            : [...$this->tagIds, $tagId];
    }

    public function pickRecipe(int $recipeId): void
    {
        $this->validate(['servings' => 'required|numeric|min:0.5|max:50'], [], ['servings' => 'portions']);

        $this->plan(fn (WeekPlanner $planner) => $planner->addRecipe($this->date, $this->slotId, Recipe::active()->findOrFail($recipeId), \App\Services\Planning\Appetites::clamp($this->servings)));
    }

    public function pickLeftover(int $mealId): void
    {
        $forPersonId = $this->lunchboxFor !== '' ? (int) $this->lunchboxFor : null;

        $this->plan(fn (WeekPlanner $planner) => $planner->addLeftover($this->date, $this->slotId, PlannedMeal::findOrFail($mealId), null, $forPersonId));
    }

    /** Place une envie (14.5) : une recette du carnet, ou une idée écrite qui devient un repas libre. */
    public function pickWish(int $wishId): void
    {
        $wish = \App\Models\Wish::query()->open()->with('recipe')->findOrFail($wishId);

        $this->plan(function (WeekPlanner $planner) use ($wish) {
            $meal = $wish->isRecipe()
                ? $planner->addRecipe($this->date, $this->slotId, $wish->recipe, \App\Services\Planning\Appetites::clamp($this->servings))
                : $planner->addFree($this->date, $this->slotId, (string) $wish->text);

            $wish->update(['planned_meal_id' => $meal->id, 'planned_at' => now()]);

            return $meal;
        });
    }

    public function pickFree(?string $text = null): void
    {
        $text ??= $this->freeText;
        $this->freeText = $text;

        $this->validate(['freeText' => 'required|string|max:200'], [], ['freeText' => 'repas']);

        $this->plan(fn (WeekPlanner $planner) => $planner->addFree($this->date, $this->slotId, $text));
    }

    private function plan(callable $create): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        try {
            /** @var PlannedMeal $meal */
            $meal = $create(app(WeekPlanner::class));
        } catch (InvalidArgumentException $e) {
            $this->addError('picker', $e->getMessage());

            return;
        }

        $this->show = false;
        $this->dispatch('meal-planned', mealId: $meal->id);
    }

    /* ---------------------------------------------------------------- Données */

    #[Computed]
    public function slot(): ?MealSlot
    {
        return $this->slotId ? MealSlot::find($this->slotId) : null;
    }

    #[Computed]
    public function occasion(): ?MealOccasion
    {
        return $this->show && $this->slotId ? app(OccasionService::class)->find($this->date, $this->slotId) : null;
    }

    /** @return Collection<int, \App\Models\Guest> */
    public function guests(): Collection
    {
        return $this->occasion?->guests ?? collect();
    }

    public function changeOccasion(): void
    {
        $this->show = false;
        $this->dispatch('open-occasion', date: $this->date, slotId: (int) $this->slotId)->to(OccasionEditor::class);
    }

    /**
     * Conflits et « déjà servi » de chaque recette affichée, pour les invités du repas.
     *
     * @return array<int, array{conflicts: list<array>, level: string|null, served: list<array{guest: string, date: Carbon}>}>
     */
    #[Computed]
    public function guestInfo(): array
    {
        // Membres du foyer présents (18.1) et invités (lot 6) : mêmes alertes pour tout le monde.
        $guests = app(\App\Services\Planning\HouseholdService::class)->eaters($this->occasion(), $this->date ?: null, $this->slotId);

        if ($guests->isEmpty()) {
            return [];
        }

        // suggestions() renvoie une collection ordonnée en mémoire : on repasse par une collection Eloquent.
        $recipes = \Illuminate\Database\Eloquent\Collection::make($this->baseRecipes->all())->loadMissing('ingredients', 'tags');

        if ($recipes->isEmpty()) {
            return [];
        }
        $compatibility = app(GuestCompatibility::class);
        $guestIds = $guests->filter(fn ($eater) => $eater instanceof \App\Models\Guest)->pluck('id')->all();
        $served = $compatibility->servedBefore($recipes->pluck('id')->all(), $guestIds, Carbon::parse($this->date));

        return $recipes->mapWithKeys(function (Recipe $recipe) use ($compatibility, $guests, $served) {
            $conflicts = $compatibility->conflicts($recipe, $guests);

            return [$recipe->id => [
                'conflicts' => $conflicts,
                'level' => $compatibility->worstLevel($conflicts),
                'served' => $served[$recipe->id] ?? [],
            ]];
        })->all();
    }

    #[Computed]
    public function tags(): Collection
    {
        return Tag::query()->ordered()->whereHas('recipes', fn ($q) => $q->whereNull('archived_at'))->get();
    }

    /** Recettes affichées, après filtres et tri liés aux invités. */
    /** Lot 40 (40.3) : recettes qui demandent ce que la cuisine n'a pas. @return array<int, string> */
    #[Computed]
    public function missingEquipment(): array
    {
        $equipment = app(\App\Services\Recipes\KitchenEquipment::class);
        $recipes = \Illuminate\Database\Eloquent\Collection::make($this->baseRecipes->all())->loadMissing('steps');

        return $recipes->mapWithKeys(fn (Recipe $recipe) => [$recipe->id => $equipment->labels($equipment->missing($recipe))])
            ->filter()->all();
    }

    #[Computed]
    public function recipes(): Collection
    {
        $recipes = $this->baseRecipes;
        $info = $this->guestInfo;

        if ($info === []) {
            return $recipes;
        }

        if ($this->compatibleOnly) {
            $recipes = $recipes->filter(fn (Recipe $r) => ($info[$r->id]['level'] ?? null) === null);
        }

        if ($this->neverServedFirst) {
            $recipes = $recipes->sortBy(fn (Recipe $r) => ($info[$r->id]['served'] ?? []) === [] ? 0 : 1);
        }

        return $recipes->values();
    }

    /** Résultats de recherche, ou suggestions si aucun critère. */
    #[Computed]
    public function baseRecipes(): Collection
    {
        if (! $this->show) {
            return collect();
        }

        $planner = app(WeekPlanner::class);

        if (trim($this->search) === '') {
            return $planner->suggestions($planner->weekStart($this->date), 12, $this->tagIds);
        }

        $query = Recipe::query()->active()->search($this->search)->with('tags')
            ->withMax('plannedMeals as last_planned_on', 'date')
            ->orderBy('search_title');

        foreach ($this->tagIds as $tagId) {
            $query->whereHas('tags', fn ($q) => $q->whereKey($tagId));
        }

        return $query->limit(30)->get();
    }

    /**
     * Onglet « Avec mon stock » (10.1) : faisables puis presque, pour les portions de la case.
     *
     * @return Collection<int, array>
     */
    #[Computed]
    public function stockSuggestions(): Collection
    {
        if (! $this->show || $this->tab !== 'stock') {
            return collect();
        }

        $results = app(\App\Services\Stock\RecipeSuggester::class)->suggest([
            'servings' => \App\Services\Planning\Appetites::clamp((float) $this->servings ?: 2),
            'tagIds' => $this->tagIds,
        ]);

        return $results['feasible']->concat($results['almost'])->take(20)->values();
    }

    /** Envies encore à placer (14.5). @return Collection<int, \App\Models\Wish> */
    #[Computed]
    public function wishes(): Collection
    {
        return \App\Models\Wish::query()->open()->with('recipe', 'user')->latest('id')->get();
    }

    #[Computed]
    public function leftovers(): Collection
    {
        return $this->show && $this->date ? app(WeekPlanner::class)->availableLeftovers($this->date) : collect();
    }

    #[Computed]
    public function lunchboxPeople(): Collection
    {
        return app(\App\Services\Planning\Lunchboxes::class)->people();
    }

    public function render()
    {
        return view('livewire.planner.meal-picker');
    }
}
