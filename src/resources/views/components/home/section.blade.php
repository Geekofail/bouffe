{{--
    Bloc repliable de l'accueil (lot 11). L'état replié est mémorisé sur l'appareil.
    <x-home.section key="menu" title="Au menu aujourd'hui" icon="calendar" :link="route('planner.week')" link-label="Voir la semaine"> … </x-home.section>
--}}
@props(['key', 'title', 'icon' => null, 'link' => null, 'linkLabel' => null, 'count' => null])

<section {{ $attributes->merge(['class' => 'card mb-6 break-inside-avoid p-5']) }}
         x-data="{ open: (() => { try { return localStorage.getItem('bouffe-home-{{ $key }}') !== 'closed'; } catch (e) { return true; } })() }"
         x-init="$watch('open', value => { try { localStorage.setItem('bouffe-home-{{ $key }}', value ? 'open' : 'closed'); } catch (e) {} })"
         data-home-section="{{ $key }}">
    <div class="flex items-center justify-between gap-3" x-bind:class="open && 'mb-4'">
        <button type="button" x-on:click="open = ! open" class="flex min-w-0 items-center gap-2 text-left" x-bind:aria-expanded="open">
            @if ($icon) <x-icon :name="$icon" class="size-5 shrink-0 text-stone-400" /> @endif
            <h2 class="font-display text-lg font-semibold text-stone-900">{{ $title }}</h2>
            @if ($count !== null)
                <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-600 tabular-nums">{{ $count }}</span>
            @endif
            <span class="text-stone-400 transition" x-bind:class="! open && '-rotate-90'"><x-icon name="chevron-down" class="size-4" /></span>
        </button>
        @if ($link)
            <a href="{{ $link }}" wire:navigate class="text-sm font-medium whitespace-nowrap text-brand-700 hover:underline">{{ $linkLabel }}</a>
        @endif
    </div>
    <div x-show="open">
        {{ $slot }}
    </div>
</section>
