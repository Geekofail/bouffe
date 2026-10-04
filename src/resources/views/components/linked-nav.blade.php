{{-- Sous-navigation « Proches » (lot 26). --}}
@php
    $sections = [
        ['route' => 'linked.index', 'label' => 'Foyers reliés', 'match' => ['linked.index', 'linked.planning', 'linked.meal', 'linked.list', 'linked.accept']],
        ['route' => 'recipes.index', 'label' => 'Recettes des proches', 'params' => ['source' => 'proches'], 'match' => []],
        ['route' => 'linked.surplus', 'label' => 'Surplus à donner', 'match' => ['linked.surplus']],
        ['route' => 'linked.book', 'label' => 'Carnet familial', 'match' => ['linked.book']],
    ];
@endphp
<nav class="-mx-4 mb-6 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="Sections des proches">
    <div class="flex min-w-max gap-1 rounded-xl bg-stone-100 p-1">
        @foreach ($sections as $section)
            @php $active = $section['match'] !== [] && request()->routeIs(...$section['match']); @endphp
            <a href="{{ route($section['route'], $section['params'] ?? []) }}" wire:navigate
               @class([
                   'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                   'bg-white text-stone-900 shadow-sm' => $active,
                   'text-stone-600 hover:text-stone-900' => ! $active,
               ])
               @if ($active) aria-current="page" @endif>
                {{ $section['label'] }}
            </a>
        @endforeach
    </div>
</nav>
