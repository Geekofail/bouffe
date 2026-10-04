<div>
    <a href="{{ route('recipes.index') }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> Recettes
    </a>

    <x-page-header title="Collections" subtitle="Des regroupements libres, en plus des catégories : « Noël », « Recettes de mamie », « Quand Léo vient ».">
        <x-slot:actions>
            @if (auth()->user()->canEdit())
                <button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Nouvelle collection</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($mine->isEmpty())
        <div class="card mb-6">
            <x-empty-state icon="squares" title="Aucune collection pour l'instant">
                Créez une collection, puis ajoutez-y des recettes depuis leur fiche (menu « Plus › Collections »).
                Une recette peut être dans plusieurs collections.
            </x-empty-state>
        </div>
    @else
        <ul class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($mine as $collection)
                @php $recipes = $covers->get($collection->id, collect()); @endphp
                <li wire:key="collection-{{ $collection->id }}">
                    <a href="{{ route('recipes.collections.show', $collection->id) }}" wire:navigate class="card flex h-full flex-col overflow-hidden transition hover:shadow-md hover:ring-brand-300">
                        <div class="grid h-28 grid-cols-3 gap-0.5 bg-stone-100" aria-hidden="true">
                            @foreach ($recipes as $recipe)
                                @if ($recipe->photo_path)
                                    <img src="{{ $recipe->photoUrl('thumb') }}" alt="" loading="lazy" class="size-full object-cover">
                                @else
                                    <x-dish-illustration :recipe="$recipe" class="size-full" inner="h-[70%] w-auto" />
                                @endif
                            @endforeach
                        </div>
                        <div class="flex flex-1 flex-col gap-1 p-4">
                            <p class="flex items-center gap-2 font-display text-lg font-semibold text-stone-900">
                                {{ $collection->name }}
                                @if ($collection->isShared()) <x-badge color="violet">Partagée</x-badge> @endif
                            </p>
                            @if ($collection->description) <p class="text-sm text-stone-600">{{ $collection->description }}</p> @endif
                            <p class="mt-auto text-sm text-stone-500">{{ $collection->recipes_count }} recette{{ $collection->recipes_count > 1 ? 's' : '' }}</p>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($shared->isNotEmpty())
        <h2 class="mb-3 font-display text-lg font-semibold text-stone-900">Partagées par nos proches</h2>
        <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($shared as $collection)
                <li wire:key="shared-collection-{{ $collection->id }}">
                    <a href="{{ route('recipes.collections.show', $collection->id) }}" wire:navigate class="card flex items-center gap-3 p-4 transition hover:ring-brand-300">
                        <x-icon name="heart" class="size-5 shrink-0 text-violet-600" />
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-stone-900">{{ $collection->name }}</span>
                            <span class="block text-sm text-stone-500">{{ $collection->household?->name }}</span>
                        </span>
                        <x-icon name="chevron-right" class="size-4 text-stone-400" />
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <x-modal :show="$showForm" title="Nouvelle collection" close="closeForm">
        <form id="collection-form" wire:submit="save" class="space-y-4">
            <x-field label="Nom" for="collection-name" error="name">
                <input id="collection-name" type="text" wire:model="name" maxlength="100" placeholder="ex. Recettes de mamie" class="form-input" autofocus>
            </x-field>
            <x-field label="Description" for="collection-description" optional>
                <input id="collection-description" type="text" wire:model="description" maxlength="500" placeholder="ex. Celles qu'on fait quand Léo vient" class="form-input">
            </x-field>
        </form>
        <x-slot:footer>
            <button type="button" wire:click="closeForm" class="btn btn-ghost">Annuler</button>
            <button type="submit" form="collection-form" class="btn btn-primary">Créer</button>
        </x-slot:footer>
    </x-modal>
</div>
