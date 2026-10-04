<div class="mx-auto max-w-5xl">
    <a href="{{ route('recipes.show', $this->recipe) }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> {{ $this->recipe->title }}
    </a>

    <x-page-header title="Variantes" :subtitle="'Ce qui change dans « '.$this->recipe->title.' » : quelques ingrédients, rien d\'autre.'" />

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- ======================================================= Les variantes --}}
        <div class="space-y-4">
            <div class="card overflow-hidden">
                <div class="border-b border-stone-200 px-4 py-3">
                    <h2 class="font-display font-semibold text-stone-900">Variantes</h2>
                </div>

                @if ($this->variants->isEmpty())
                    <x-empty-state icon="recipes" title="Aucune variante">
                        Une variante évite de recopier la recette pour un simple remplacement d'ingrédient.
                    </x-empty-state>
                @else
                    <ul class="divide-y divide-stone-100">
                        @foreach ($this->variants as $item)
                            <li wire:key="variant-{{ $item->id }}" @class(['flex items-center gap-2 px-4 py-2.5', 'bg-brand-50/60' => $item->id === $variantId])>
                                <button type="button" wire:click="select({{ $item->id }})" class="min-w-0 flex-1 text-left">
                                    <span class="block truncate font-medium text-stone-800">{{ $item->name }}</span>
                                    <span class="block truncate text-xs text-stone-500">
                                        {{ $item->swaps->count() }} remplacement{{ $item->swaps->count() > 1 ? 's' : '' }}
                                        @if ($item->solvesLabel()) · évite {{ mb_strtolower($item->solvesLabel()) }} @endif
                                    </span>
                                </button>
                                <button type="button" wire:click="delete({{ $item->id }})"
                                        wire:confirm="Supprimer la variante « {{ $item->name }} » ?"
                                        class="btn btn-ghost px-2 hover:text-red-600" title="Supprimer">
                                    <x-icon name="delete" class="size-4" /><span class="sr-only">Supprimer {{ $item->name }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <form wire:submit="add" class="card space-y-4 p-4">
                <h2 class="font-display font-semibold text-stone-900">Nouvelle variante</h2>

                <x-field label="Nom" for="variant-name" error="newName">
                    <input id="variant-name" type="text" wire:model="newName" placeholder="ex. Version végétarienne"
                           @class(['form-input', 'form-input-error' => $errors->has('newName')])>
                </x-field>

                <x-field label="Précision" for="variant-note" error="newNote" optional>
                    <input id="variant-note" type="text" wire:model="newNote" placeholder="ex. un peu moins riche" class="form-input">
                </x-field>

                <x-field label="Contrainte levée" for="variant-solves" optional
                         help="Renseignée, la variante est proposée d'elle-même quand un convive a cette contrainte.">
                    <select id="variant-solves" wire:model.live="solvesType" class="form-input">
                        <option value="">Aucune</option>
                        @foreach ($restrictionTypes as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>
                </x-field>

                @if ($solvesType === 'diet')
                    <x-field label="Régime" for="variant-tag" error="solvesTagId">
                        <select id="variant-tag" wire:model="solvesTagId" class="form-input">
                            <option value="">—</option>
                            @foreach ($this->tags as $tag) <option value="{{ $tag->id }}">{{ $tag->name }}</option> @endforeach
                        </select>
                    </x-field>
                @elseif ($solvesType !== '')
                    <x-field label="Ingrédient évité" for="variant-ingredient" error="solvesIngredientId">
                        <select id="variant-ingredient" wire:model="solvesIngredientId" class="form-input">
                            <option value="">—</option>
                            @foreach ($this->ingredients as $ingredient) <option value="{{ $ingredient->id }}">{{ $ingredient->name }}</option> @endforeach
                        </select>
                    </x-field>
                @endif

                <button type="submit" class="btn btn-primary w-full"><x-icon name="plus" class="size-4" /> Créer</button>
            </form>
        </div>

        {{-- ======================================================= Remplacements --}}
        <div class="min-w-0 lg:col-span-2">
            @if (! $this->variant)
                <div class="card p-8 text-center text-stone-500">
                    <p>Créez une variante à gauche, puis indiquez ici ce qui change.</p>
                </div>
            @else
                <div class="card overflow-hidden">
                    <div class="border-b border-stone-200 px-4 py-3">
                        <h2 class="font-display font-semibold text-stone-900">{{ $this->variant->name }}</h2>
                        <p class="text-sm text-stone-500">Pour chaque ligne : laissez telle quelle, remplacez l'ingrédient, ou retirez-la.</p>
                    </div>

                    <ul class="divide-y divide-stone-100">
                        @foreach ($lines as $row)
                            @php $line = $row['line']; @endphp
                            <li wire:key="line-{{ $line->id }}" @class(['px-4 py-3', 'bg-brand-50/40' => $row['swapped'] || $row['removed']])>
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                    <span class="min-w-40 flex-1">
                                        <span @class(['block font-medium', 'text-stone-500 line-through' => $row['removed'], 'text-stone-800' => ! $row['removed']])>
                                            {{ $line->ingredient?->name }}
                                        </span>
                                        <span class="block text-xs text-stone-500">
                                            {{ $line->quantity ? app(\App\Services\QuantityFormatter::class)->number((float) $line->quantity).' '.$line->unit?->label : 'sans quantité' }}
                                            @if ($line->preparation) · {{ $line->preparation }} @endif
                                        </span>
                                    </span>

                                    <select wire:change="swap({{ $line->id }}, $event.target.value)" class="form-input w-auto min-w-48 py-1.5 text-sm" aria-label="Remplacement pour {{ $line->ingredient?->name }}">
                                        <option value="" @selected(! $row['swapped'] && ! $row['removed'])>— inchangé —</option>
                                        <option value="none" @selected($row['removed'])>Retirer cette ligne</option>
                                        <optgroup label="Remplacer par">
                                            @foreach ($this->ingredients as $ingredient)
                                                <option value="{{ $ingredient->id }}" @selected($row['swapped'] && $row['ingredient']?->id === $ingredient->id)>{{ $ingredient->name }}</option>
                                            @endforeach
                                        </optgroup>
                                    </select>
                                </div>

                                @if ($row['swapped'])
                                    <div class="mt-2 flex flex-wrap items-center gap-2 pl-1 text-xs text-stone-600">
                                        <span>Quantité de remplacement :</span>
                                        <input type="text" inputmode="decimal"
                                               value="{{ $row['quantity'] !== null ? app(\App\Services\QuantityFormatter::class)->number($row['quantity']) : '' }}"
                                               wire:change="setQuantity({{ $line->id }}, $event.target.value)"
                                               class="form-input w-24 py-1 text-sm" aria-label="Quantité de remplacement">
                                        <select wire:change="setQuantity({{ $line->id }}, '{{ $row['quantity'] }}', $event.target.value)" class="form-input w-auto py-1 text-sm" aria-label="Unité de remplacement">
                                            <option value="">—</option>
                                            @foreach ($this->units as $unit)
                                                <option value="{{ $unit->id }}" @selected($row['unit']?->id === $unit->id)>{{ $unit->label }}</option>
                                            @endforeach
                                        </select>
                                        @error('quantity-'.$line->id) <span class="text-red-600">{{ $message }}</span> @enderror
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>

                <p class="mt-3 text-sm text-stone-500">
                    Cette variante s'affiche sur la fiche de la recette, à côté de « Recette d'origine ».
                </p>
            @endif
        </div>
    </div>
</div>
