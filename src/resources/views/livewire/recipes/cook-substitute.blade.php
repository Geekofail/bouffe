{{-- Lot 40 (40.1) : un remplacement proposé en mode cuisine. --}}
<p wire:key="sub-{{ $line['id'] }}-{{ $sub['substitute_id'] }}" class="flex flex-wrap items-center gap-x-2 gap-y-1">
    <span class="text-stone-800">
        {{ $sub['text'] }}
        @if ($sub['detail'] !== '') <span class="text-stone-500">· {{ $sub['detail'] }}</span> @endif
        @if ($sub['in_stock']) <x-badge color="green" class="align-middle">en stock</x-badge> @endif
    </span>
    <button type="button" wire:click="useSubstitute({{ (int) $line['ingredient_id'] }}, {{ $sub['substitute_id'] }})" class="min-h-10 text-sm font-medium text-brand-700 underline hover:text-brand-800">Je l'utilise</button>
</p>
