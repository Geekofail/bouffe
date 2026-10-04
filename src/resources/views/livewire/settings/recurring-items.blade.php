<div>
    <x-page-header title="Paramètres" subtitle="Les listes de référence utilisées par les recettes et la liste de courses." />
    <x-settings-nav />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card min-w-0 lg:col-span-2">
            <div class="border-b border-stone-200 px-4 py-3">
                <h2 class="font-display font-semibold text-stone-900">Articles récurrents</h2>
                <p class="text-sm text-stone-500">Ajoutés automatiquement à chaque nouvelle liste de courses (s'ils ne sont pas déjà apportés par une recette).</p>
            </div>

            @if ($items->isEmpty())
                <x-empty-state icon="cart" title="Aucun article récurrent">Ex. café, lait, pain, papier toilette…</x-empty-state>
            @else
                <ul class="divide-y divide-stone-100">
                    @foreach ($items as $item)
                        <li wire:key="recurring-{{ $item->id }}" class="flex flex-wrap items-center gap-3 px-4 py-2.5">
                            <label class="flex min-w-0 flex-1 items-center gap-2">
                                <input type="checkbox" class="form-checkbox" @checked($item->is_active) wire:click="toggle({{ $item->id }})"
                                       title="{{ $item->is_active ? 'Actif' : 'Inactif' }}">
                                <span @class(['font-medium', 'text-stone-800' => $item->is_active, 'text-stone-500 line-through' => ! $item->is_active])>{{ $item->label }}</span>
                            </label>
                            <select wire:change="updateAisle({{ $item->id }}, $event.target.value)" class="form-input w-auto py-1 text-sm" aria-label="Rayon de {{ $item->label }}">
                                <option value="">Autres</option>
                                @foreach ($this->aisles as $aisle)
                                    <option value="{{ $aisle->id }}" @selected($aisle->id === $item->aisle_id)>{{ $aisle->name }}</option>
                                @endforeach
                            </select>
                            <button type="button" wire:click="delete({{ $item->id }})" wire:confirm="Supprimer « {{ $item->label }} » ?"
                                    class="btn btn-ghost px-2 hover:text-red-600" title="Supprimer">
                                <x-icon name="delete" class="size-4" /><span class="sr-only">Supprimer</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <form wire:submit="add" class="card h-fit space-y-4 p-4">
            <h2 class="font-display font-semibold text-stone-900">Nouvel article récurrent</h2>
            <x-field label="Article" for="rec-label" error="label">
                <input id="rec-label" type="text" wire:model="label" placeholder="ex. Café" class="form-input">
            </x-field>
            <x-field label="Rayon" for="rec-aisle" error="aisleId" optional help="Déduit automatiquement si l'article est un ingrédient connu.">
                <select id="rec-aisle" wire:model="aisleId" class="form-input">
                    <option value="">Automatique</option>
                    @foreach ($this->aisles as $aisle) <option value="{{ $aisle->id }}">{{ $aisle->name }}</option> @endforeach
                </select>
            </x-field>
            <button type="submit" class="btn btn-primary w-full"><x-icon name="plus" class="size-4" /> Ajouter</button>
        </form>
    </div>
</div>
