{{-- Stock : les articles, par ingrédient (lot 36). --}}
{{-- ============================================================ Articles --}}
@if ($this->groups->isEmpty() && $this->outOfStock->isEmpty() && ! ($filter === 'minimum' && $this->belowMinimum->isNotEmpty()))
    <div class="card">
        <x-empty-state icon="pantry" :title="$totalCount === 0 ? 'Le stock est vide' : 'Rien ne correspond'">
            @if ($totalCount === 0)
                Ajoutez des produits avec le champ ci-dessus, ou rangez vos courses depuis une liste terminée.
            @else
                Changez d'emplacement ou de filtre.
            @endif
        </x-empty-state>
    </div>
@elseif ($this->groups->isNotEmpty() || $this->outOfStock->isNotEmpty())
    <ul class="card divide-y divide-stone-100" wire:loading.class="opacity-60" wire:target="location,search,filter">
        @foreach ($this->groups as $group)
            <li wire:key="group-{{ $group['key'] }}" class="px-4 py-3">
                <div class="flex items-center gap-2">
                    <p class="min-w-0 flex-1 truncate font-semibold text-stone-900">
                        {{ $group['name'] }}
                        @if ($group['total'] && $group['items']->count() > 1)
                            <span class="font-normal text-stone-500">· {{ $group['total'] }}</span>
                        @endif
                    </p>
                    @if ($group['presence'])
                        <button type="button" wire:click="togglePresence({{ $group['ingredient_id'] }}, false)"
                                class="flex items-center gap-1.5 rounded-full bg-herb-50 px-2.5 py-1 text-xs font-medium text-herb-700 ring-1 ring-herb-200 hover:bg-red-50 hover:text-red-700 hover:ring-red-200"
                                title="Marquer « plus rien »">
                            <span class="size-2 rounded-full bg-herb-500"></span> En stock
                        </button>
                    @elseif ($group['items']->count() > 1)
                        <span class="text-xs text-stone-500">{{ $group['items']->count() }} articles</span>
                    @endif
                </div>

                <div class="mt-1.5 flex flex-wrap gap-1.5">
                    @foreach ($group['items'] as $item)
                        @php $badge = $expiry->badge($item); @endphp
                        <button type="button" wire:key="item-{{ $item->id }}" wire:click="select({{ $item->id }})"
                                class="flex max-w-full items-center gap-1.5 rounded-lg bg-stone-50 px-2.5 py-1.5 text-left text-sm text-stone-700 ring-1 ring-stone-200 transition hover:bg-white hover:ring-brand-300"
                                title="{{ $badge['title'] ?? 'Détails' }}">
                            @if ($item->quantity !== null)
                                <span class="font-medium whitespace-nowrap text-stone-900 tabular-nums">{{ $formatter->format((float) $item->quantity, $item->unit) }}</span>
                            @elseif (! $group['presence'])
                                <span class="text-stone-500">quantité ?</span>
                            @endif
                            @if ($location === 'tout')
                                <span class="truncate text-xs text-stone-500">{{ $item->location->name }}</span>
                            @endif
                            @if ($badge)
                                <x-badge :color="$badge['color']">{{ $badge['text'] }}</x-badge>
                            @endif
                            @if ($item->isFrozen()) <x-badge color="blue">congelé</x-badge>
                            @elseif ($item->opened_on) <x-badge color="violet">ouvert</x-badge> @endif
                            @if ($item->note) <span class="truncate text-xs text-stone-500 italic">{{ $item->note }}</span> @endif
                            @if ($allergen = $this->allergenAlerts[$item->id] ?? null)
                                <x-badge color="red" title="{{ $allergen }}" data-allergen-alert>allergène</x-badge>
                            @endif
                            @if ($reserved = $this->reservations[$item->id] ?? null)
                                <span class="min-w-0 truncate rounded-full bg-sky-50 px-1.5 text-xs text-sky-800 ring-1 ring-sky-200" title="Réservé pour : {{ collect($reserved['meals'])->pluck('label')->unique()->join(', ') }}">
                                    {{ $item->ingredient ? app(\App\Services\IngredientLineFormatter::class)->format($reserved['reserved'], $item->unit, $item->ingredient)['text'] : $formatter->format($reserved['reserved'], $item->unit) }} réservé{{ $reserved['reserved'] >= 2 ? 's' : '' }}
                                </span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </li>
        @endforeach
    </ul>

    @if ($this->outOfStock->isNotEmpty())
        <details class="mt-4" @if ($this->groups->isEmpty()) open @endif>
            <summary class="cursor-pointer text-sm font-semibold text-stone-600">Plus en stock ({{ $this->outOfStock->count() }})</summary>
            <div class="mt-2 flex flex-wrap gap-1.5">
                @foreach ($this->outOfStock as $ingredient)
                    <button type="button" wire:key="out-{{ $ingredient->id }}" wire:click="togglePresence({{ $ingredient->id }}, true)"
                            class="flex items-center gap-1.5 rounded-full bg-white px-3 py-1 text-sm text-stone-600 ring-1 ring-stone-200 hover:text-herb-700 hover:ring-herb-300"
                            title="Marquer « en stock »">
                        <span class="size-2 rounded-full border border-stone-300"></span> {{ $ingredient->name }}
                    </button>
                @endforeach
            </div>
        </details>
    @endif
@endif
