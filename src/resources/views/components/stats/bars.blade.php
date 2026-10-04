{{--
    Barres horizontales (lot 35) : une seule série, valeur au bout de la barre.
    :rows = [['label' => …, 'value' => int, 'href' => …, 'title' => …], …]
--}}
@props(['rows', 'unit' => ''])
@php $max = max(1, collect($rows)->max('value')); @endphp
<ul class="space-y-2">
    @foreach ($rows as $row)
        <li class="grid grid-cols-1 items-center gap-x-3 gap-y-0.5 text-sm sm:grid-cols-[minmax(0,16rem)_1fr]" title="{{ $row['title'] ?? $row['label'].' : '.$row['value'].$unit }}">
            @if (! empty($row['href']))
                <a href="{{ $row['href'] }}" wire:navigate class="truncate font-medium text-stone-800 hover:text-brand-700">{{ $row['label'] }}</a>
            @else
                <span class="truncate font-medium text-stone-800">{{ $row['label'] }}</span>
            @endif
            <span class="flex items-center gap-2">
                <span class="h-4 rounded-r bg-brand-500" style="width: {{ max(2, round($row['value'] / $max * 85)) }}%"></span>
                <span class="whitespace-nowrap text-stone-600 tabular-nums">{{ $row['value'] }}{{ $unit }}</span>
            </span>
        </li>
    @endforeach
</ul>
