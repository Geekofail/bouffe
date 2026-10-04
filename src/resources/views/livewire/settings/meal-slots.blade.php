<div>
    <x-page-header title="Paramètres" subtitle="Les listes de référence utilisées par les recettes et la liste de courses." />
    <x-settings-nav />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card min-w-0 overflow-hidden lg:col-span-2">
            <div class="border-b border-stone-200 px-4 py-3">
                <h2 class="font-display font-semibold text-stone-900">Créneaux du planning</h2>
                <p class="text-sm text-stone-500">Seuls les créneaux actifs apparaissent dans la grille de la semaine, dans cet ordre.</p>
            </div>

            <ul wire:sort="sort" class="divide-y divide-stone-100">
                @foreach ($mealSlots as $slot)
                    <li wire:key="slot-{{ $slot->id }}" wire:sort:item="{{ $slot->id }}" class="flex items-center gap-3 bg-white px-4 py-3">
                        @if ($editingId === $slot->id)
                            <form wire:submit="update" class="flex flex-1 flex-col gap-2" wire:sort:ignore>
                                <div class="flex gap-2">
                                    <input type="text" wire:model="editName" class="form-input" aria-label="Nom du créneau" autofocus
                                           x-on:keydown.escape="$wire.cancelEdit()">
                                    <button type="submit" class="btn btn-primary">OK</button>
                                    <button type="button" wire:click="cancelEdit" class="btn btn-secondary">Annuler</button>
                                </div>
                                @error('editName') <p class="form-error mt-0">{{ $message }}</p> @enderror
                            </form>
                        @else
                            <span wire:sort:handle class="cursor-grab text-stone-300 hover:text-stone-500" title="Déplacer">
                                <x-icon name="grip" class="size-5" />
                            </span>
                            <span @class(['flex-1 font-medium', 'text-stone-800' => $slot->is_active, 'text-stone-500' => ! $slot->is_active])>
                                {{ $slot->name }}
                            </span>

                            <div wire:sort:ignore class="flex items-center gap-1">
                                <button type="button" wire:click="toggle({{ $slot->id }})" role="switch" aria-checked="{{ $slot->is_active ? 'true' : 'false' }}"
                                        class="flex items-center gap-2 rounded-full px-2 py-1 text-xs font-medium text-stone-600 hover:bg-stone-100"
                                        title="{{ $slot->is_active ? 'Désactiver' : 'Activer' }}">
                                    <span @class([
                                        'relative inline-flex h-5 w-9 items-center rounded-full transition',
                                        'bg-herb-600' => $slot->is_active,
                                        'bg-stone-300' => ! $slot->is_active,
                                    ])>
                                        <span @class([
                                            'inline-block size-4 rounded-full bg-white shadow transition',
                                            'translate-x-4.5' => $slot->is_active,
                                            'translate-x-0.5' => ! $slot->is_active,
                                        ])></span>
                                    </span>
                                    <span class="w-12 text-left">{{ $slot->is_active ? 'Actif' : 'Inactif' }}</span>
                                </button>
                                <button type="button" wire:click="edit({{ $slot->id }})" class="btn btn-ghost px-2" title="Renommer">
                                    <x-icon name="edit" class="size-4" /><span class="sr-only">Renommer {{ $slot->name }}</span>
                                </button>
                                <button type="button" wire:click="delete({{ $slot->id }})"
                                        wire:confirm="Supprimer le créneau « {{ $slot->name }} » ?"
                                        class="btn btn-ghost px-2 hover:text-red-600" title="Supprimer">
                                    <x-icon name="delete" class="size-4" /><span class="sr-only">Supprimer {{ $slot->name }}</span>
                                </button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>

        <form wire:submit="add" class="card h-fit space-y-4 p-4">
            <h2 class="font-display font-semibold text-stone-900">Nouveau créneau</h2>
            <x-field label="Nom" for="new-slot" error="newName">
                <input id="new-slot" type="text" wire:model="newName" placeholder="ex. Brunch"
                       @class(['form-input', 'form-input-error' => $errors->has('newName')])>
            </x-field>
            <button type="submit" class="btn btn-primary w-full"><x-icon name="plus" class="size-4" /> Ajouter</button>
        </form>
    </div>
</div>
