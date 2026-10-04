{{-- Sélecteur de couleur de la palette. Usage : <x-color-picker wire:model="color" name="color" /> --}}
@props(['name' => 'color'])

<div class="flex flex-wrap gap-1.5" role="radiogroup">
    @foreach (\App\Support\Palette::options() as $key => $label)
        <label class="cursor-pointer" title="{{ $label }}">
            <input type="radio" name="{{ $name }}" value="{{ $key }}" {{ $attributes->whereStartsWith('wire:model') }} class="peer sr-only">
            <span class="block size-6 rounded-full ring-2 ring-transparent ring-offset-1 peer-checked:ring-stone-700 peer-focus-visible:ring-brand-500 {{ \App\Support\Palette::dot($key) }}"></span>
            <span class="sr-only">{{ $label }}</span>
        </label>
    @endforeach
</div>
