{{-- Lot 39 (39.2) : le midi à la cantine, dans la case du déjeuner. --}}
@foreach ($entries->groupBy(fn ($entry) => (string) $entry['label']) as $label => $group)
    <a href="{{ route('planner.canteen', ['semaine' => $dateKey]) }}" wire:navigate data-canteen-chip
       class="flex min-w-0 items-start gap-1 rounded-md bg-sky-50 px-1.5 py-1 text-xs text-sky-900 ring-1 ring-sky-200 hover:bg-sky-100"
       title="Cantine : {{ $group->pluck('person.name')->join(', ', ' et ') }}{{ $label !== '' ? ' — '.$label : ' (menu pas encore noté)' }}">
        <x-icon name="school" class="mt-px size-3.5 shrink-0" />
        <span class="min-w-0">
            <span class="font-medium">Cantine</span> · {{ $group->pluck('person.name')->join(', ', ' et ') }}
            @if ($label !== '') <span class="block truncate text-sky-800">{{ $label }}</span> @endif
        </span>
    </a>
@endforeach
