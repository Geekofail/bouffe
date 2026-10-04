{{--
    Écran vide (lot 29, 29.7) : une illustration ou une icône, un titre, une phrase, et les premiers pas.

    <x-empty-state icon="receipt" title="Aucun ticket ce mois-ci">
        Photographiez un ticket : la dépense, les prix et le stock se remplissent seuls.
        <x-slot:actions> <a class="btn btn-primary" …>Photographier un ticket</a> </x-slot:actions>
    </x-empty-state>
--}}
@props(['icon' => 'sparkles', 'title', 'dish' => null, 'tone' => 'plat'])

<div class="flex flex-col items-center px-6 py-12 text-center">
    @if ($dish)
        <x-dish-illustration :kind="$dish" :tone="$tone" class="mb-4 size-24 rounded-3xl" />
    @else
        <div class="mb-4 flex size-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-700">
            <x-icon :name="$icon" class="size-7" />
        </div>
    @endif
    <p class="font-display text-lg font-semibold text-stone-900">{{ $title }}</p>
    @if (trim($slot) !== '')
        <div class="mt-1 max-w-md text-sm text-stone-600">{{ $slot }}</div>
    @endif
    @isset($actions)
        <div class="mt-5 flex flex-wrap justify-center gap-2">{{ $actions }}</div>
    @endisset
</div>
