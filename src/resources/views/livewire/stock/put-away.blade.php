<div class="mx-auto max-w-3xl">
    <a href="{{ route('shopping.show', $list) }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> {{ $list->name }}
    </a>

    <x-page-header title="Ranger les courses" subtitle="Vérifiez les quantités achetées et les dates, puis validez : tout entre dans le stock d'un coup." />

    @if ($rows === [])
        <div class="card">
            <x-empty-state icon="success" title="Rien à ranger">
                Les articles cochés de cette liste sont déjà rangés.
                <a href="{{ route('stock.index') }}" wire:navigate class="text-brand-700 underline">Voir le stock</a>
            </x-empty-state>
        </div>
    @else
        <div class="mb-3 flex items-center justify-between gap-3 text-sm">
            <span class="text-stone-600">{{ $includedCount }} / {{ count($rows) }} à ranger</span>
            <div class="flex gap-2">
                <button type="button" wire:click="setAll(true)" class="text-brand-700 hover:underline">Tout cocher</button>
                <button type="button" wire:click="setAll(false)" class="text-stone-500 hover:underline">Tout décocher</button>
            </div>
        </div>

        @error('rows') <p class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</p> @enderror

        <form wire:submit="store" class="space-y-2">
            @foreach ($rows as $i => $row)
                <div wire:key="row-{{ $row['item_id'] }}" @class(['card p-3 transition', 'opacity-50' => ! $row['include']])>
                    <label class="flex items-center gap-3">
                        <input type="checkbox" wire:model.live="rows.{{ $i }}.include" class="form-checkbox size-5" @disabled(! $row['ingredient_id'])>
                        <span class="min-w-0 flex-1 font-semibold text-stone-900">{{ $row['label'] }}</span>
                        @if (! $row['ingredient_id'])
                            <span class="text-xs text-stone-500">pas un ingrédient : ignoré</span>
                        @elseif ($row['presence'])
                            <span class="text-xs text-stone-500">présence</span>
                        @endif
                    </label>

                    @if ($row['include'])
                        <div class="mt-3 grid gap-2 sm:grid-cols-[minmax(0,9rem)_minmax(0,1fr)_minmax(0,1fr)]">
                            @unless ($row['presence'])
                                <div class="flex gap-1">
                                    <input type="text" inputmode="decimal" wire:model="rows.{{ $i }}.quantity" class="form-input min-w-0 py-1.5" aria-label="Quantité de {{ $row['label'] }}" placeholder="Qté">
                                    <select wire:model="rows.{{ $i }}.unit_id" class="form-input w-24 py-1.5" aria-label="Unité">
                                        <option value="">—</option>
                                        @foreach ($units as $unit) <option value="{{ $unit->id }}">{{ $unit->label }}</option> @endforeach
                                    </select>
                                </div>
                            @endunless
                            <select wire:model="rows.{{ $i }}.storage_location_id" class="form-input py-1.5" aria-label="Emplacement">
                                @foreach ($locations as $loc) <option value="{{ $loc->id }}">{{ $loc->name }}</option> @endforeach
                            </select>
                            @unless ($row['presence'])
                                <div class="flex gap-1">
                                    <input type="date" wire:model="rows.{{ $i }}.expires_on" class="form-input min-w-0 py-1.5" aria-label="Date limite">
                                    <select wire:model="rows.{{ $i }}.expiry_type" class="form-input w-24 py-1.5" aria-label="Type de date">
                                        @foreach ($expiryTypes as $type) <option value="{{ $type->value }}">{{ $type->shortLabel() }}</option> @endforeach
                                    </select>
                                </div>
                            @endunless
                        </div>
                        @unless ($row['presence'])
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach (['3d' => '+3 j', '1w' => '+1 sem.', '1m' => '+1 mois', 'none' => 'Pas de date'] as $key => $label)
                                    <button type="button" wire:click="shiftDate({{ $i }}, '{{ $key }}')" class="rounded-full bg-stone-100 px-2.5 py-0.5 text-xs text-stone-700 hover:bg-stone-200">{{ $label }}</button>
                                @endforeach
                            </div>

                            {{-- 16.2 : date proposée d'après ce qui a été observé, pas d'après une estimation. --}}
                            @if (! empty($row['learned_note']))
                                <p class="mt-1.5 flex items-center gap-1 text-xs text-herb-700">
                                    <x-icon name="sparkles" class="size-3.5" /> {{ $row['learned_note'] }}
                                </p>
                            @endif
                        @endunless
                    @endif
                </div>
            @endforeach

            <div class="sticky bottom-20 flex justify-end gap-2 pt-2 md:bottom-4">
                <a href="{{ route('shopping.show', $list) }}" wire:navigate class="btn btn-secondary shadow">Plus tard</a>
                <button type="submit" class="btn btn-primary shadow-lg"><x-icon name="success" class="size-4" /> Tout valider</button>
            </div>
        </form>
        <p class="mt-3 text-xs text-stone-500">Les lignes décochées ne sont pas ajoutées au stock et ne seront plus proposées.</p>
    @endif
</div>
