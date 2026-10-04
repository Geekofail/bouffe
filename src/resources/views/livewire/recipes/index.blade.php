<div>
    <x-page-header title="Recettes" :subtitle="$total.' recette'.($total > 1 ? 's' : '').' dans le carnet'">
        <x-slot:actions>
            {{-- Lot 29 : sur téléphone, « Nouvelle recette » seule ; le reste dans « Plus ». --}}
            @if ($inboxCount > 0)
                <a href="{{ route('recipes.inbox') }}" wire:navigate class="btn btn-secondary" data-inbox-link>
                    <x-icon name="download" class="size-4" /> À trier <span class="rounded-full bg-brand-600 px-1.5 text-xs text-white tabular-nums">{{ $inboxCount }}</span>
                </a>
            @endif
            <a href="{{ route('recipes.collections') }}" wire:navigate class="btn btn-secondary hidden sm:inline-flex">
                <x-icon name="squares" class="size-4" /> Collections
            </a>
            <a href="{{ route('suggestions') }}" wire:navigate class="btn btn-secondary hidden sm:inline-flex">
                <x-icon name="sparkles" class="size-4" /> Avec mon stock
            </a>
            @if (auth()->user()->canEdit())
                <a href="{{ route('recipes.import') }}" wire:navigate class="btn btn-secondary hidden sm:inline-flex">
                    <x-icon name="download" class="size-4" /> Importer
                </a>
                <a href="{{ route('recipes.create') }}" wire:navigate class="btn btn-primary">
                    <x-icon name="plus" class="size-4" /> Nouvelle recette
                </a>
            @endif
            <x-action-sheet label="Plus" title="Recettes" class="sm:hidden">
                <a href="{{ route('recipes.collections') }}" wire:navigate class="menu-item"><x-icon name="squares" class="size-5 text-stone-400" /> Collections</a>
                <a href="{{ route('suggestions') }}" wire:navigate class="menu-item"><x-icon name="sparkles" class="size-5 text-stone-400" /> Que cuisiner avec mon stock ?</a>
                @if (auth()->user()->canEdit())
                    <a href="{{ route('recipes.import') }}" wire:navigate class="menu-item"><x-icon name="download" class="size-5 text-stone-400" /> Importer une recette</a>
                @endif
                <a href="{{ route('recipes.inbox') }}" wire:navigate class="menu-item"><x-icon name="download" class="size-5 text-stone-400" /> À trier @if ($inboxCount > 0) <span class="ml-auto text-xs text-stone-500 tabular-nums">{{ $inboxCount }}</span> @endif</a>
                <a href="{{ route('recipes.export') }}" class="menu-item"><x-icon name="download" class="size-5 text-stone-400" /> Exporter le carnet</a>
            </x-action-sheet>
        </x-slot:actions>
    </x-page-header>

    {{-- Lot 38 (38.3) : un rappel discret quand la boîte déborde. --}}
    @if ($inboxCount > \App\Services\Recipes\RecipeInbox::REMIND_ABOVE)
        <a href="{{ route('recipes.inbox') }}" wire:navigate class="mb-4 flex items-center gap-3 rounded-xl bg-stone-100 px-4 py-2.5 text-sm text-stone-700 hover:bg-stone-200" data-inbox-reminder>
            <x-icon name="download" class="size-5 shrink-0 text-stone-500" />
            <span class="flex-1">{{ $inboxCount }} recettes attendent dans « À trier » : un petit tri ?</span>
            <x-icon name="chevron-right" class="size-4" />
        </a>
    @endif

    {{-- Mon carnet · Recettes des proches (26.1) ; cartes ou liste (lot 29, 29.4). --}}
    <div class="mb-4 flex items-center justify-between gap-3">
        @if ($sharedCount > 0 || $shared)
            <div class="flex w-fit max-w-full gap-1 overflow-x-auto rounded-xl bg-stone-100 p-1" role="tablist">
                <button type="button" wire:click="showSource('')" role="tab" aria-selected="{{ $shared ? 'false' : 'true' }}" @class(['min-h-9 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition', 'bg-white text-stone-900 shadow-sm' => ! $shared, 'text-stone-600 hover:text-stone-900' => $shared])>Notre carnet ({{ $total }})</button>
                <button type="button" wire:click="showSource('proches')" role="tab" aria-selected="{{ $shared ? 'true' : 'false' }}" @class(['min-h-9 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition', 'bg-white text-stone-900 shadow-sm' => $shared, 'text-stone-600 hover:text-stone-900' => ! $shared])><x-icon name="heart" class="inline size-4" /> Proches ({{ $sharedCount }})</button>
            </div>
        @else
            <span></span>
        @endif
        <div class="flex shrink-0 gap-0.5 rounded-xl bg-stone-100 p-1" role="group" aria-label="Affichage des recettes">
            <button type="button" wire:click="$set('layout', 'cartes')" aria-pressed="{{ $layout !== 'liste' ? 'true' : 'false' }}" title="En cartes"
                    @class(['flex size-9 items-center justify-center rounded-lg transition', 'bg-white text-stone-900 shadow-sm' => $layout !== 'liste', 'text-stone-600 hover:text-stone-900' => $layout === 'liste'])>
                <x-icon name="squares" class="size-5" /><span class="sr-only">En cartes</span>
            </button>
            <button type="button" wire:click="$set('layout', 'liste')" aria-pressed="{{ $layout === 'liste' ? 'true' : 'false' }}" title="En liste"
                    @class(['flex size-9 items-center justify-center rounded-lg transition', 'bg-white text-stone-900 shadow-sm' => $layout === 'liste', 'text-stone-600 hover:text-stone-900' => $layout !== 'liste'])>
                <x-icon name="list" class="size-5" /><span class="sr-only">En liste</span>
            </button>
        </div>
    </div>

    {{-- Recherche et filtres. Sur téléphone, les filtres sont repliés derrière « Filtres (n) » (lot 28, 28.4). --}}
    @php $activeFilters = $this->activeFilterCount(); @endphp
    <div class="mb-3 flex items-center gap-2 sm:hidden">
        <div class="relative min-w-0 flex-1">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Titre ou ingrédient…"
                   class="form-input pl-10" aria-label="Rechercher une recette">
        </div>
        <button type="button" wire:click="$toggle('showFilters')" aria-expanded="{{ $showFilters ? 'true' : 'false' }}" aria-controls="recipe-filters"
                @class(['btn shrink-0', 'btn-secondary' => ! $showFilters && $activeFilters === 0, 'bg-stone-900 text-white' => $showFilters || $activeFilters > 0])>
            <x-icon name="filter" class="size-4" /> Filtres{{ $activeFilters > 0 ? ' ('.$activeFilters.')' : '' }}
        </button>
    </div>
    @if ($activeFilters > 0 && ! $showFilters)
        <p class="-mt-1 mb-3 flex items-center gap-2 text-sm text-stone-600 sm:hidden">
            {{ $activeFilters }} filtre{{ $activeFilters > 1 ? 's' : '' }} actif{{ $activeFilters > 1 ? 's' : '' }}
            <button type="button" wire:click="clearFilters" class="font-medium text-brand-700 underline">Effacer</button>
        </p>
    @endif

    <div id="recipe-filters" @class(['max-sm:hidden' => ! $showFilters])>
    <div class="mb-3 grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center sm:gap-3">
        <div class="relative hidden min-w-56 flex-1 sm:block">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Titre ou ingrédient…"
                   class="form-input pl-10" aria-label="Rechercher une recette">
        </div>

        <select wire:model.live="maxMinutes" class="form-input sm:w-auto" aria-label="Temps maximum">
            <option value="">Tous les temps</option>
            @foreach (\App\Livewire\Recipes\Index::MAX_TIMES as $minutes)
                <option value="{{ $minutes }}">≤ {{ \App\Support\Duration::format($minutes) }}</option>
            @endforeach
        </select>

        <label class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm ring-1 ring-stone-200"
               title="Recettes dont tous les fruits et légumes sont de saison ce mois-ci">
            <input type="checkbox" wire:model.live="inSeasonOnly" class="form-checkbox">
            <span class="flex items-center gap-1 whitespace-nowrap text-stone-700"><x-icon name="leaf" class="size-4 text-herb-600" /> De saison</span>
        </label>

        <select wire:model.live="maxCost" class="form-input sm:w-auto" aria-label="Prix maximum par portion">
            <option value="">Tous les prix</option>
            @foreach (\App\Livewire\Recipes\Index::MAX_COSTS as $euros)
                <option value="{{ $euros }}">≤ {{ $euros }} € / portion</option>
            @endforeach
        </select>

        <select wire:model.live="difficulty" class="form-input sm:w-auto" aria-label="Difficulté">
            <option value="">Toutes difficultés</option>
            @foreach (\App\Enums\Difficulty::cases() as $d)
                <option value="{{ $d->value }}">{{ $d->label() }}</option>
            @endforeach
        </select>

        <select wire:model.live="sort" class="form-input sm:w-auto" aria-label="Trier">
            @foreach (\App\Livewire\Recipes\Index::SORTS as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    @if ($shared)
        <div class="mb-6 flex flex-wrap items-center gap-2">
            <button type="button" wire:click="$set('householdFilter', null)" @class(['rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset transition', 'bg-brand-600 text-white ring-brand-600' => ! $householdFilter, 'bg-white text-stone-600 ring-stone-200 hover:text-stone-900' => $householdFilter])>Tous les foyers</button>
            @foreach ($sharedHouseholds as $h)
                <button type="button" wire:key="hf-{{ $h->id }}" wire:click="$set('householdFilter', {{ $h->id }})" @class(['rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset transition', 'bg-brand-600 text-white ring-brand-600' => $householdFilter === $h->id, 'bg-white text-stone-600 ring-stone-200 hover:text-stone-900' => $householdFilter !== $h->id])>{{ $h->name }}</button>
            @endforeach
        </div>
    @else
    <div class="mb-6 flex flex-wrap items-center gap-2">
        <button type="button" wire:click="$toggle('favoritesOnly')" aria-pressed="{{ $favoritesOnly ? 'true' : 'false' }}"
                @class([
                    'inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset transition',
                    'bg-brand-600 text-white ring-brand-600' => $favoritesOnly,
                    'bg-white text-stone-600 ring-stone-200 hover:text-stone-900' => ! $favoritesOnly,
                ])>
            <x-icon :name="$favoritesOnly ? 'heart-solid' : 'heart'" class="size-4" /> Favoris
        </button>

        @if ($toTestCount > 0 || $toTestOnly)
            <button type="button" wire:click="$toggle('toTestOnly')" aria-pressed="{{ $toTestOnly ? 'true' : 'false' }}"
                    @class([
                        'inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset transition',
                        'bg-sky-600 text-white ring-sky-600' => $toTestOnly,
                        'bg-white text-stone-600 ring-stone-200 hover:text-stone-900' => ! $toTestOnly,
                    ])>
                <x-icon name="sparkles" class="size-4" /> À tester ({{ $toTestCount }})
            </button>
        @endif

        {{-- Lot 39 (39.4) --}}
        @if ($kidsCount > 0 || $kidsOnly)
            <button type="button" wire:click="$toggle('kidsOnly')" aria-pressed="{{ $kidsOnly ? 'true' : 'false' }}"
                    @class([
                        'inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset transition',
                        'bg-herb-600 text-white ring-herb-600' => $kidsOnly,
                        'bg-white text-stone-600 ring-stone-200 hover:text-stone-900' => ! $kidsOnly,
                    ])>
                <x-icon name="smile" class="size-4" /> Avec un enfant ({{ $kidsCount }})
            </button>
        @endif

        @foreach ($this->tags as $tag)
            @php $selected = in_array($tag->id, $tagIds, true); @endphp
            @continue(! $selected && $tag->recipes_count === 0)
            <button type="button" wire:click="toggleTag({{ $tag->id }})" wire:key="filter-tag-{{ $tag->id }}"
                    aria-pressed="{{ $selected ? 'true' : 'false' }}"
                    @class([
                        'rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset transition',
                        \App\Support\Palette::badge($tag->color) => $selected,
                        'bg-white text-stone-600 ring-stone-200 hover:text-stone-900' => ! $selected,
                    ])>
                {{ $tag->name }}
            </button>
        @endforeach

        @if ($archivedCount > 0 || $showArchived)
            <button type="button" wire:click="$toggle('showArchived')"
                    @class([
                        'ml-auto inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm font-medium transition',
                        'bg-stone-800 text-white' => $showArchived,
                        'text-stone-500 hover:text-stone-800' => ! $showArchived,
                    ])>
                <x-icon name="archive" class="size-4" /> Archivées ({{ $archivedCount }})
            </button>
        @endif
    </div>
    @endif
    </div>

    @if ($recipes->isEmpty())
        <div class="card">
            @if ($this->hasFilters())
                <x-empty-state icon="search" title="Aucune recette ne correspond">
                    <button type="button" wire:click="clearFilters" class="text-brand-700 underline">Effacer les filtres</button>
                </x-empty-state>
            @elseif ($shared)
                <x-empty-state icon="heart" title="Aucune recette partagée">
                    Vos proches n'ont encore ouvert aucune recette. <a href="{{ route('linked.index') }}" wire:navigate class="text-brand-700 underline">Foyers reliés</a>
                </x-empty-state>
            @else
                <x-empty-state dish="assiette" title="Le carnet est vide">
                    Saisissez une recette, ou importez-la depuis un site, un texte ou la photo d'une page de livre.
                    @if (auth()->user()->canEdit())
                        <x-slot:actions>
                            <a href="{{ route('recipes.import') }}" wire:navigate class="btn btn-secondary"><x-icon name="download" class="size-4" /> Importer une recette</a>
                            <a href="{{ route('recipes.create') }}" wire:navigate class="btn btn-primary"><x-icon name="plus" class="size-4" /> Nouvelle recette</a>
                        </x-slot:actions>
                    @endif
                </x-empty-state>
            @endif
        </div>
    @else
        @if ($layout === 'liste')
            {{-- Liste compacte (29.4) : utile à 50 recettes et plus. --}}
            <ul class="card divide-y divide-stone-100 overflow-hidden rounded-2xl"
                wire:loading.class="opacity-60" wire:target="search,toggleTag,maxMinutes,maxCost,inSeasonOnly,difficulty,sort,favoritesOnly,toTestOnly,showArchived">
                @foreach ($recipes as $recipe)
                    @php $cost = $costs[$recipe->id] ?? null; @endphp
                    <li wire:key="recipe-row-{{ $recipe->id }}">
                        <a href="{{ route('recipes.show', $recipe) }}" wire:navigate class="flex items-center gap-3 px-3 py-2.5 hover:bg-stone-50">
                            @if ($recipe->photo_path)
                                <img src="{{ $recipe->photoUrl('thumb') }}" alt="" loading="lazy" class="size-14 shrink-0 rounded-xl object-cover">
                            @else
                                <x-dish-illustration :recipe="$recipe" class="size-14 rounded-xl" />
                            @endif
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold text-stone-900">{{ $recipe->title }}</span>
                                <span class="block truncate text-sm text-stone-600">
                                    {{ collect([
                                        $recipe->total_minutes ? \App\Support\Duration::format($recipe->total_minutes) : null,
                                        $recipe->difficulty?->label(),
                                        $cost?->isKnown() && $cost->perServing() !== null ? $cost->label(app(\App\Services\Pricing\PriceBook::class), $cost->perServing()).' / pers.' : null,
                                        $recipe->isForeign() ? $recipe->household?->name : null,
                                    ])->filter()->join(' · ') }}
                                </span>
                            </span>
                            @if (($seasons[$recipe->id]['status'] ?? null) === 'season')
                                <span class="hidden shrink-0 items-center gap-1 rounded-full bg-herb-100 px-2 py-0.5 text-xs font-medium text-herb-800 sm:inline-flex"><x-icon name="leaf" class="size-3" /> De saison</span>
                            @endif
                            @if ($recipe->is_favorite)
                                <x-icon name="heart-solid" class="size-4 shrink-0 text-brand-600" />
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        @else
            <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3 xl:grid-cols-4"
                 wire:loading.class="opacity-60" wire:target="search,toggleTag,maxMinutes,maxCost,inSeasonOnly,difficulty,sort,favoritesOnly,toTestOnly,showArchived">
                @foreach ($recipes as $recipe)
                    <div wire:key="recipe-{{ $recipe->id }}" class="flex"><x-recipe.card :recipe="$recipe" :cost="$costs[$recipe->id] ?? null" :season="$seasons[$recipe->id] ?? null" class="w-full" /></div>
                @endforeach
            </div>
        @endif

        <div class="mt-6 flex justify-end">
            {{ $recipes->links('pagination.simple') }}
        </div>
    @endif
</div>
