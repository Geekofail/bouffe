{{--
    Illustration d'un plat (lot 29, 29.3) : dessin simple sur fond doux, teinté selon le type de plat.
    La taille vient de la classe donnée par l'appelant.

    <x-dish-illustration :recipe="$recipe" class="size-12 rounded-xl" />
    <x-dish-illustration kind="soupe" tone="entree" class="size-24 rounded-2xl" />
--}}
@props(['recipe' => null, 'course' => null, 'kind' => null, 'tone' => null, 'inner' => 'size-[72%]'])

@php
    $pick = $recipe ? \App\Support\DishIllustration::for($recipe, $course) : ['kind' => 'assiette', 'tone' => 'plat'];
    $kind = in_array($kind, \App\Support\DishIllustration::KINDS, true) ? $kind : $pick['kind'];
    $tone = in_array($tone, \App\Support\DishIllustration::TONES, true) ? $tone : $pick['tone'];
    $var = $tone === 'accompagnement' ? 'side' : $tone;
    $ink = "var(--color-course-{$var}-ink)";
    $mid = "var(--color-course-{$var}-mid)";
    $light = 'var(--color-white)';
@endphp

<span {{ $attributes->merge(['class' => 'flex shrink-0 items-center justify-center overflow-hidden', 'style' => "background: var(--color-course-{$var})"]) }}
      data-dish="{{ $kind }}" data-tone="{{ $tone }}" aria-hidden="true">
    <svg viewBox="0 0 64 64" class="{{ $inner }}" fill="none" style="stroke: {{ $ink }}" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
        @switch($kind)
            @case('soupe')
                <path d="M10 30h44a22 20 0 0 1-44 0z" style="fill: {{ $mid }}" />
                <path d="M22 53h20" />
                <path d="M24 22c-2.5-3 2.5-5 0-9M32 22c-2.5-3 2.5-5 0-9M40 22c-2.5-3 2.5-5 0-9" />
                @break
            @case('salade')
                <path d="M17 30c-1-9 6-15 13-15-1 7-5 12-13 15z" style="fill: {{ $mid }}" />
                <path d="M47 30c1-9-6-15-13-15 1 7 5 12 13 15z" style="fill: {{ $mid }}" />
                <path d="M32 30c-4-6-2-13 4-17 2 7 1 12-4 17z" style="fill: {{ $mid }}" />
                <path d="M10 32h44a22 18 0 0 1-44 0z" style="fill: {{ $light }}" />
                <path d="M22 53h20" />
                @break
            @case('tarte')
                <path d="M9 34h46l-5 13H14z" style="fill: {{ $light }}" />
                <path d="M11 34c3-8 39-8 42 0" style="fill: {{ $mid }}" />
                <path d="M23 30v-2M32 29v-2M41 30v-2" />
                <path d="M14 47h36" />
                @break
            @case('pates')
                <ellipse cx="32" cy="42" rx="24" ry="9" style="fill: {{ $light }}" />
                <path d="M18 40c3-12 25-12 28 0" style="fill: {{ $mid }}" />
                <path d="M23 40c2-7 16-7 18 0M27 40c1-4 9-4 10 0" />
                <path d="M48 12v16M44 12v7M52 12v7M44 19c0 4 8 4 8 0" />
                @break
            @case('curry')
                <path d="M10 32h44a22 19 0 0 1-44 0z" style="fill: {{ $mid }}" />
                <path d="M18 32c2-9 26-9 28 0" style="fill: {{ $light }}" />
                <circle cx="26" cy="38" r="1.6" style="fill: {{ $ink }}" />
                <circle cx="36" cy="41" r="1.6" style="fill: {{ $ink }}" />
                <circle cx="42" cy="36" r="1.6" style="fill: {{ $ink }}" />
                <path d="M22 53h20" />
                @break
            @case('crepes')
                <ellipse cx="32" cy="45" rx="23" ry="7" style="fill: {{ $light }}" />
                <path d="M11 40c0 4 10 7 21 7s21-3 21-7" style="fill: {{ $mid }}" />
                <path d="M13 35c0 4 9 7 19 7s19-3 19-7c0-4-9-7-19-7s-19 3-19 7z" style="fill: {{ $mid }}" />
                <path d="M28 26l6-10 6 10" style="fill: {{ $light }}" />
                @break
            @case('gratin')
                <rect x="10" y="28" width="44" height="20" rx="5" style="fill: {{ $light }}" />
                <path d="M12 34c6-4 10 3 16-1s10 3 16-1 8 2 8 2" />
                <path d="M4 34h6M54 34h6" />
                <circle cx="22" cy="41" r="2.4" style="fill: {{ $mid }}" />
                <circle cx="34" cy="42" r="3" style="fill: {{ $mid }}" />
                <circle cx="45" cy="40" r="2" style="fill: {{ $mid }}" />
                @break
            @case('gateau')
                <path d="M12 46V33l38-9v22z" style="fill: {{ $light }}" />
                <path d="M12 39.5l38-7M12 46h38" />
                <path d="M12 33l38-9v-2c-10-2-28 4-38 11z" style="fill: {{ $mid }}" />
                <circle cx="41" cy="20" r="3.2" style="fill: {{ $mid }}" />
                <path d="M41 17c1-3 3-5 6-6" />
                @break
            @default
                <circle cx="32" cy="34" r="17" style="fill: {{ $light }}" />
                <circle cx="32" cy="34" r="10" style="fill: {{ $mid }}" />
                <path d="M8 14v10M5 14v6M11 14v6M5 20c0 3 6 3 6 0M8 24v28" />
                <path d="M57 14c-4 2-5 8-5 14h5v24" />
        @endswitch
    </svg>
</span>
