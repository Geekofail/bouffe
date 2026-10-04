{{-- Chiffre clé (lot 35) : la valeur, ce qu'elle mesure, et sa base — jamais un chiffre seul. --}}
@props(['label', 'value', 'base' => null, 'icon' => null])
<div {{ $attributes->class('card flex flex-col gap-1 p-4') }}>
    <p class="flex items-center gap-1.5 text-sm font-medium text-stone-500">
        @if ($icon) <x-icon :name="$icon" class="size-4" /> @endif {{ $label }}
    </p>
    <p class="font-display text-3xl font-semibold text-stone-900 tabular-nums">{{ $value }}</p>
    @if ($base) <p class="text-xs text-stone-500">{{ $base }}</p> @endif
    {{ $slot }}
</div>
