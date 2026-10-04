<div>
    <a href="{{ route('recipes.collections') }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> Collections
    </a>

    @php $canEdit = ! $foreign && auth()->user()->canEdit(); @endphp

    <x-page-header :title="$collection->name" :subtitle="$foreign ? 'Collection de '.$collection->household?->name.' : les recettes qu\'ils partagent avec vous.' : ($collection->description ?: null)">
        <x-slot:actions>
            @if ($printUrl)
                <a href="{{ $printUrl }}" target="_blank" class="btn btn-secondary"><x-icon name="printer" class="size-4" /> Imprimer en carnet</a>
            @endif
            @if ($canEdit)
                <x-action-sheet label="Plus" title="Collection">
                    <button type="button" wire:click="edit" x-on:click="open = false" class="menu-item"><x-icon name="edit" class="size-5 text-stone-400" /> Renommer</button>
                    @if ($linkedCount > 0)
                        <button type="button" wire:click="openShare" x-on:click="open = false" class="menu-item"><x-icon name="share" class="size-5 text-stone-400" /> {{ $collection->isShared() ? 'Partage avec les proches' : 'Partager avec les proches' }}</button>
                    @endif
                    <div class="my-1 border-t border-stone-100"></div>
                    <button type="button" wire:click="delete" wire:confirm="Supprimer la collection « {{ $collection->name }} » ? Les recettes restent dans le carnet." x-on:click="open = false" class="menu-item menu-item-danger">
                        <x-icon name="delete" class="size-5" /> Supprimer la collection
                    </button>
                </x-action-sheet>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if (! $foreign && $collection->isShared())
        <p class="mb-4 flex items-center gap-2 rounded-lg bg-violet-50 px-3 py-2 text-sm text-violet-900">
            <x-icon name="share" class="size-4 shrink-0" />
            Partagée avec les foyers reliés.
            @if ($this->privateRecipes->isNotEmpty())
                {{ $this->privateRecipes->count() }} recette{{ $this->privateRecipes->count() > 1 ? 's' : '' }} privée{{ $this->privateRecipes->count() > 1 ? 's' : '' }} n'y {{ $this->privateRecipes->count() > 1 ? 'apparaissent' : 'apparaît' }} pas chez eux.
            @endif
        </p>
    @endif

    {{-- Ajouter des recettes --}}
    @if ($canEdit)
        <div class="card mb-5 p-4">
            <label for="collection-search" class="form-label">Ajouter une recette</label>
            <div class="relative">
                <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" />
                <input id="collection-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Titre ou ingrédient…" class="form-input pl-10" autocomplete="off">
            </div>
            @if ($this->candidates->isNotEmpty())
                <ul class="mt-2 divide-y divide-stone-100">
                    @foreach ($this->candidates as $recipe)
                        <li wire:key="candidate-{{ $recipe->id }}" class="flex items-center gap-3 py-2">
                            <span class="min-w-0 flex-1 truncate text-stone-900">{{ $recipe->title }}</span>
                            <button type="button" wire:click="add({{ $recipe->id }})" class="btn btn-secondary py-1 text-sm"><x-icon name="plus" class="size-4" /> Ajouter</button>
                        </li>
                    @endforeach
                </ul>
            @elseif (trim($search) !== '')
                <p class="mt-2 text-sm text-stone-500">Aucune autre recette ne correspond.</p>
            @endif
        </div>
    @endif

    @if ($this->recipes->isEmpty())
        <div class="card">
            <x-empty-state icon="recipes" title="Aucune recette dans cette collection">
                @if ($canEdit)
                    Cherchez une recette ci-dessus, ou ajoutez-la depuis sa fiche (menu « Plus › Collections »).
                @else
                    Rien de visible pour vous pour l'instant.
                @endif
            </x-empty-state>
        </div>
    @else
        <ol class="card divide-y divide-stone-100">
            @foreach ($this->recipes as $recipe)
                <li wire:key="collection-recipe-{{ $recipe->id }}" class="flex items-center gap-3 p-3">
                    @if ($recipe->photo_path)
                        <img src="{{ $recipe->photoUrl('thumb') }}" alt="" loading="lazy" class="size-12 shrink-0 rounded-lg object-cover">
                    @else
                        <x-dish-illustration :recipe="$recipe" class="size-12 rounded-lg" />
                    @endif
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('recipes.show', $recipe) }}" wire:navigate class="font-medium text-stone-900 hover:text-brand-700">{{ $recipe->title }}</a>
                        <p class="text-xs text-stone-500">
                            @if ($recipe->total_minutes) {{ \App\Support\Duration::format($recipe->total_minutes) }} @endif
                            @if ($recipe->tags->isNotEmpty()) · {{ $recipe->tags->take(2)->pluck('name')->join(', ') }} @endif
                            @if (! $foreign && $collection->isShared() && $recipe->visibility === 'private') · <span class="text-violet-800">privée</span> @endif
                        </p>
                    </div>
                    @if ($canEdit)
                        <div class="flex shrink-0 items-center">
                            <button type="button" wire:click="move({{ $recipe->id }}, -1)" class="btn btn-ghost px-2" @disabled($loop->first) title="Monter">
                                <x-icon name="chevron-up" class="size-4" /><span class="sr-only">Monter {{ $recipe->title }}</span>
                            </button>
                            <button type="button" wire:click="move({{ $recipe->id }}, 1)" class="btn btn-ghost px-2" @disabled($loop->last) title="Descendre">
                                <x-icon name="chevron-down" class="size-4" /><span class="sr-only">Descendre {{ $recipe->title }}</span>
                            </button>
                            <button type="button" wire:click="remove({{ $recipe->id }})" class="btn btn-ghost px-2 text-stone-500 hover:text-red-700" title="Retirer de la collection">
                                <x-icon name="close" class="size-4" /><span class="sr-only">Retirer {{ $recipe->title }} de la collection</span>
                            </button>
                        </div>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif

    <x-modal :show="$editing" title="Renommer la collection" close="closeForm">
        <form id="collection-edit-form" wire:submit="saveDetails" class="space-y-4">
            <x-field label="Nom" for="collection-edit-name" error="name">
                <input id="collection-edit-name" type="text" wire:model="name" maxlength="100" class="form-input">
            </x-field>
            <x-field label="Description" for="collection-edit-description" optional>
                <input id="collection-edit-description" type="text" wire:model="description" maxlength="500" class="form-input">
            </x-field>
        </form>
        <x-slot:footer>
            <button type="button" wire:click="closeForm" class="btn btn-ghost">Annuler</button>
            <button type="submit" form="collection-edit-form" class="btn btn-primary">Enregistrer</button>
        </x-slot:footer>
    </x-modal>

    <x-modal :show="$showShare" title="Partager avec les foyers reliés" close="closeShare">
        <div class="space-y-3 text-sm text-stone-700">
            <p>Les foyers reliés verront cette collection dans leurs collections, avec les recettes qu'ils peuvent déjà lire.</p>
            @if ($this->privateRecipes->isNotEmpty())
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-amber-900">
                    {{ $this->privateRecipes->count() }} recette{{ $this->privateRecipes->count() > 1 ? 's sont privées' : ' est privée' }} :
                    {{ $this->privateRecipes->pluck('title')->join(', ') }}. Elles n'apparaîtront pas chez eux, sauf si vous les ouvrez aussi.
                </p>
            @endif
        </div>
        <x-slot:footer>
            @if ($collection->isShared())
                <button type="button" wire:click="share(false, false)" class="btn btn-ghost">Ne plus partager</button>
            @else
                <button type="button" wire:click="closeShare" class="btn btn-ghost">Annuler</button>
            @endif
            @if ($this->privateRecipes->isNotEmpty())
                <button type="button" wire:click="share(true, false)" class="btn btn-secondary">{{ $collection->isShared() ? 'Garder ainsi' : 'Partager la collection seule' }}</button>
                <button type="button" wire:click="share(true, true)" class="btn btn-primary">Partager et ouvrir les recettes</button>
            @elseif (! $collection->isShared())
                <button type="button" wire:click="share(true, false)" class="btn btn-primary">Partager</button>
            @endif
        </x-slot:footer>
    </x-modal>
</div>
