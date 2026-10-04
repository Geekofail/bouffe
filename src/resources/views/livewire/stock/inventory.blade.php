<div class="mx-auto max-w-3xl">
    <a href="{{ route('stock.index', ['emplacement' => $this->location->id]) }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> Stock
    </a>

    <x-page-header :title="'Inventaire · '.$this->location->name"
                   subtitle="Passez les articles un par un : toujours là, quantité à corriger, ou plus là.">
        <x-slot:actions>
            <button type="button" wire:click="finish" class="btn btn-primary"><x-icon name="check" class="size-4" /> Terminer</button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-3 flex items-center justify-between gap-3 text-sm text-stone-600">
        <span>{{ $checked }} / {{ $total }} vérifié{{ $checked > 1 ? 's' : '' }}</span>
        @if ($this->location->last_inventory_at)
            <span class="text-stone-500">Dernier inventaire {{ $this->location->last_inventory_at->locale('fr')->diffForHumans() }}</span>
        @endif
    </div>

    @if ($total === 0)
        <div class="card">
            <x-empty-state icon="pantry" title="Rien dans cet emplacement">
                Terminez l'inventaire pour noter qu'il a été vérifié.
            </x-empty-state>
        </div>
    @else
        <ul class="card divide-y divide-stone-100">
            @foreach ($this->items as $item)
                @php
                    $state = $done[$item->id] ?? null;
                    $isGone = $state === 'gone';
                    $presence = $this->isPresence($item);
                @endphp
                <li wire:key="inv-{{ $item->id }}" @class(['px-4 py-3', 'bg-herb-50/50' => $state === 'ok' || $state === 'qty', 'bg-stone-50' => $isGone])>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <div class="min-w-0 flex-1">
                            <p @class(['font-medium', 'text-stone-900' => ! $isGone, 'text-stone-500 line-through' => $isGone])>{{ $item->name() }}</p>
                            <p class="text-xs text-stone-500">
                                @if ($isGone)
                                    retiré du stock
                                @elseif ($presence)
                                    en stock (présence)
                                @elseif ($item->quantity !== null)
                                    {{ $formatter->format((float) $item->quantity, $item->unit) }}
                                @else
                                    quantité inconnue
                                @endif
                                @if (! $isGone && $item->expires_on) · {{ $item->expires_on->format('d/m/Y') }} @endif
                            </p>
                        </div>

                        @unless ($isGone)
                            <div class="flex gap-1.5">
                                <button type="button" wire:click="confirm({{ $item->id }})"
                                        @class(['btn py-1 text-sm', 'btn-primary' => $state === 'ok', 'btn-secondary' => $state !== 'ok'])
                                        title="Toujours là">✓ <span class="sr-only sm:not-sr-only">Toujours là</span></button>
                                @unless ($presence)
                                    <button type="button" wire:click="editQuantity({{ $item->id }})"
                                            @class(['btn py-1 text-sm', 'btn-primary' => $state === 'qty', 'btn-secondary' => $state !== 'qty'])>Quantité</button>
                                @endunless
                                <button type="button" wire:click="gone({{ $item->id }})" class="btn btn-secondary py-1 text-sm text-red-700" title="Plus là">✗ <span class="sr-only sm:not-sr-only">Plus là</span></button>
                            </div>
                        @endunless
                    </div>

                    @if ($editingId === $item->id)
                        <form wire:submit="saveQuantity" class="mt-2 flex flex-wrap items-center gap-2">
                            <input type="text" inputmode="decimal" wire:model="quantity" class="form-input w-28 py-1" aria-label="Quantité restante" autofocus>
                            <span class="text-sm text-stone-500">{{ $item->unit?->label }}</span>
                            <button type="submit" class="btn btn-primary py-1 text-sm">OK</button>
                            <button type="button" wire:click="cancelEdit" class="btn btn-ghost py-1 text-sm">Annuler</button>
                            @error('quantity') <p class="form-error w-full">{{ $message }}</p> @enderror
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>

        <div class="mt-4 flex justify-end">
            <button type="button" wire:click="finish" class="btn btn-primary"><x-icon name="check" class="size-4" /> Terminer l'inventaire</button>
        </div>
    @endif
</div>
