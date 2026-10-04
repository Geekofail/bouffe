{{-- « Ce soir » : un repas à clôturer (mangé · pas fait). --}}
@php $recipe = $meal->eatenRecipe(); @endphp
<li wire:key="ev-close-{{ $meal->id }}" class="flex items-center gap-3 py-2.5">
    @if ($recipe?->photo_path)
        <img src="{{ $recipe->photoUrl('thumb') }}" alt="" class="size-12 shrink-0 rounded-xl object-cover" loading="lazy">
    @elseif ($recipe)
        <x-dish-illustration :recipe="$recipe" :course="$meal->course" class="size-12 rounded-xl" />
    @endif
    <span class="min-w-0 flex-1">
        <span class="block truncate font-medium text-stone-900">{{ $meal->label() }}</span>
        <span class="block truncate text-xs text-stone-500">
            {{ $meal->date->isSameDay($now) ? 'Aujourd\'hui' : ucfirst($meal->date->locale('fr')->isoFormat('ddd D MMM')) }} · {{ mb_strtolower($meal->slot?->name ?? '') }}
        </span>
    </span>
    <button type="button" wire:click="closeEaten({{ $meal->id }})" class="btn btn-secondary min-h-11 min-w-11 px-2.5 text-herb-700 sm:px-3" title="Mangé">
        <x-icon name="check" class="size-5" /><span class="sr-only sm:not-sr-only">Mangé</span>
    </button>
    <button type="button" wire:click="closeSkipped({{ $meal->id }})" class="btn btn-ghost min-h-11 min-w-11 px-2.5 sm:px-3" title="Pas fait">
        <x-icon name="close" class="size-5" /><span class="sr-only sm:not-sr-only">Pas fait</span>
    </button>
</li>
