<div>
    <x-page-header title="Magasins" subtitle="Chaque magasin a son ordre de rayons : la liste de courses suit votre parcours." />
    <x-settings-nav />

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- ======================================================= Les magasins --}}
        <div class="space-y-4">
            <div class="card overflow-hidden">
                <div class="border-b border-stone-200 px-4 py-3">
                    <h2 class="font-display font-semibold text-stone-900">Vos magasins</h2>
                </div>

                @if ($stores->isEmpty())
                    <x-empty-state icon="cart" title="Aucun magasin">
                        Sans magasin, la liste suit l'ordre général des rayons. Ajoutez-en un pour ranger la liste dans l'ordre de votre parcours.
                    </x-empty-state>
                @else
                    <ul class="divide-y divide-stone-100">
                        @foreach ($stores as $item)
                            <li wire:key="store-{{ $item->id }}" @class(['px-4 py-2.5', 'bg-brand-50/60' => $item->id === $storeId])>
                                @if ($editingId === $item->id)
                                    <form wire:submit="update" class="space-y-3 py-1">
                                        <input type="text" wire:model="editName" class="form-input" aria-label="Nom du magasin" autofocus
                                               x-on:keydown.escape="$wire.cancelEdit()">
                                        @error('editName') <p class="form-error mt-0">{{ $message }}</p> @enderror
                                        <input type="text" wire:model="editNote" class="form-input" placeholder="ex. le mardi matin" aria-label="Note">
                                        <x-color-picker wire:model="editColor" name="edit-store-color-{{ $item->id }}" />
                                        <div class="flex gap-2">
                                            <button type="submit" class="btn btn-primary">Enregistrer</button>
                                            <button type="button" wire:click="cancelEdit" class="btn btn-secondary">Annuler</button>
                                        </div>
                                    </form>
                                @else
                                    <div class="flex items-center gap-2">
                                        <button type="button" wire:click="select({{ $item->id }})" class="flex min-w-0 flex-1 items-center gap-2 text-left">
                                            <span class="size-3 shrink-0 rounded-full {{ \App\Support\Palette::dot($item->color) }}"></span>
                                            <span class="min-w-0">
                                                <span class="block truncate font-medium text-stone-800">{{ $item->name }}</span>
                                                <span class="block truncate text-xs text-stone-500">
                                                    @if ($item->is_default) Proposé par défaut @endif
                                                    @if ($item->note) @if ($item->is_default) · @endif {{ $item->note }} @endif
                                                </span>
                                            </span>
                                        </button>

                                        @unless ($item->is_default)
                                            <button type="button" wire:click="makeDefault({{ $item->id }})" class="btn btn-ghost px-2" title="Proposer par défaut">
                                                <x-icon name="star" class="size-4" /><span class="sr-only">Proposer par défaut</span>
                                            </button>
                                        @endunless
                                        <button type="button" wire:click="edit({{ $item->id }})" class="btn btn-ghost px-2" title="Modifier">
                                            <x-icon name="edit" class="size-4" /><span class="sr-only">Modifier {{ $item->name }}</span>
                                        </button>
                                        <button type="button" wire:click="delete({{ $item->id }})"
                                                wire:confirm="Supprimer « {{ $item->name }} » ? Les listes qui l'utilisent reprendront l'ordre général des rayons."
                                                class="btn btn-ghost px-2 hover:text-red-600" title="Supprimer">
                                            <x-icon name="delete" class="size-4" /><span class="sr-only">Supprimer {{ $item->name }}</span>
                                        </button>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <form wire:submit="add" class="card space-y-4 p-4">
                <h2 class="font-display font-semibold text-stone-900">Nouveau magasin</h2>
                <x-field label="Nom" for="new-store" error="newName">
                    <input id="new-store" type="text" wire:model="newName" placeholder="ex. Cactus Belle Étoile"
                           @class(['form-input', 'form-input-error' => $errors->has('newName')])>
                </x-field>
                <div>
                    <span class="form-label">Couleur</span>
                    <x-color-picker wire:model="newColor" name="new-store-color" />
                </div>
                <button type="submit" class="btn btn-primary w-full"><x-icon name="plus" class="size-4" /> Ajouter</button>
            </form>
        </div>

        {{-- ======================================================= Parcours du magasin --}}
        <div class="min-w-0 lg:col-span-2">
            @if (! $store)
                <div class="card p-8 text-center text-stone-500">
                    <p>Ajoutez un magasin à gauche, puis rangez ses rayons dans l'ordre où vous les traversez.</p>
                    <p class="mt-2 text-sm">Sans magasin, la liste de courses suit l'ordre général défini dans
                        <a href="{{ route('settings.aisles') }}" wire:navigate class="font-medium text-brand-700">Rayons</a>.</p>
                </div>
            @else
                <div class="card overflow-hidden">
                    <div class="flex flex-wrap items-center gap-3 border-b border-stone-200 px-4 py-3">
                        <div class="min-w-0 flex-1">
                            <h2 class="font-display flex items-center gap-2 font-semibold text-stone-900">
                                <span class="size-3 shrink-0 rounded-full {{ \App\Support\Palette::dot($store->color) }}"></span>
                                Parcours chez {{ $store->name }}
                            </h2>
                            <p class="text-sm text-stone-500">Glissez-déposez les rayons dans l'ordre où vous les traversez. L'œil barré masque un rayon qu'on ne trouve pas ici.</p>
                        </div>
                        <button type="button" wire:click="resetOrder" class="btn btn-secondary"
                                wire:confirm="Reprendre l'ordre général des rayons pour « {{ $store->name }} » ?">
                            <x-icon name="undo" class="size-4" /> Ordre général
                        </button>
                    </div>

                    <ol wire:sort="sort" class="divide-y divide-stone-100">
                        @foreach ($rows as $row)
                            <li wire:key="store-aisle-{{ $store->id }}-{{ $row->aisle_id }}" wire:sort:item="{{ $row->aisle_id }}"
                                @class(['flex items-center gap-3 px-4 py-2.5', 'bg-white' => ! $row->is_hidden, 'bg-stone-50' => $row->is_hidden])>
                                <span wire:sort:handle class="cursor-grab text-stone-300 hover:text-stone-500 active:cursor-grabbing" title="Déplacer">
                                    <x-icon name="grip" class="size-5" />
                                </span>
                                <span class="hidden w-6 text-right text-sm text-stone-500 tabular-nums sm:inline">{{ $loop->iteration }}</span>
                                <span class="size-3 shrink-0 rounded-full {{ \App\Support\Palette::dot($row->aisle->color) }}"></span>
                                <span @class(['min-w-0 flex-1 font-medium', 'text-stone-800' => ! $row->is_hidden, 'text-stone-500 line-through' => $row->is_hidden])>
                                    {{ $row->aisle->name }}
                                </span>
                                @if ($row->is_hidden)
                                    <span class="hidden text-xs text-stone-500 sm:inline">pas dans ce magasin</span>
                                @endif
                                <div wire:sort:ignore>
                                    <button type="button" wire:click="toggleHidden({{ $row->aisle_id }})"
                                            class="btn btn-ghost px-2" title="{{ $row->is_hidden ? 'Reprendre ce rayon' : 'Ce rayon n\'existe pas ici' }}">
                                        <x-icon :name="$row->is_hidden ? 'eye-off' : 'eye'" class="size-4" />
                                        <span class="sr-only">{{ $row->is_hidden ? 'Reprendre' : 'Masquer' }} {{ $row->aisle->name }}</span>
                                    </button>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>

                <p class="mt-3 text-sm text-stone-500">
                    Pour utiliser ce parcours, choisissez ce magasin sur une liste de courses
                    (<a href="{{ route('shopping.index') }}" wire:navigate class="font-medium text-brand-700">Courses</a>).
                </p>
            @endif
        </div>
    </div>
</div>
