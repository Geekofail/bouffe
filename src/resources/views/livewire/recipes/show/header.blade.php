{{-- Fiche recette : en-tête — photo, titre, temps, saison, coût, actions (lot 36). --}}
{{-- ============================================================ En-tête --}}
<div class="card overflow-hidden">
    <div class="grid md:grid-cols-5">
        @if ($recipe->photo_path)
            <div class="md:col-span-2">
                <img src="{{ $recipe->photoUrl('large') }}" alt="{{ $recipe->title }}" class="aspect-[4/3] size-full object-cover md:aspect-auto">
            </div>
        @endif

        <div @class(['flex flex-col gap-4 p-5 sm:p-6', 'md:col-span-3' => $recipe->photo_path, 'md:col-span-5' => ! $recipe->photo_path])>
            <div class="flex items-start gap-3">
                @unless ($recipe->photo_path)
                    <x-dish-illustration :recipe="$recipe" class="size-14 rounded-2xl sm:size-16" />
                @endunless
                <h1 class="page-title flex-1 self-center">{{ $recipe->title }}</h1>
                @unless ($foreign)
                <button type="button" wire:click="toggleFavorite" class="rounded-full p-2 hover:bg-brand-50"
                        title="{{ $recipe->is_favorite ? 'Retirer des favoris' : 'Ajouter aux favoris' }}">
                    <x-icon :name="$recipe->is_favorite ? 'heart-solid' : 'heart'" @class(['size-7', 'text-brand-600' => $recipe->is_favorite, 'text-stone-400' => ! $recipe->is_favorite]) />
                    <span class="sr-only">Favori</span>
                </button>
                @endunless
            </div>

            @if ($recipe->kid_friendly)
                <p class="flex items-center gap-2 rounded-lg bg-herb-50 px-3 py-2 text-sm text-herb-900">
                    <x-icon name="smile" class="size-4" /> Facile avec un enfant : le mode cuisine signale les étapes pour un adulte.
                </p>
            @endif

            @if ($recipe->is_to_test)
                <div class="flex flex-wrap items-center gap-2 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-900">
                    <x-icon name="sparkles" class="size-4" />
                    <span class="flex-1">À tester — jamais encore cuisinée.</span>
                    @if ($recipe->source && \Illuminate\Support\Str::startsWith($recipe->source, ['http://', 'https://']))
                        <a href="{{ $recipe->source }}" target="_blank" rel="noopener noreferrer" class="font-semibold underline">Voir la page d'origine</a>
                    @endif
                </div>
            @endif

            @if ($recipe->tags->isNotEmpty())
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($recipe->tags as $tag)
                        <a href="{{ route('recipes.index', ['categories' => [$tag->id]]) }}" wire:navigate>
                            <x-badge :color="$tag->color" class="text-sm">{{ $tag->name }}</x-badge>
                        </a>
                    @endforeach
                </div>
            @endif

            @if ($recipe->description)
                <p class="text-stone-600">{{ $recipe->description }}</p>
            @endif

            {{-- Lot 37 (37.5) : sur téléphone, les temps tiennent sur une ligne ; les ingrédients arrivent plus vite. --}}
            <p class="flex flex-wrap gap-x-3 gap-y-1 text-sm text-stone-600 sm:hidden" data-recipe-facts>
                @foreach ([['Prép.', $recipe->prep_minutes], ['Cuisson', $recipe->cook_minutes], ['Repos', $recipe->rest_minutes], ['Total', $recipe->total_minutes]] as [$label, $minutes])
                    @if ($minutes)
                        <span class="inline-flex items-center gap-1 rounded-full bg-stone-100 px-2.5 py-1"><span class="text-stone-500">{{ $label }}</span> <strong class="font-semibold text-stone-800">{{ \App\Support\Duration::format($minutes) }}</strong></span>
                    @endif
                @endforeach
                @if ($recipe->difficulty)
                    <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-1 font-semibold text-stone-800">{{ $recipe->difficulty->label() }}</span>
                @endif
                @if ($this->season['status'] !== 'neutral')
                    <span @class(['inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-semibold', 'bg-herb-50 text-herb-800' => $this->season['status'] === 'season', 'bg-amber-50 text-amber-800' => $this->season['status'] === 'off'])>
                        <x-icon name="leaf" class="size-3.5" /> {{ $this->season['status'] === 'season' ? 'De saison' : 'Hors saison' }}
                    </span>
                @endif
                @if ($this->cost->isKnown())
                    <span class="inline-flex items-center gap-1 rounded-full bg-stone-100 px-2.5 py-1"><strong class="font-semibold text-stone-800">{{ $this->cost->label($prices, $this->cost->perServing()) }}</strong> <span class="text-stone-500">/ portion</span></span>
                @endif
            </p>

            <dl class="hidden grid-cols-2 gap-3 sm:grid sm:grid-cols-4">
                @foreach ([
                    ['Préparation', $recipe->prep_minutes],
                    ['Cuisson', $recipe->cook_minutes],
                    ['Repos', $recipe->rest_minutes],
                    ['Total', $recipe->total_minutes],
                ] as [$label, $minutes])
                    @if ($minutes)
                        <div class="rounded-lg bg-stone-50 px-3 py-2">
                            <dt class="text-xs text-stone-500">{{ $label }}</dt>
                            <dd class="font-semibold text-stone-800">{{ \App\Support\Duration::format($minutes) }}</dd>
                        </div>
                    @endif
                @endforeach
                @if ($recipe->difficulty)
                    <div class="rounded-lg bg-stone-50 px-3 py-2">
                        <dt class="text-xs text-stone-500">Difficulté</dt>
                        <dd class="font-semibold text-stone-800">{{ $recipe->difficulty->label() }}</dd>
                    </div>
                @endif

                {{-- Saison (17.1, R18) : évaluée au mois du repas quand la recette est ouverte depuis le planning. --}}
                @if ($this->season['status'] !== 'neutral')
                    <div @class(['rounded-lg px-3 py-2', 'bg-herb-50' => $this->season['status'] === 'season', 'bg-amber-50' => $this->season['status'] === 'off'])>
                        <dt class="text-xs text-stone-500">Saison</dt>
                        <dd @class(['flex items-center gap-1 font-semibold', 'text-herb-800' => $this->season['status'] === 'season', 'text-amber-800' => $this->season['status'] === 'off'])
                            title="{{ app(\App\Services\Seasons\SeasonCalendar::class)->explain($this->season) ?? 'Tous les fruits et légumes sont de saison.' }}">
                            <x-icon name="leaf" class="size-4" />
                            {{ $this->season['status'] === 'season' ? 'De saison' : 'Hors saison' }}
                        </dd>
                    </div>
                @endif

                {{-- Coût par portion (17.2) : un minimum tant que des ingrédients n'ont pas de prix. --}}
                @if ($this->cost->isKnown())
                    <div class="rounded-lg bg-stone-50 px-3 py-2">
                        <dt class="text-xs text-stone-500">Coût / portion</dt>
                        <dd class="font-semibold text-stone-800" title="{{ $this->cost->missingLabel() ?? 'Tous les ingrédients ont un prix' }}">
                            {{ $this->cost->label($prices, $this->cost->perServing()) }}
                        </dd>
                    </div>
                @endif
            </dl>

            {{-- Collections (31.1). --}}
            @if ($this->collections->isNotEmpty())
                <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-stone-500">
                    <x-icon name="squares" class="size-4" /> Dans :
                    @foreach ($this->collections as $collection)
                        <a href="{{ route('recipes.collections.show', $collection->id) }}" wire:navigate wire:key="in-collection-{{ $collection->id }}" class="font-medium text-brand-700 hover:underline">{{ $collection->name }}</a>@unless ($loop->last)<span aria-hidden="true">·</span>@endunless
                    @endforeach
                </p>
            @endif

            @php $stats = $this->planningStats; @endphp
            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-stone-500">
                <x-icon name="calendar" class="size-4" />
                @if ($stats['count'] === 0 && ! $stats['next'])
                    Jamais planifiée
                @else
                    @if ($stats['count'] > 0)
                        <span>Mangée {{ $stats['count'] }} fois, dernière fois le {{ $stats['last']->date->locale('fr')->isoFormat('D MMMM YYYY') }}</span>
                    @endif
                    @if ($stats['next'])
                        <a href="{{ route('planner.week', ['semaine' => $stats['next']->date->copy()->startOfWeek()->toDateString()]) }}" wire:navigate
                           class="font-medium text-brand-700 hover:underline">
                            Prévue {{ $stats['next']->date->locale('fr')->isoFormat('dddd D MMMM') }} ({{ mb_strtolower($stats['next']->slot->name) }})
                        </a>
                    @endif
                @endif
            </p>

            {{-- Lot 37 (37.5) : sur téléphone, « Cuisiner », « Planifier » et un menu « Plus ». --}}
            <div class="mt-auto flex gap-2 pt-1 sm:hidden" data-recipe-phone-actions>
                @unless ($recipe->isArchived())
                    <a href="{{ route('recipes.cook', ['recipe' => $recipe, 'portions' => $servings, 'repas' => $mealId ?: null]) }}" wire:navigate class="btn btn-primary min-h-11 flex-1">
                        <x-icon name="fire" class="size-4" /> Cuisiner
                    </a>
                    <button type="button" wire:click="openPlan" class="btn btn-secondary min-h-11 flex-1">
                        <x-icon name="calendar" class="size-4" /> Planifier
                    </button>
                @endunless
                <x-action-sheet label="Plus" :title="$recipe->title" class="flex-1" button-class="btn btn-secondary min-h-11 w-full">
                    @unless ($recipe->isArchived() || $foreign)
                        <button type="button" wire:click="{{ $this->isWished ? 'removeFromWishes' : 'addToWishes' }}" x-on:click="open = false" class="menu-item">
                            <x-icon :name="$this->isWished ? 'star-solid' : 'star'" class="size-5 text-stone-400" /> {{ $this->isWished ? 'Retirer des envies' : 'Ajouter aux envies' }}
                        </button>
                    @endunless
                    <a href="{{ route('recipes.print', ['recipe' => $recipe, 'portions' => $servings]) }}" target="_blank" class="menu-item"><x-icon name="printer" class="size-5 text-stone-400" /> Imprimer</a>
                    @if (auth()->user()->canEdit() && ! $foreign)
                        <a href="{{ route('recipes.edit', $recipe) }}" wire:navigate class="menu-item"><x-icon name="edit" class="size-5 text-stone-400" /> Modifier</a>
                        <button type="button" wire:click="duplicate" x-on:click="open = false" class="menu-item"><x-icon name="duplicate" class="size-5 text-stone-400" /> Dupliquer</button>
                        <a href="{{ route('recipes.variants', $recipe) }}" wire:navigate class="menu-item">
                            <x-icon name="shuffle" class="size-5 text-stone-400" /> Variantes
                            @if ($this->variants->isNotEmpty()) <span class="ml-auto text-xs text-stone-500 tabular-nums">{{ $this->variants->count() }}</span> @endif
                        </a>
                        <button type="button" x-on:click="open = false; $dispatch('open-recipe-collections')" class="menu-item"><x-icon name="squares" class="size-5 text-stone-400" /> Collections…</button>
                        <button type="button" x-on:click="open = false; $dispatch('open-recipe-share')" class="menu-item"><x-icon name="link" class="size-5 text-stone-400" /> Partager un lien…</button>
                    @endif
                    @if ($this->assistantAvailable && ! $foreign)
                        <button type="button" x-on:click="open = false; $dispatch('open-recipe-assistant')" class="menu-item"><x-icon name="sparkles" class="size-5 text-violet-500" /> Adapter avec l'assistant…</button>
                    @endif
                    @unless ($foreign)
                        <a href="{{ route('recipes.history', $recipe) }}" wire:navigate class="menu-item"><x-icon name="clock" class="size-5 text-stone-400" /> Historique</a>
                        <div class="my-1 border-t border-stone-100"></div>
                        <button type="button" wire:click="toggleArchive" x-on:click="open = false" class="menu-item">
                            <x-icon :name="$recipe->isArchived() ? 'unarchive' : 'archive'" class="size-5 text-stone-400" /> {{ $recipe->isArchived() ? 'Désarchiver' : 'Archiver' }}
                        </button>
                        <button type="button" wire:click="delete" wire:confirm="Supprimer définitivement la recette « {{ $recipe->title }} » ?" x-on:click="open = false" class="menu-item menu-item-danger">
                            <x-icon name="delete" class="size-5" /> Supprimer
                        </button>
                    @endunless
                </x-action-sheet>
            </div>

            <div class="mt-auto hidden flex-wrap gap-2 pt-2 sm:flex">
                @unless ($recipe->isArchived())
                    <a href="{{ route('recipes.cook', ['recipe' => $recipe, 'portions' => $servings, 'repas' => $mealId ?: null]) }}" wire:navigate class="btn btn-primary">
                        <x-icon name="fire" class="size-4" /> Cuisiner
                    </a>
                    <button type="button" wire:click="openPlan" class="btn btn-secondary">
                        <x-icon name="calendar" class="size-4" /> Planifier
                    </button>
                    @unless ($foreign)
                    <button type="button" wire:click="{{ $this->isWished ? 'removeFromWishes' : 'addToWishes' }}"
                            @class(['btn', 'btn-secondary' => ! $this->isWished, 'btn-primary' => $this->isWished])
                            title="Envies à planifier bientôt">
                        <x-icon :name="$this->isWished ? 'star-solid' : 'star'" class="size-4" />
                        {{ $this->isWished ? 'Dans les envies' : 'Envie' }}
                    </button>
                    @endunless
                @endunless
                <a href="{{ route('recipes.print', ['recipe' => $recipe, 'portions' => $servings]) }}" target="_blank" class="btn btn-secondary">
                    <x-icon name="printer" class="size-4" /> Imprimer
                </a>
                @if (auth()->user()->canEdit() && ! $foreign)
                    <a href="{{ route('recipes.edit', $recipe) }}" wire:navigate class="btn btn-secondary">
                        <x-icon name="edit" class="size-4" /> Modifier
                    </a>
                    <button type="button" wire:click="duplicate" class="btn btn-secondary">
                        <x-icon name="duplicate" class="size-4" /> Dupliquer
                    </button>
                    {{-- Variantes (13.7) : plutôt que dupliquer la recette pour un seul ingrédient. --}}
                    <a href="{{ route('recipes.variants', $recipe) }}" wire:navigate class="btn btn-secondary">
                        <x-icon name="shuffle" class="size-4" /> Variantes
                        @if ($this->variants->isNotEmpty()) <span class="text-xs text-stone-500">({{ $this->variants->count() }})</span> @endif
                    </a>
                @endif
                {{-- Lot 31 : collections, lien de partage, historique. --}}
                @unless ($foreign)
                    <x-action-sheet label="Plus" title="Recette">
                        @if (auth()->user()->canEdit())
                            <button type="button" x-on:click="open = false; $dispatch('open-recipe-collections')" class="menu-item"><x-icon name="squares" class="size-5 text-stone-400" /> Collections…</button>
                            <button type="button" x-on:click="open = false; $dispatch('open-recipe-share')" class="menu-item"><x-icon name="link" class="size-5 text-stone-400" /> Partager un lien…</button>
                        @endif
                        @if ($this->assistantAvailable)
                            <button type="button" x-on:click="open = false; $dispatch('open-recipe-assistant')" class="menu-item"><x-icon name="sparkles" class="size-5 text-violet-500" /> Adapter avec l'assistant…</button>
                        @endif
                        <a href="{{ route('recipes.history', $recipe) }}" wire:navigate class="menu-item"><x-icon name="clock" class="size-5 text-stone-400" /> Historique</a>
                    </x-action-sheet>
                @endunless
                @unless ($foreign)
                <button type="button" wire:click="toggleArchive" class="btn btn-secondary">
                    <x-icon :name="$recipe->isArchived() ? 'unarchive' : 'archive'" class="size-4" />
                    {{ $recipe->isArchived() ? 'Désarchiver' : 'Archiver' }}
                </button>
                <button type="button" wire:click="delete" wire:confirm="Supprimer définitivement la recette « {{ $recipe->title }} » ?"
                        class="btn btn-ghost text-red-600 hover:bg-red-50">
                    <x-icon name="delete" class="size-4" /> Supprimer
                </button>
                @endunless
            </div>
        </div>
    </div>
</div>
