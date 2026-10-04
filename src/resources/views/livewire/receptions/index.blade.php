<div class="mx-auto max-w-5xl">
    <x-page-header title="Réceptions" subtitle="Un repas avec des invités : le menu, ce qu'il faut faire et quand, puis un souvenir.">
        @if (auth()->user()->canEdit())
            <x-slot:actions>
                <button type="button" wire:click="$toggle('showCreate')" class="btn btn-primary">
                    <x-icon name="plus" class="size-4" /> Organiser une réception
                </button>
            </x-slot:actions>
        @endif
    </x-page-header>

    {{-- Invitations des foyers reliés (26.5) --}}
    @if ($invitations->isNotEmpty())
        <section class="card mb-6 p-4 sm:p-5">
            <h2 class="font-display mb-2 flex items-center gap-2 font-semibold text-stone-900"><x-icon name="heart" class="size-5 text-brand-600" /> Invitations de vos proches</h2>
            <ul class="divide-y divide-stone-100">
                @foreach ($invitations as $row)
                    <li wire:key="inv-{{ $row->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                        <span class="min-w-0 flex-1 text-stone-800">{{ $row->occasion->title ?: 'Repas' }} — {{ $row->occasion->household?->name }} · {{ ucfirst($row->occasion->date->locale('fr')->isoFormat('dddd D MMMM')) }}</span>
                        <span class="text-sm text-stone-500">{{ $row->status === 'invited' ? 'À répondre' : \App\Models\MealOccasionHousehold::STATUSES[$row->status] }}</span>
                        <a href="{{ route('linked.meal', $row->id) }}" wire:navigate class="btn btn-secondary py-1 text-sm">Ouvrir</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($showCreate)
        <form wire:submit="create" class="card mb-6 grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-field label="Nom" for="reception-title" error="title" class="sm:col-span-2 lg:col-span-4">
                <input id="reception-title" type="text" wire:model="title" placeholder="ex. Anniversaire de Julie" class="form-input" autofocus>
            </x-field>
            <x-field label="Date" for="reception-date" error="date">
                <input id="reception-date" type="date" wire:model="date" class="form-input">
            </x-field>
            <x-field label="Créneau" for="reception-slot" error="slotId">
                <select id="reception-slot" wire:model="slotId" class="form-input">
                    @foreach ($this->slots as $slot) <option value="{{ $slot->id }}">{{ $slot->name }}</option> @endforeach
                </select>
            </x-field>
            <x-field label="Heure" for="reception-time" error="serveTime" help="Arrivée des invités.">
                <input id="reception-time" type="time" wire:model="serveTime" class="form-input">
            </x-field>
            <div class="flex items-end">
                <button type="submit" class="btn btn-primary w-full">Créer</button>
            </div>
        </form>
    @endif

    <h2 class="font-display mb-3 text-lg font-semibold text-stone-900">À venir</h2>
    @if ($this->upcoming->isEmpty())
        <div class="card mb-8">
            <x-empty-state dish="gateau" tone="dessert" title="Aucune réception prévue">
                Ajoutez des invités à un repas du planning : la réception apparaît ici, avec son menu et son rétroplanning.
                <x-slot:actions>
                    <a href="{{ route('planner.week') }}" wire:navigate class="btn btn-secondary"><x-icon name="calendar" class="size-4" /> Ouvrir le planning</a>
                </x-slot:actions>
            </x-empty-state>
        </div>
    @else
        <ul class="mb-8 grid gap-4 sm:grid-cols-2">
            @foreach ($this->upcoming as $occasion)
                @php
                    $timeline = $planner->timeline($occasion);
                    $next = $timeline->first(fn ($t) => ! $t['done']);
                    $dishes = $occasion->meals()->count();
                @endphp
                <li wire:key="upcoming-{{ $occasion->id }}">
                    <a href="{{ route('receptions.show', $occasion) }}" wire:navigate class="card block h-full p-4 transition hover:ring-brand-300">
                        <p class="text-xs font-semibold tracking-wide text-violet-700 uppercase">
                            {{ ucfirst($occasion->serveAt()->locale('fr')->isoFormat('dddd D MMMM')) }}{{ $occasion->serve_time ? ' · '.str_replace(':', ' h ', $occasion->serve_time) : '' }}
                        </p>
                        <p class="mt-1 text-lg font-semibold text-stone-900">{{ $receptions->name($occasion) }}</p>
                        <p class="text-sm text-stone-500">
                            {{ $planner->diners($occasion) }} à table · {{ $dishes }} plat{{ $dishes > 1 ? 's' : '' }}
                            @if ($occasion->guests->isNotEmpty()) · {{ $occasion->guests->pluck('name')->join(', ') }} @endif
                        </p>
                        @if ($next)
                            <p class="mt-3 flex items-start gap-1.5 rounded-md bg-sky-50 px-2 py-1.5 text-sm text-sky-900">
                                <x-icon name="clock" class="mt-0.5 size-4 shrink-0" />
                                <span>{{ $next['title'] }} — {{ $next['at']->locale('fr')->isoFormat('ddd D, HH[h]mm') }}</span>
                            </p>
                        @elseif ($dishes === 0)
                            <p class="mt-3 text-sm text-amber-700">Menu à composer</p>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <h2 class="font-display mb-3 text-lg font-semibold text-stone-900">Souvenirs</h2>
    @if ($this->past->isEmpty())
        <p class="text-sm text-stone-500">Les réceptions passées apparaîtront ici, avec leur photo et une note.</p>
    @else
        <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->past as $occasion)
                <li wire:key="past-{{ $occasion->id }}">
                    <a href="{{ route('receptions.show', $occasion) }}" wire:navigate class="card block h-full overflow-hidden transition hover:ring-brand-300">
                        @if ($url = $receptions->photoUrl($occasion))
                            <img src="{{ $url }}" alt="" class="aspect-video w-full object-cover" loading="lazy">
                        @endif
                        <div class="p-4">
                            <p class="font-semibold text-stone-900">{{ $receptions->name($occasion) }}</p>
                            <p class="text-sm text-stone-500">{{ ucfirst($occasion->date->locale('fr')->isoFormat('D MMMM YYYY')) }}@if ($occasion->guests->isNotEmpty()) · {{ $occasion->guests->pluck('name')->join(', ') }}@endif</p>
                            @if ($occasion->memory_note)
                                <p class="mt-2 line-clamp-3 text-sm text-stone-600 italic">« {{ $occasion->memory_note }} »</p>
                            @endif
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
