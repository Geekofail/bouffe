@props(['icon', 'lot'])

<div class="card flex flex-col items-center px-6 py-14 text-center">
    <div class="mb-4 rounded-full bg-brand-50 p-4 text-brand-600">
        <x-icon :name="$icon" class="size-8" />
    </div>
    <span class="mb-2 rounded-full bg-stone-100 px-3 py-1 text-xs font-semibold tracking-wide text-stone-600 uppercase">
        Arrive au {{ $lot }}
    </span>
    <p class="max-w-md text-stone-600">{{ $slot }}</p>
</div>
