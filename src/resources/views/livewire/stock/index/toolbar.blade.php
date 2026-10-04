{{-- Stock : ajout rapide, emplacements, recherche et filtres (lot 36 : découpé de index.blade.php). --}}
{{-- ============================================================ Ajout rapide --}}
<form wire:submit="prepareQuickAdd" class="mb-4 flex gap-2">
    <input type="text" wire:model="quickText" placeholder="Ajouter : 6 œufs, 500 g de haché, crème fraîche…"
           @class(['form-input', 'form-input-error' => $errors->has('quickText')]) aria-label="Ajouter au stock" autocomplete="off">
    <button type="submit" class="btn btn-primary shrink-0"><x-icon name="plus" class="size-4" /><span class="sr-only sm:not-sr-only">Ajouter</span></button>
</form>
@error('quickText') <p class="form-error -mt-3 mb-3">{{ $message }}</p> @enderror

{{-- ============================================================ Emplacements --}}
<nav class="-mx-4 mb-3 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="Emplacements">
    <div class="flex min-w-max gap-1 rounded-xl bg-stone-100 p-1">
        @foreach (collect([['id' => 'tout', 'name' => 'Tout', 'count' => $totalCount]])->concat($this->locations->map(fn ($l) => ['id' => (string) $l->id, 'name' => $l->name, 'count' => $l->items_count])) as $tab)
            <button type="button" wire:key="loc-{{ $tab['id'] }}" wire:click="$set('location', '{{ $tab['id'] }}')"
                    @class([
                        'flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium whitespace-nowrap transition',
                        'bg-white text-stone-900 shadow-sm' => $location === $tab['id'],
                        'text-stone-600 hover:text-stone-900' => $location !== $tab['id'],
                    ])>
                {{ $tab['name'] }} <span class="text-xs text-stone-500 tabular-nums">{{ $tab['count'] }}</span>
            </button>
        @endforeach
    </div>
</nav>

{{-- Recherche et filtres : pastilles sur écran large ; sur téléphone, un bouton « Filtres » (lot 28, 28.4). --}}
@php
    $stockFilters = ['alertes' => 'À surveiller', 'bientot' => 'À consommer bientôt', 'depasse' => 'Date dépassée', 'ouverts' => 'Ouverts']
        + ($this->belowMinimum->isNotEmpty() || $filter === 'minimum' ? ['minimum' => 'Sous le minimum ('.$this->belowMinimum->count().')'] : []);
@endphp
<div class="mb-4 flex flex-wrap items-center gap-2">
    <div class="relative min-w-0 flex-1 sm:min-w-48">
        <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" />
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Chercher dans le stock…" class="form-input py-1.5 pl-10" aria-label="Chercher dans le stock">
    </div>
    <x-action-sheet :label="$filter !== '' ? 'Filtres (1)' : 'Filtres'" title="Filtrer le stock" icon="filter" class="sm:hidden">
        @foreach ($stockFilters as $key => $label)
            <button type="button" wire:click="$set('filter', '{{ $filter === $key ? '' : $key }}')" x-on:click="open = false" class="menu-item" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}">
                {{ $label }} @if ($filter === $key) <x-icon name="check" class="ml-auto size-4 text-brand-700" /> @endif
            </button>
        @endforeach
    </x-action-sheet>
    @if ($filter !== '' && isset($stockFilters[$filter]))
        <button type="button" wire:click="$set('filter', '')" class="inline-flex min-h-9 items-center gap-1 rounded-full bg-brand-600 px-3 py-1 text-sm font-medium text-white sm:hidden" title="Retirer ce filtre">
            {{ $stockFilters[$filter] }} <x-icon name="close" class="size-4" />
        </button>
    @endif
    @foreach ($stockFilters as $key => $label)
        <button type="button" wire:click="$set('filter', '{{ $filter === $key ? '' : $key }}')"
                @class([
                    'hidden rounded-full px-3 py-1 text-sm font-medium ring-1 transition sm:inline-flex',
                    'bg-brand-600 text-white ring-brand-600' => $filter === $key,
                    'bg-white text-stone-600 ring-stone-200 hover:text-stone-900' => $filter !== $key,
                ])>{{ $label }}</button>
    @endforeach
</div>

@if ($currentLocation)
    <div class="-mt-2 mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-stone-500">
        <a href="{{ route('stock.inventory', $currentLocation) }}" wire:navigate class="inline-flex items-center gap-1 font-medium text-brand-700 hover:underline">
            <x-icon name="check" class="size-4" /> Faire l'inventaire
        </a>
        <span>
            @if ($currentLocation->last_inventory_at)
                dernier inventaire {{ $currentLocation->last_inventory_at->locale('fr')->diffForHumans() }}
            @else
                jamais inventorié
            @endif
        </span>
    </div>
@endif
