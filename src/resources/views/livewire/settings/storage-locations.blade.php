<div>
    <x-page-header title="Paramètres" subtitle="Les listes de référence utilisées par les recettes et la liste de courses." />
    <x-settings-nav />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card min-w-0 overflow-hidden lg:col-span-2">
            <div class="border-b border-stone-200 px-4 py-3">
                <h2 class="font-display font-semibold text-stone-900">Emplacements du stock</h2>
                <p class="text-sm text-stone-500">L'ordre des onglets de la page Stock. Le type sert aux durées de conservation (congélation, frais).</p>
            </div>

            <ol wire:sort="sort" class="divide-y divide-stone-100">
                @foreach ($locations as $location)
                    <li wire:key="location-{{ $location->id }}" wire:sort:item="{{ $location->id }}" class="flex items-center gap-3 bg-white px-4 py-2.5">
                        @if ($editingId === $location->id)
                            <form wire:submit="update" class="flex flex-1 flex-wrap gap-2 py-1" wire:sort:ignore>
                                <input type="text" wire:model="editName" class="form-input min-w-40 flex-1" aria-label="Nom" autofocus x-on:keydown.escape="$wire.cancelEdit()">
                                <select wire:model="editType" class="form-input w-auto" aria-label="Type">
                                    @foreach ($types as $type) <option value="{{ $type->value }}">{{ $type->label() }}</option> @endforeach
                                </select>
                                <button type="submit" class="btn btn-primary">OK</button>
                                <button type="button" wire:click="cancelEdit" class="btn btn-secondary">Annuler</button>
                                @error('editName') <p class="form-error mt-0 w-full">{{ $message }}</p> @enderror
                            </form>
                        @else
                            <span wire:sort:handle class="cursor-grab text-stone-300 hover:text-stone-500 active:cursor-grabbing" title="Déplacer"><x-icon name="grip" class="size-5" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium text-stone-800">{{ $location->name }}</span>
                                <span class="text-xs text-stone-500">{{ $location->type->label() }}</span>
                            </span>
                            <a href="{{ route('stock.index', ['emplacement' => $location->id]) }}" wire:navigate wire:sort:ignore class="text-xs whitespace-nowrap text-stone-500 hover:text-brand-700">
                                {{ $location->items_count }} article{{ $location->items_count > 1 ? 's' : '' }}
                            </a>
                            <div wire:sort:ignore class="flex">
                                <button type="button" wire:click="edit({{ $location->id }})" class="btn btn-ghost px-2" title="Modifier"><x-icon name="edit" class="size-4" /><span class="sr-only">Modifier</span></button>
                                <button type="button" wire:click="delete({{ $location->id }})" wire:confirm="Supprimer « {{ $location->name }} » ?" class="btn btn-ghost px-2 hover:text-red-600" title="Supprimer"><x-icon name="delete" class="size-4" /><span class="sr-only">Supprimer</span></button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>

        <form wire:submit="add" class="card h-fit space-y-4 p-4">
            <h2 class="font-display font-semibold text-stone-900">Nouvel emplacement</h2>
            <x-field label="Nom" for="new-location" error="newName">
                <input id="new-location" type="text" wire:model="newName" placeholder="ex. Congélateur du garage" class="form-input">
            </x-field>
            <x-field label="Type" for="new-location-type">
                <select id="new-location-type" wire:model="newType" class="form-input">
                    @foreach ($types as $type) <option value="{{ $type->value }}">{{ $type->label() }}</option> @endforeach
                </select>
            </x-field>
            <button type="submit" class="btn btn-primary w-full"><x-icon name="plus" class="size-4" /> Ajouter</button>
        </form>
    </div>
</div>
