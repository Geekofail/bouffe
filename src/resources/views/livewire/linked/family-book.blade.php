<div>
    <x-page-header title="Carnet familial" subtitle="Réunissez des recettes de plusieurs foyers dans un livre à imprimer : sommaire, une recette par page, photos, auteurs." />
    <x-linked-nav />

    <div class="grid gap-6 lg:grid-cols-[1fr_18rem]">
        <div class="space-y-4">
            @forelse ($groups as $name => $recipes)
                @php $ids = $recipes->pluck('id')->all(); @endphp
                <section wire:key="grp-{{ md5($name) }}" class="card p-4 sm:p-5">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h2 class="font-display font-semibold text-stone-900">{{ $name }} <span class="font-normal text-stone-500">· {{ $recipes->count() }}</span></h2>
                        <button type="button" wire:click="toggleGroup(@js($ids))" class="text-sm font-medium text-brand-700 hover:underline">
                            {{ array_diff($ids, $selected) === [] ? 'Tout décocher' : 'Tout cocher' }}
                        </button>
                    </div>
                    <div class="grid gap-x-4 gap-y-1.5 sm:grid-cols-2">
                        @foreach ($recipes as $recipe)
                            <label wire:key="fb-{{ $recipe->id }}" class="flex items-center gap-2 text-sm text-stone-800">
                                <input type="checkbox" value="{{ $recipe->id }}" wire:model.live="selected" class="form-checkbox">
                                <span class="truncate">{{ $recipe->title }}</span>
                            </label>
                        @endforeach
                    </div>
                </section>
            @empty
                <div class="card"><x-empty-state icon="recipes" title="Aucune recette">Ajoutez des recettes à votre carnet, ou reliez-vous à des proches qui partagent les leurs.</x-empty-state></div>
            @endforelse
        </div>

        <aside class="card h-fit space-y-3 p-4 sm:p-5 lg:sticky lg:top-20">
            <x-field label="Titre du carnet" for="fb-title">
                <input id="fb-title" type="text" maxlength="120" wire:model.live.debounce.500ms="title" class="form-input">
            </x-field>
            <p class="text-sm text-stone-600">{{ $count }} recette{{ $count > 1 ? 's' : '' }} choisie{{ $count > 1 ? 's' : '' }}.</p>
            @if ($printUrl)
                <a href="{{ $printUrl }}" target="_blank" class="btn btn-primary w-full"><x-icon name="printer" class="size-4" /> Voir et imprimer</a>
                <p class="text-xs text-stone-500">Pour un PDF : « Imprimer », puis « Enregistrer au format PDF ».</p>
            @else
                <button type="button" class="btn btn-primary w-full" disabled><x-icon name="printer" class="size-4" /> Voir et imprimer</button>
            @endif
        </aside>
    </div>
</div>
