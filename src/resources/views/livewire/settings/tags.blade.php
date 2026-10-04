<div>
    <x-page-header title="Paramètres" subtitle="Les listes de référence utilisées par les recettes et la liste de courses." />
    <x-settings-nav />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card min-w-0 lg:col-span-2">
            <div class="border-b border-stone-200 px-4 py-3">
                <h2 class="font-display font-semibold text-stone-900">Catégories de recettes</h2>
                <p class="text-sm text-stone-500">Pour classer et filtrer les recettes : type de plat, régime, saison, temps de préparation…</p>
            </div>

            @if ($tags->isEmpty())
                <x-empty-state icon="tag" title="Aucune catégorie" />
            @else
                <ul class="divide-y divide-stone-100">
                    @foreach ($tags as $tag)
                        <li wire:key="tag-{{ $tag->id }}" class="flex items-center gap-3 px-4 py-2.5">
                            @if ($editingId === $tag->id)
                                <form wire:submit="update" class="flex flex-1 flex-col gap-3 py-1">
                                    <div class="flex gap-2">
                                        <input type="text" wire:model="editName" class="form-input" aria-label="Nom de la catégorie" autofocus
                                               x-on:keydown.escape="$wire.cancelEdit()">
                                        <button type="submit" class="btn btn-primary">OK</button>
                                        <button type="button" wire:click="cancelEdit" class="btn btn-secondary">Annuler</button>
                                    </div>
                                    @error('editName') <p class="form-error mt-0">{{ $message }}</p> @enderror
                                    <x-color-picker wire:model="editColor" name="edit-tag-color" />
                                </form>
                            @else
                                <div class="flex-1"><x-badge :color="$tag->color" class="text-sm">{{ $tag->name }}</x-badge></div>
                                <button type="button" wire:click="edit({{ $tag->id }})" class="btn btn-ghost px-2" title="Modifier">
                                    <x-icon name="edit" class="size-4" /><span class="sr-only">Modifier {{ $tag->name }}</span>
                                </button>
                                <button type="button" wire:click="delete({{ $tag->id }})"
                                        wire:confirm="Supprimer la catégorie « {{ $tag->name }} » ?"
                                        class="btn btn-ghost px-2 hover:text-red-600" title="Supprimer">
                                    <x-icon name="delete" class="size-4" /><span class="sr-only">Supprimer {{ $tag->name }}</span>
                                </button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <form wire:submit="add" class="card h-fit space-y-4 p-4">
            <h2 class="font-display font-semibold text-stone-900">Nouvelle catégorie</h2>
            <x-field label="Nom" for="new-tag" error="newName">
                <input id="new-tag" type="text" wire:model="newName" placeholder="ex. Sans gluten"
                       @class(['form-input', 'form-input-error' => $errors->has('newName')])>
            </x-field>
            <div>
                <span class="form-label">Couleur</span>
                <x-color-picker wire:model="newColor" name="new-tag-color" />
            </div>
            <button type="submit" class="btn btn-primary w-full"><x-icon name="plus" class="size-4" /> Ajouter</button>
        </form>
    </div>
</div>
