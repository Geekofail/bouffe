{{--
    Étiquette à découper et à coller sur une boîte (lot 18 ; reprise pour les gamelles, lot 32).
    Le QR code est dessiné dans le navigateur par <x-box-labels>.
--}}
@props(['title', 'qr' => null, 'qrLabel' => null])

<div {{ $attributes->merge(['class' => 'flex items-center gap-3 rounded-lg border-2 border-dashed border-stone-300 p-3 print:break-inside-avoid']) }}>
    <div class="min-w-0 flex-1">
        <p class="truncate text-lg leading-tight font-bold text-stone-900">{{ $title }}</p>
        {{ $slot }}
    </div>

    @if ($qr)
        <canvas class="size-20 shrink-0" data-qr="{{ $qr }}" aria-label="{{ $qrLabel ?? 'QR code' }}"></canvas>
    @endif
</div>
