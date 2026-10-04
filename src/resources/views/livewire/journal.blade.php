<div>
    <x-page-header title="Journal du foyer" subtitle="Qui a fait quoi ces 30 derniers jours. Seuls les membres du foyer le voient." />
    <x-settings-nav />

    <div class="mb-6 flex flex-wrap items-center gap-2">
        <select wire:model.live="person" class="form-input w-auto" aria-label="Personne">
            <option value="0">Tout le monde</option>
            @foreach ($this->members as $member)
                <option value="{{ $member->id }}">{{ $member->name }}</option>
            @endforeach
        </select>

        <div class="flex flex-wrap gap-1.5" role="group" aria-label="Type d'action">
            <button type="button" wire:click="$set('family', '')" aria-pressed="{{ $family === '' ? 'true' : 'false' }}"
                    @class(['min-h-9 rounded-full px-3 text-sm font-medium ring-1 ring-inset transition', 'bg-stone-900 text-white ring-stone-900' => $family === '', 'bg-white text-stone-600 ring-stone-200 hover:text-stone-900' => $family !== ''])>Tout</button>
            @foreach ($families as $key => $label)
                <button type="button" wire:click="$set('family', '{{ $key }}')" aria-pressed="{{ $family === $key ? 'true' : 'false' }}"
                        @class(['min-h-9 rounded-full px-3 text-sm font-medium ring-1 ring-inset transition', 'bg-stone-900 text-white ring-stone-900' => $family === $key, 'bg-white text-stone-600 ring-stone-200 hover:text-stone-900' => $family !== $key])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    @forelse ($this->days as $date => $events)
        <section class="mb-6" wire:key="day-{{ $date }}">
            <h2 class="section-title mb-2">{{ $this->dayLabel($date) }}</h2>
            <ul class="card divide-y divide-stone-100">
                @foreach ($events as $event)
                    <li class="flex items-start gap-3 px-4 py-3" wire:key="event-{{ $event->id }}">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-800" aria-hidden="true">
                            {{ mb_strtoupper(mb_substr($event->user?->name ?? '?', 0, 1)) }}
                        </span>
                        <p class="min-w-0 flex-1 text-sm text-stone-700">
                            <span class="font-semibold text-stone-900">{{ $event->user?->name ?? 'Quelqu\'un' }}</span>
                            {{ $event->summary }}
                        </p>
                        <time class="shrink-0 text-xs text-stone-500 tabular-nums" datetime="{{ $event->updated_at->toIso8601String() }}">{{ $event->updated_at->format('H:i') }}</time>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <div class="card">
            <x-empty-state icon="clock" title="Rien dans le journal">
                Les gestes du foyer apparaissent ici : repas planifiés, articles cochés, courses rangées, envies…
            </x-empty-state>
        </div>
    @endforelse
</div>
