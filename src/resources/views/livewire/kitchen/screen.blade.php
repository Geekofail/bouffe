{{-- Écran de cuisine (35.1) : texte grand, gros boutons, rafraîchi seul toutes les minutes. --}}
<div wire:poll.60s class="space-y-5 text-lg" data-kitchen-screen>
    {{-- ============================================================ En-tête --}}
    <header class="flex flex-wrap items-center gap-x-6 gap-y-2">
        <p class="font-display text-5xl font-semibold text-stone-900 tabular-nums sm:text-6xl" x-text="time" aria-live="off">{{ now()->format('H:i') }}</p>
        <div class="order-last min-w-0 basis-full sm:order-none sm:basis-auto sm:flex-1">
            <p class="text-2xl font-semibold text-stone-800">{{ ucfirst($today->locale('fr')->isoFormat('dddd D MMMM')) }}</p>
            <p class="text-sm text-stone-500">
                <span x-show="window.bouffeWakeLock.supported">Écran maintenu allumé</span>
                <span x-show="! window.bouffeWakeLock.supported" x-cloak>L'écran peut se mettre en veille (connexion non sécurisée)</span>
                · mis à jour à {{ $updatedAt->format('H:i') }}
            </p>
        </div>
        <a href="{{ route('dashboard') }}" class="btn btn-secondary ml-auto min-h-12 px-4 text-base">
            <x-icon name="close" class="size-5" /> Quitter
        </a>
    </header>

    {{-- ============================================================ Le choix des enfants (lot 39, 39.3) --}}
    @foreach ($choices as $choice)
        <a href="{{ route('kitchen.choice', ['choix' => $choice->id]) }}" wire:key="k-choice-{{ $choice->id }}" wire:navigate data-kitchen-choice
           class="card flex min-h-16 items-center gap-4 p-4 text-xl text-stone-900 ring-2 ring-brand-200 hover:bg-brand-50">
            <x-icon name="smile" class="size-8 shrink-0 text-brand-600" />
            <span class="min-w-0 flex-1"><strong>{{ $choice->person?->name ?? 'Les enfants' }}</strong> : {{ $choice->person ? 'à toi' : 'à vous' }} de choisir le {{ $choice->dayLabel() }} !</span>
            <x-icon name="chevron-right" class="size-6 shrink-0 text-stone-400" />
        </a>
    @endforeach

    <div class="grid gap-5 lg:grid-cols-3">
        {{-- ============================================================ Menus --}}
        <div class="space-y-5 lg:col-span-2">
            <section class="card p-5" aria-labelledby="k-today">
                <h2 id="k-today" class="font-display mb-3 text-3xl font-semibold text-stone-900">Aujourd'hui</h2>
                @include('livewire.kitchen.day', ['rows' => $todayMeals, 'date' => $today, 'big' => true])
            </section>

            <section class="card p-5" aria-labelledby="k-tomorrow">
                <h2 id="k-tomorrow" class="font-display mb-3 text-2xl font-semibold text-stone-900">
                    Demain <span class="text-lg font-normal text-stone-500">{{ $tomorrow->locale('fr')->isoFormat('dddd D') }}</span>
                </h2>
                @include('livewire.kitchen.day', ['rows' => $tomorrowMeals, 'date' => $tomorrow, 'big' => false])
            </section>
        </div>

        <div class="space-y-5">
            {{-- ============================================================ Minuteurs --}}
            <section class="card p-5" aria-labelledby="k-timers">
                <h2 id="k-timers" class="font-display mb-3 text-2xl font-semibold text-stone-900">Minuteurs</h2>
                {{-- Lot 41 (41.1) : ceux de toute la maison, lancés de n'importe quel appareil. --}}
                <x-timers source="cuisine" :big="true">
                    <p x-show="timers.length === 0" class="text-base text-stone-500">Aucun minuteur en cours.</p>
                    <div class="grid grid-cols-4 gap-2 pt-1">
                        @foreach ([5, 10, 15, 30] as $minutes)
                            <button type="button" x-on:click="add({{ $minutes }}, '{{ $minutes }} min')" class="btn btn-secondary min-h-12 justify-center text-base">
                                +{{ $minutes }}
                            </button>
                        @endforeach
                    </div>
                    <p class="text-xs text-stone-500">Les minuteurs lancés sur un téléphone apparaissent ici, et s'arrêtent de n'importe quel appareil.</p>
                </x-timers>
            </section>

            {{-- ============================================================ À préparer --}}
            @if ($reminders->isNotEmpty())
                <section class="card p-5" aria-labelledby="k-reminders">
                    <h2 id="k-reminders" class="font-display mb-3 text-2xl font-semibold text-stone-900">À préparer</h2>
                    <ul class="divide-y divide-stone-100">
                        @foreach ($reminders as $reminder)
                            <li wire:key="k-reminder-{{ $reminder->id }}" class="flex items-center gap-3 py-2">
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-stone-900">{{ $reminder->title }}</p>
                                    <p class="text-sm text-stone-500">
                                        @if ($reminder->due_at->isToday()) aujourd'hui {{ $reminder->due_at->format('H:i') }}
                                        @elseif ($reminder->due_at->isTomorrow()) demain {{ $reminder->due_at->format('H:i') }}
                                        @else <span class="font-medium text-amber-800">en retard</span> ({{ $reminder->due_at->locale('fr')->isoFormat('ddd D') }})
                                        @endif
                                        · pour {{ mb_strtolower($reminder->meal->slot->name) }} {{ $reminder->meal->date->isToday() ? 'aujourd\'hui' : $reminder->meal->date->locale('fr')->isoFormat('dddd') }}
                                    </p>
                                </div>
                                @if (auth()->user()->canEdit())
                                    <button type="button" wire:click="reminderDone({{ $reminder->id }})" class="btn btn-secondary min-h-12 px-3 text-base">
                                        <x-icon name="check" class="size-5" /> Fait
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- ============================================================ Courses --}}
            <section class="card p-5" aria-labelledby="k-shopping">
                <h2 id="k-shopping" class="font-display mb-1 text-2xl font-semibold text-stone-900">Courses</h2>
                @if ($list)
                    <p class="mb-3 text-sm text-stone-500">{{ $list->name }} · {{ $items->count() + $more }} à acheter</p>
                    @if ($items->isEmpty())
                        <p class="text-base text-stone-500">Tout est coché.</p>
                    @else
                        <ul class="grid grid-cols-1 gap-x-4 text-base sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                            @foreach ($items as $item)
                                <li wire:key="k-item-{{ $item->id }}" class="truncate border-b border-stone-100 py-1.5">{{ app(\App\Services\Shopping\ShoppingItemPresenter::class)->text($item) }}</li>
                            @endforeach
                        </ul>
                        @if ($more > 0) <p class="mt-2 text-sm text-stone-500">et {{ $more }} autre{{ $more > 1 ? 's' : '' }}.</p> @endif
                    @endif
                @else
                    <p class="mb-3 text-base text-stone-500">Pas de liste en cours.</p>
                @endif

                @if (auth()->user()->canEdit())
                    <form wire:submit="addItem" class="mt-3 flex gap-2">
                        <label for="k-new-item" class="sr-only">Ajouter à la liste</label>
                        <input id="k-new-item" type="text" wire:model="newItem" maxlength="120" placeholder="Il manque…" autocomplete="off"
                               @class(['form-input min-h-12 min-w-0 flex-1 text-base', 'form-input-error' => $errors->has('newItem')])>
                        <button type="submit" class="btn btn-primary min-h-12 px-4 text-base"><x-icon name="plus" class="size-5" /><span class="sr-only">Ajouter</span></button>
                    </form>
                    @error('newItem') <p class="form-error mt-1">{{ $message }}</p> @enderror
                @endif
            </section>
        </div>
    </div>
</div>
