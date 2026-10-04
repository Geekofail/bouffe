{{-- Ligne d'article : grande case à cocher (utilisable d'une main en magasin) --}}
@props(['item', 'presenter'])

<li wire:key="item-{{ $item->id }}" @class(['flex items-stretch gap-1 transition', 'opacity-55' => $item->is_checked])>
    <button type="button" wire:click="toggle({{ $item->id }})"
            class="flex min-h-12 flex-1 items-center gap-3 rounded-lg px-2 py-2 text-left hover:bg-stone-50 print:min-h-0 print:py-0.5"
            aria-pressed="{{ $item->is_checked ? 'true' : 'false' }}">
        <span @class([
            'flex size-6 shrink-0 items-center justify-center rounded-md border-2 transition print:size-4 print:rounded-sm print:border',
            'border-herb-600 bg-herb-600 text-white' => $item->is_checked,
            'border-stone-300 bg-white' => ! $item->is_checked,
        ])>
            @if ($item->is_checked) <x-icon name="check" class="size-4 stroke-[3] print:hidden" /> @endif
        </span>
        <span class="min-w-0 flex-1">
            <span @class(['block text-[15px] leading-snug text-stone-900', 'line-through decoration-stone-400' => $item->is_checked])>
                {{ $presenter->text($item) }}
                @if ($item->is_optional) <span class="text-xs text-stone-500">(facultatif)</span> @endif
            </span>
            @if ($item->sources->isNotEmpty() || $item->origin !== \App\Enums\ItemOrigin::Generated)
                <span class="block truncate text-xs text-stone-500 print:hidden">
                    @if ($item->for_household_id)
                        <span class="font-medium text-violet-700">Pour {{ $item->forHousehold?->name }}</span>{{ $item->paid_price !== null ? ' · '.number_format((float) $item->paid_price, 2, ',', ' ').' € à rembourser' : ' · prix à saisir' }}
                    @elseif ($item->origin === \App\Enums\ItemOrigin::Manual)
                        Ajouté à la main
                    @elseif ($item->origin === \App\Enums\ItemOrigin::Recurring)
                        Article récurrent
                    @elseif ($item->origin === \App\Enums\ItemOrigin::Restock)
                        Stock bas
                    @else
                        {{ $item->sources->pluck('recipe_title')->unique()->join(', ') }}
                    @endif
                    @if ($item->quantity_overridden) · quantité modifiée @endif
                </span>
            @endif
            @if ($item->stock_note)
                <span @class(['block truncate text-xs print:hidden', 'text-herb-700' => $item->stock_status === 'covered' || $item->stock_status === 'partial', 'text-stone-500' => $item->origin === \App\Enums\ItemOrigin::Manual, 'text-amber-700' => $item->origin !== \App\Enums\ItemOrigin::Manual && ! in_array($item->stock_status, ['covered', 'partial'], true)])>
                    @if ($item->stock_status === 'covered') ✓ @endif{{ $item->stock_note }}
                </span>
            @endif
            {{-- Lot 40 (40.1) : un remplacement déjà à la maison. --}}
            @if ($alternative = $presenter->alternative($item))
                <span class="block truncate text-xs text-herb-700 print:hidden" data-alternative>{{ $alternative }}</span>
            @endif
        </span>
    </button>

    <button type="button" wire:click="edit({{ $item->id }})" class="rounded-lg px-2 text-stone-500 hover:bg-stone-100 hover:text-stone-700 print:hidden" title="Détails / modifier">
        <x-icon name="edit" class="size-4" /><span class="sr-only">Modifier {{ $item->label }}</span>
    </button>
</li>
