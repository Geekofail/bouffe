{{-- Nutri-Score estimé (lot 43) : une pastille simple, jamais le logo officiel. Couleurs fixes, identiques en mode sombre. --}}
@props(['letter' => null, 'label' => true, 'size' => 'md'])

@php
    $colors = [
        'A' => 'background:#038141;color:#fff',
        'B' => 'background:#85bb2f;color:#fff',
        'C' => 'background:#fecb02;color:#1c1917',
        'D' => 'background:#ee8100;color:#fff',
        'E' => 'background:#e63e11;color:#fff',
    ];
    $title = $letter ? 'Nutri-Score estimé '.$letter.' : '.\App\Services\Nutrition\NutriScore::LETTERS[$letter].' (calcul Grignott.in d\'après la table Ciqual)' : '';
@endphp

@if ($letter && isset($colors[$letter]))
    @if ($label)
        <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full bg-stone-100 py-0.5 pr-2.5 pl-0.5 text-sm']) }} title="{{ $title }}" data-nutri-score="{{ $letter }}">
            <span @class(['grid place-items-center rounded-full font-bold', 'size-6 text-sm' => $size === 'md', 'size-5 text-xs' => $size === 'sm']) style="{{ $colors[$letter] }}" aria-hidden="true">{{ $letter }}</span>
            <span class="whitespace-nowrap text-stone-700">Nutri-Score <span class="text-stone-500">estimé</span></span>
            <span class="sr-only">{{ $letter }}</span>
        </span>
    @else
        <span {{ $attributes->merge(['class' => 'grid shrink-0 place-items-center rounded-full font-bold '.($size === 'sm' ? 'size-5 text-xs' : 'size-6 text-sm')]) }} style="{{ $colors[$letter] }}" title="{{ $title }}" data-nutri-score="{{ $letter }}">
            {{ $letter }}<span class="sr-only"> (Nutri-Score estimé)</span>
        </span>
    @endif
@endif
