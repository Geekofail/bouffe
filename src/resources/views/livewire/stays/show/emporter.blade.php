{{-- Séjour — à emporter (34.4). --}}
@php
    // Lot 42 (R45) : chaque foyer emporte de son stock ; ici, ce que NOTRE foyer emporte.
    $packed = $this->packed;
    $state = $this->packingState;
    $format = fn ($item, $qty) => $qty === null ? 'tout' : app(\App\Services\QuantityFormatter::class)->format((float) $qty, $item->unit);
    $pending = $packed->whereNull('taken_at');
    $away = $packed->whereNotNull('taken_at')->whereNull('returned_at');
@endphp
<div class="grid gap-6 lg:grid-cols-2" data-stay-packing>
    <section class="card space-y-3 p-4 sm:p-5">
        <h2 class="font-display text-lg font-semibold text-stone-900">Ce qui part de la maison</h2>
        @error('pack') <p class="form-error">{{ $message }}</p> @enderror

        @if ($packed->isEmpty())
            <p class="text-sm text-stone-500">Rien pour l'instant. Choisissez dans le stock ce que vous emportez : la liste de courses du séjour en tient compte.</p>
        @else
            <ul class="divide-y divide-stone-100">
                @foreach ($packed as $item)
                    <li wire:key="packed-{{ $item->id }}" class="flex flex-wrap items-center gap-2 py-2">
                        <span class="min-w-0 flex-1">
                            <span class="font-medium text-stone-900">{{ $item->label }}</span>
                            <span class="text-sm text-stone-500">· {{ $format($item, $item->quantity) }}</span>
                        </span>
                        @if ($item->returned_at)
                            <x-badge color="stone">revenu : {{ $item->quantity === null ? ($item->returned_quantity === null ? 'rien' : 'oui') : $format($item, $item->returned_quantity ?? 0) }}</x-badge>
                        @elseif ($item->taken_at)
                            <x-badge color="sky">parti</x-badge>
                        @else
                            <x-badge color="amber">prévu</x-badge>
                            @if ($canEdit)
                                <button type="button" wire:click="unpack({{ $item->id }})" class="btn btn-ghost min-h-10 px-2 hover:text-red-600" title="Ne plus emporter">
                                    <x-icon name="close" class="size-4" /><span class="sr-only">Ne plus emporter {{ $item->label }}</span>
                                </button>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($canEdit && $pending->isNotEmpty() && ! $state->departed_at)
            <div class="rounded-lg bg-stone-50 p-3 text-sm text-stone-600">
                <p class="mb-2">Au moment de partir, ce qui est prévu est retiré du stock de la maison.</p>
                <button type="button" wire:click="depart" wire:confirm="Retirer du stock les {{ $pending->count() }} article(s) prévu(s) ?" class="btn btn-primary">
                    <x-icon name="check" class="size-4" /> C'est parti
                </button>
            </div>
        @endif

        @if ($canEdit && $away->isNotEmpty())
            <form wire:submit="returnHome" class="space-y-2 rounded-lg bg-sky-50 p-3 text-sm ring-1 ring-sky-100">
                <p class="font-medium text-sky-900">De retour ? Indiquez ce qui revient : c'est remis dans le stock.</p>
                @foreach ($away as $item)
                    <label class="flex items-center gap-2" wire:key="return-{{ $item->id }}">
                        <span class="min-w-0 flex-1 text-stone-800">{{ $item->label }} <span class="text-stone-500">({{ $format($item, $item->quantity) }})</span></span>
                        @if ($item->quantity === null)
                            <input type="checkbox" wire:model="returned.{{ $item->id }}" class="form-checkbox"> revenu
                        @else
                            <input type="number" min="0" step="any" max="{{ (float) $item->quantity }}" wire:model="returned.{{ $item->id }}" placeholder="0" class="form-input w-24 py-1 text-sm" aria-label="Revenu : {{ $item->label }}">
                            <span class="w-10 text-stone-500">{{ $item->unit?->code ?? '' }}</span>
                        @endif
                    </label>
                @endforeach
                <button type="submit" class="btn btn-secondary">Remettre dans le stock</button>
            </form>
        @endif
    </section>

    @if ($canEdit && ! $state->returned_at)
        <section class="card space-y-3 p-4 sm:p-5">
            <h2 class="font-display text-lg font-semibold text-stone-900">Choisir dans le stock</h2>
            <input type="search" wire:model.live.debounce.300ms="packSearch" placeholder="Chercher dans le stock…" aria-label="Chercher dans le stock" class="form-input">
            <ul class="max-h-[28rem] divide-y divide-stone-100 overflow-y-auto">
                @forelse ($this->candidates as $item)
                    <li wire:key="candidate-{{ $item->id }}" class="flex flex-wrap items-center gap-2 py-2">
                        <span class="min-w-0 flex-1">
                            <span class="font-medium text-stone-800">{{ $item->name() }}</span>
                            <span class="block text-xs text-stone-500">{{ $item->quantity === null ? 'en stock' : app(\App\Services\QuantityFormatter::class)->format((float) $item->quantity, $item->unit) }} · {{ $item->location?->name }}</span>
                        </span>
                        @if ($item->quantity !== null)
                            <input type="number" min="0" step="any" max="{{ (float) $item->quantity }}" wire:model="packQuantities.{{ $item->id }}" placeholder="{{ rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.') }}"
                                   class="form-input w-24 py-1 text-sm" aria-label="Quantité à emporter : {{ $item->name() }}">
                        @endif
                        <button type="button" wire:click="pack({{ $item->id }})" class="btn btn-secondary min-h-10 px-3 text-sm">Emporter</button>
                    </li>
                @empty
                    <li class="py-2 text-sm text-stone-500">Rien dans le stock{{ $packSearch !== '' ? ' pour cette recherche' : '' }}.</li>
                @endforelse
            </ul>
        </section>
    @endif
</div>
