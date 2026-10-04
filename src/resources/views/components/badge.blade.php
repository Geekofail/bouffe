@props(['color' => null])

<span {{ $attributes->merge(['class' => 'inline-flex max-w-full items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap overflow-hidden ring-1 ring-inset '.\App\Support\Palette::badge($color)]) }}>
    {{ $slot }}
</span>
