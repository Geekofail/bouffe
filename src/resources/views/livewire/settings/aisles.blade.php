<div>
    <x-page-header title="Paramètres" subtitle="Les listes de référence utilisées par les recettes et la liste de courses." />
    <x-settings-nav />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="min-w-0 lg:col-span-2">
            <div class="card overflow-hidden">
                <div class="border-b border-stone-200 px-4 py-3">
                    <h2 class="font-display font-semibold text-stone-900">Ordre des rayons</h2>
                    <p class="text-sm text-stone-500">Glissez-déposez pour suivre votre parcours en magasin : la liste de courses sera triée dans cet ordre.</p>
                </div>

                <ol wire:sort="sort" class="divide-y divide-stone-100">
                    @foreach ($aisles as $aisle)
                        <li wire:key="aisle-{{ $aisle->id }}" wire:sort:item="{{ $aisle->id }}" class="flex items-center gap-3 bg-white px-4 py-2.5">
                            @if ($editingId === $aisle->id)
                                <form wire:submit="update" class="flex flex-1 flex-col gap-3 py-1" wire:sort:ignore>
                                    <div class="flex gap-2">
                                        <input type="text" wire:model="editName" class="form-input" aria-label="Nom du rayon" autofocus
                                               x-on:keydown.escape="$wire.cancelEdit()">
                                        <button type="submit" class="btn btn-primary">OK</button>
                                        <button type="button" wire:click="cancelEdit" class="btn btn-secondary">Annuler</button>
                                    </div>
                                    @error('editName') <p class="form-error mt-0">{{ $message }}</p> @enderror
                                    <x-color-picker wire:model="editColor" name="edit-color-{{ $aisle->id }}" />
                                </form>
                            @else
                                <span wire:sort:handle class="cursor-grab text-stone-300 hover:text-stone-500 active:cursor-grabbing" title="Déplacer">
                                    <x-icon name="grip" class="size-5" />
                                </span>
                                <span class="hidden w-6 text-right text-sm text-stone-500 tabular-nums sm:inline">{{ $loop->iteration }}</span>
                                <span class="size-3 shrink-0 rounded-full {{ \App\Support\Palette::dot($aisle->color) }}"></span>
                                <span class="min-w-0 flex-1 font-medium text-stone-800">{{ $aisle->name }}</span>
                                <a href="{{ route('settings.ingredients', ['rayon' => $aisle->id]) }}" wire:navigate wire:sort:ignore
                                   class="hidden text-xs whitespace-nowrap text-stone-500 hover:text-brand-700 sm:inline">
                                    {{ $aisle->ingredients_count }} ingrédient{{ $aisle->ingredients_count > 1 ? 's' : '' }}
                                </a>
                                <div wire:sort:ignore class="flex">
                                    <button type="button" wire:click="edit({{ $aisle->id }})" class="btn btn-ghost px-2" title="Modifier">
                                        <x-icon name="edit" class="size-4" /><span class="sr-only">Modifier {{ $aisle->name }}</span>
                                    </button>
                                    <button type="button" wire:click="delete({{ $aisle->id }})"
                                            wire:confirm="Supprimer le rayon « {{ $aisle->name }} » ?"
                                            class="btn btn-ghost px-2 hover:text-red-600" title="Supprimer">
                                        <x-icon name="delete" class="size-4" /><span class="sr-only">Supprimer {{ $aisle->name }}</span>
                                    </button>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>

        <form wire:submit="add" class="card h-fit space-y-4 p-4">
            <h2 class="font-display font-semibold text-stone-900">Nouveau rayon</h2>
            <x-field label="Nom" for="new-aisle" error="newName">
                <input id="new-aisle" type="text" wire:model="newName" placeholder="ex. Produits du monde"
                       @class(['form-input', 'form-input-error' => $errors->has('newName')])>
            </x-field>
            <div>
                <span class="form-label">Couleur</span>
                <x-color-picker wire:model="newColor" name="new-color" />
            </div>
            <button type="submit" class="btn btn-primary w-full"><x-icon name="plus" class="size-4" /> Ajouter</button>
        </form>
    </div>
</div>
