{{--
    Fenêtre modale pilotée par Livewire.

    <x-modal :show="$showForm" title="Nouvel ingrédient" close="closeForm">
        … contenu …
        <x-slot:footer> … boutons … </x-slot:footer>
    </x-modal>

    - :show  : propriété booléenne du composant
    - close  : méthode Livewire appelée par Échap, clic sur le fond ou la croix
--}}
@props(['show' => false, 'title' => '', 'close' => 'closeForm', 'maxWidth' => 'max-w-lg'])

@if ($show)
    <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-6"
         x-data
         x-on:keydown.escape.window="$wire.{{ $close }}()"
         role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="absolute inset-0 bg-stone-900/40" wire:click="{{ $close }}"></div>

        <div {{ $attributes->merge(['class' => "relative flex max-h-[92vh] w-full {$maxWidth} flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-2xl"]) }}>
            <div class="flex items-center justify-between border-b border-stone-200 px-5 py-4">
                <h2 id="modal-title" class="text-lg font-semibold text-stone-900">{{ $title }}</h2>
                <button type="button" wire:click="{{ $close }}" class="btn btn-ghost -mr-2 px-2" title="Fermer">
                    <x-icon name="close" class="size-5" />
                    <span class="sr-only">Fermer</span>
                </button>
            </div>

            <div class="overflow-y-auto px-5 py-4">
                {{ $slot }}
            </div>

            @isset($footer)
                <div class="flex justify-end gap-2 border-t border-stone-200 bg-stone-50 px-5 py-3 sm:rounded-b-2xl">
                    {{ $footer }}
                </div>
            @endisset
        </div>
    </div>
@endif
