{{--
    Envoi d'une photo : sur téléphone ou tablette (écran tactile), un bouton « Prendre une photo » ouvre
    directement l'appareil photo (capture="environment" : l'objectif de dos), à côté d'un bouton pour
    choisir une photo existante. Sur ordinateur, seul le choix d'un fichier est proposé.

    Les deux champs remplissent la même propriété Livewire ($model). Les attributs passés au composant
    (id, data-…, aria-…) vont au champ « fichier », celui qui existe partout.

    <x-photo-input model="photo" accept="image/*,application/pdf" />
--}}
@props([
    'model',
    'accept' => 'image/*',
    'label' => null,
    'galleryLabel' => null,
    'cameraLabel' => 'Prendre une photo',
    'buttonClass' => 'btn btn-secondary',
    'cameraClass' => null,
    'showName' => false,
])

@php
    $withPdf = str_contains($accept, 'pdf');
    $label ??= $withPdf ? 'Choisir un fichier' : 'Choisir une photo';
    $galleryLabel ??= $withPdf ? 'Choisir un fichier' : 'Choisir une photo';
    // L'appareil photo ne donne que des images : on garde les types d'image demandés (JPEG, PNG…).
    $cameraAccept = collect(explode(',', $accept))->map(fn ($type) => trim($type))
        ->filter(fn ($type) => str_starts_with($type, 'image/'))->implode(',') ?: 'image/*';
@endphp

<div class="flex flex-wrap items-center gap-2" data-photo-input
     @if ($showName) x-data="{ name: '' }" x-on:change="name = $event.target.files?.[0]?.name ?? ''" @endif>
    <label class="{{ $cameraClass ?? $buttonClass }} hidden cursor-pointer pointer-coarse:inline-flex" data-photo-camera>
        <x-icon name="camera" class="size-4" /> {{ $cameraLabel }}
        <input type="file" accept="{{ $cameraAccept }}" capture="environment" wire:model="{{ $model }}" class="sr-only">
    </label>
    <label class="{{ $buttonClass }} cursor-pointer" data-photo-file>
        <x-icon name="photo" class="size-4" />
        <span class="pointer-coarse:hidden">{{ $label }}</span>
        <span class="hidden pointer-coarse:inline">{{ $galleryLabel }}</span>
        <input type="file" accept="{{ $accept }}" wire:model="{{ $model }}" {{ $attributes->merge(['class' => 'sr-only']) }}>
    </label>
    @if ($showName)
        <span x-show="name" x-text="name" x-cloak class="min-w-0 max-w-56 truncate text-sm text-stone-600"></span>
    @endif
</div>
