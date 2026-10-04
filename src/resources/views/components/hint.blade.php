{{--
    Aide contextuelle (lot 30, 30.3) : une bulle « Le saviez-vous ? » la première fois qu'on ouvre un écran riche.
    « Compris » la fait disparaître pour de bon (préférence hints_seen) ; toutes se réactivent dans Paramètres › Affichage.

    <x-hint key="planning" title="Le saviez-vous ?">Glissez un repas…</x-hint>
--}}
@props(['key', 'title' => 'Le saviez-vous ?'])

@php
    $user = auth()->user();
    $visible = $user && ! $user->preference('hints_off', false) && ! in_array($key, (array) $user->preference('hints_seen', []), true);
@endphp

@if ($visible)
    <aside x-data="{ open: true, seen() { this.open = false; fetch(@js(route('hints.seen', $key)), { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '', 'Accept': 'application/json' } }); } }"
           x-show="open" x-transition.opacity data-hint="{{ $key }}"
           {{ $attributes->merge(['class' => 'mb-4 flex flex-wrap items-start gap-x-3 gap-y-2 rounded-xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200 print:hidden']) }}
           aria-label="{{ $title }}">
        <x-icon name="sparkles" class="mt-0.5 size-5 shrink-0 text-amber-700" />
        <div class="min-w-0 flex-1 basis-56">
            <p class="font-semibold">{{ $title }}</p>
            <div class="mt-0.5 text-amber-800">{{ $slot }}</div>
        </div>
        <button type="button" x-on:click="seen()" class="btn btn-secondary ml-8 shrink-0 px-3 py-1.5 sm:-my-1 sm:ml-0">Compris</button>
    </aside>
@endif
