<?php

namespace App\Livewire\Recipes;

use App\Enums\Difficulty;
use App\Models\Recipe;
use App\Models\Tag;
use App\Services\Pricing\CostCalculator;
use App\Services\Pricing\PriceBook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Recettes')]
class Index extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;
    use WithPagination;

    public const SORTS = [
        'recent' => 'Plus récentes',
        'alpha' => 'Ordre alphabétique',
        'rating' => 'Mieux notées',
        'quick' => 'Plus rapides',
        'forgotten' => 'Pas mangées depuis longtemps',
    ];

    public const MAX_TIMES = [15, 30, 45, 60, 90];

    /** Paliers proposés pour le filtre par prix (€ par portion). */
    public const MAX_COSTS = [2, 3, 5];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** @var list<int> */
    #[Url(as: 'categories', except: [])]
    public array $tagIds = [];

    #[Url(as: 'temps', except: null)]
    public ?int $maxMinutes = null;

    #[Url(as: 'difficulte', except: '')]
    public string $difficulty = '';

    #[Url(as: 'favoris', except: false)]
    public bool $favoritesOnly = false;

    #[Url(as: 'a_tester', except: false)]
    public bool $toTestOnly = false;

    /** Lot 39 (39.4) : « facile avec un enfant ». */
    #[Url(as: 'enfants', except: false)]
    public bool $kidsOnly = false;

    #[Url(as: 'archives', except: false)]
    public bool $showArchived = false;

    /** Filtre « de saison ce mois-ci » (17.1, R18). */
    #[Url(as: 'saison', except: false)]
    public bool $inSeasonOnly = false;

    /** Filtre « moins de N € par portion » (17.2). */
    #[Url(as: 'prix', except: null)]
    public ?float $maxCost = null;

    #[Url(as: 'tri', except: 'recent')]
    public string $sort = 'recent';

    /** « proches » : recettes des foyers reliés (26.1). */
    #[Url(as: 'source', except: '')]
    public string $source = '';

    /** Un seul foyer parmi les proches. */
    #[Url(as: 'foyer', except: null)]
    public ?int $householdFilter = null;

    public function showSource(string $source): void
    {
        $this->source = $source === 'proches' ? 'proches' : '';
        $this->reset('tagIds', 'favoritesOnly', 'toTestOnly', 'kidsOnly', 'showArchived', 'householdFilter');
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function toggleTag(int $tagId): void
    {
        $this->tagIds = in_array($tagId, $this->tagIds, true)
            ? array_values(array_diff($this->tagIds, [$tagId]))
            : [...$this->tagIds, $tagId];

        $this->resetPage();
    }

    public function toggleFavorite(int $recipeId): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $recipe = Recipe::findOrFail($recipeId);
        $recipe->timestamps = false;
        $recipe->update(['is_favorite' => ! $recipe->is_favorite]);
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'tagIds', 'maxMinutes', 'maxCost', 'inSeasonOnly', 'difficulty', 'favoritesOnly', 'toTestOnly', 'kidsOnly', 'showArchived');
        $this->resetPage();
    }

    #[Computed]
    public function tags(): Collection
    {
        return Tag::query()->ordered()->withCount(['recipes' => fn (Builder $q) => $q->whereNull('archived_at')])->get();
    }

    /** Lot 29 (29.4) : cartes (par défaut) ou liste compacte. */
    #[Url(as: 'affichage', except: 'cartes')]
    public string $layout = 'cartes';

    /** Lot 28 (28.4) : sur téléphone, les filtres sont repliés derrière un bouton « Filtres ». */
    public bool $showFilters = false;

    /** Nombre de filtres actifs (hors recherche et tri), pour le bouton « Filtres (3) ». */
    public function activeFilterCount(): int
    {
        return count($this->tagIds) + (int) (bool) $this->maxMinutes + (int) (bool) $this->maxCost + (int) $this->inSeasonOnly
            + (int) ($this->difficulty !== '') + (int) $this->favoritesOnly + (int) $this->toTestOnly + (int) $this->kidsOnly + (int) $this->showArchived;
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->tagIds !== [] || $this->maxMinutes || $this->maxCost || $this->inSeasonOnly
            || $this->difficulty !== '' || $this->favoritesOnly || $this->toTestOnly || $this->kidsOnly || $this->showArchived;
    }

    /**
     * Identifiants des recettes dont le coût par portion tient dans le budget demandé.
     * Une recette dont on ne connaît pas le coût n'est pas proposée : on ne devine pas.
     *
     * @return list<int>
     */
    private function affordableIds(Builder $query): array
    {
        $recipes = $query->limit(500)->with(['ingredients.ingredient.referencePriceUnit', 'ingredients.unit'])->get();
        $costs = app(CostCalculator::class)->forRecipes($recipes);

        return collect($costs)
            ->filter(fn ($cost) => $cost->isKnown() && $cost->perServing() !== null && $cost->perServing() <= $this->maxCost)
            ->keys()->map(fn ($id) => (int) $id)->values()->all();
    }

    public function render()
    {
        $shared = $this->source === 'proches';
        $sharedRecipes = app(\App\Services\Linked\SharedRecipes::class);

        // Recettes des proches : même recherche et mêmes filtres de temps, prix, saison et difficulté ;
        // les catégories, favoris et archives sont propres à chaque foyer (26.1).
        $query = ($shared ? $sharedRecipes->visibleQuery()->with('household') : Recipe::query())
            ->when($shared && $this->householdFilter, fn (Builder $q) => $q->where('recipes.household_id', $this->householdFilter))
            ->with('tags')
            ->withAvg('ratings', 'rating')
            ->withMax(['plannedMeals as last_planned_on' => fn (Builder $q) => $q->where('date', '<=', now()->toDateString())], 'date')
            ->when($this->showArchived && ! $shared, fn (Builder $q) => $q->archived(), fn (Builder $q) => $q->active())
            ->search($this->search)
            ->when($this->favoritesOnly, fn (Builder $q) => $q->where('is_favorite', true))
            ->when($this->toTestOnly, fn (Builder $q) => $q->where('is_to_test', true))
            ->when($this->kidsOnly, fn (Builder $q) => $q->where('kid_friendly', true))
            ->when(Difficulty::tryFrom($this->difficulty), fn (Builder $q, Difficulty $d) => $q->where('difficulty', $d->value))
            ->when($this->maxMinutes, fn (Builder $q) => $q->maxTotalMinutes($this->maxMinutes));

        // Toutes les catégories sélectionnées doivent être présentes (ET)
        foreach ($shared ? [] : $this->tagIds as $tagId) {
            $query->whereHas('tags', fn (Builder $q) => $q->whereKey($tagId));
        }

        // 17.2 : « moins de 3 € par portion ». Le coût ne se calcule pas en SQL (conversions
        // d'unités, prix de référence) : on chiffre les recettes candidates, puis on filtre.
        if ($this->maxCost) {
            $query->whereIn('recipes.id', $this->affordableIds($query->clone()));
        }

        // 17.1 : « de saison ce mois-ci ». Même principe : la saison dépend des ingrédients.
        if ($this->inSeasonOnly) {
            $candidates = $query->clone()->limit(500)->with(['ingredients.ingredient', 'ingredients.unit'])->get();
            $query->whereIn('recipes.id', app(\App\Services\Seasons\SeasonCalendar::class)->recipeIdsInSeason($candidates) ?: [0]);
        }

        match ($this->sort) {
            'alpha' => $query->orderBy('search_title'),
            'rating' => $query->orderByDesc('ratings_avg_rating')->orderBy('search_title'),
            'quick' => $query->orderByRaw('(COALESCE(prep_minutes, 9999) + COALESCE(cook_minutes, 0) + COALESCE(rest_minutes, 0))')->orderBy('search_title'),
            'forgotten' => $query->orderByRaw('CASE WHEN last_planned_on IS NULL THEN 0 ELSE 1 END')->orderBy('last_planned_on')->orderBy('search_title'),
            default => $query->latest()->orderByDesc('id'),
        };

        $recipes = $query->paginate(24);

        return view('livewire.recipes.index', [
            'recipes' => $recipes,
            'costs' => app(CostCalculator::class)->forRecipes($recipes->getCollection()),
            'seasons' => app(\App\Services\Seasons\SeasonCalendar::class)->forRecipes($recipes->getCollection()),
            'prices' => app(PriceBook::class),
            'total' => Recipe::active()->count(),
            // Lot 38 (38.3) : recettes reçues, à trier.
            'inboxCount' => app(\App\Services\Recipes\RecipeInbox::class)->openCount(),
            'archivedCount' => Recipe::archived()->count(),
            'toTestCount' => Recipe::active()->where('is_to_test', true)->count(),
            'kidsCount' => Recipe::active()->where('kid_friendly', true)->count(),
            'shared' => $shared,
            'sharedCount' => $sharedRecipes->count(),
            'sharedHouseholds' => $shared ? \App\Models\Household::query()->whereIn('id', $sharedRecipes->visibleQuery()->select('recipes.household_id'))->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }
}
