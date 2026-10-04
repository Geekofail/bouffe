@props(['checked', 'total'])

@php $percent = $total > 0 ? (int) round($checked * 100 / $total) : 0; @endphp
<div {{ $attributes->merge(['class' => 'flex items-center gap-3']) }}>
    <div class="h-2 flex-1 overflow-hidden rounded-full bg-stone-100" role="progressbar" aria-label="Articles cochés" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100">
        <div @class(['h-full rounded-full transition-all', 'bg-herb-500' => $percent === 100, 'bg-brand-500' => $percent < 100]) style="width: {{ $percent }}%"></div>
    </div>
    <span class="text-xs font-medium whitespace-nowrap text-stone-500 tabular-nums">{{ $checked }} / {{ $total }}</span>
</div>
