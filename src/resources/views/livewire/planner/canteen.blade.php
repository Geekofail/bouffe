<div class="max-w-4xl">
    <a href="{{ route('planner.week', ['semaine' => $monday->toDateString()]) }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> Planning
    </a>
    <x-page-header title="Cantine" subtitle="Ce qui est servi le midi à l'école : compté dans l'équilibre de la semaine, évité le soir." />

    @if ($people->isEmpty())
        <div class="card p-6">
            <x-empty-state icon="school" title="Personne ne mange à la cantine">
                Dans <a href="{{ route('settings.household') }}" wire:navigate class="font-medium text-brand-700 underline">Paramètres › Foyer</a>,
                ouvrez une personne et cochez ses jours de cantine.
            </x-empty-state>
        </div>
    @else
        {{-- ============================================================ Semaine --}}
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <button type="button" wire:click="previousWeek" class="btn btn-secondary min-h-11 px-3" title="Semaine précédente"><x-icon name="chevron-left" class="size-4" /><span class="sr-only">Semaine précédente</span></button>
            <h2 class="min-w-0 flex-1 text-center font-display text-lg font-semibold text-stone-900 sm:flex-none">
                Semaine du {{ $monday->locale('fr')->isoFormat('D MMMM') }}
            </h2>
            <button type="button" wire:click="nextWeek" class="btn btn-secondary min-h-11 px-3" title="Semaine suivante"><x-icon name="chevron-right" class="size-4" /><span class="sr-only">Semaine suivante</span></button>
        </div>

        @unless ($lunch)
            <p class="mb-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200">
                Aucun créneau « Déjeuner » ou « Midi » n'est actif : la cantine ne retire personne des portions.
                (<a href="{{ route('settings.slots') }}" wire:navigate class="underline">Paramètres › Créneaux</a>)
            </p>
        @endunless

        <div class="space-y-4">
            @foreach ($people as $person)
                <section wire:key="canteen-{{ $person->id }}" class="card overflow-hidden" data-canteen-person="{{ $person->name }}">
                    <header class="flex flex-wrap items-center gap-3 border-b border-stone-100 px-4 py-3">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full font-semibold text-white" style="background-color: {{ $person->hex() }}" aria-hidden="true">{{ $person->initial() }}</span>
                        <div class="min-w-0 flex-1">
                            <h3 class="font-semibold text-stone-900">{{ $person->name }}</h3>
                            @if ($person->canteen_name) <p class="text-xs text-stone-500">{{ $person->canteen_name }}</p> @endif
                        </div>
                        @if ($canEdit)
                            <button type="button" wire:click="homeAllWeek({{ $person->id }})" class="btn btn-ghost min-h-11 text-sm">Pas de cantine cette semaine</button>
                        @endif
                    </header>

                    <ul class="divide-y divide-stone-100">
                        @foreach ($days as $date => $entries)
                            @php $entry = $entries->firstWhere('person.id', $person->id); @endphp
                            @continue (! $entry)
                            @php $home = $entry['status'] === \App\Models\CanteenMeal::HOME; @endphp
                            <li wire:key="canteen-{{ $person->id }}-{{ $date }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2.5">
                                <span class="w-24 shrink-0 text-sm font-medium capitalize text-stone-700">{{ \Illuminate\Support\Carbon::parse($date)->locale('fr')->isoFormat('dddd D') }}</span>

                                @if ($home)
                                    <span class="min-w-0 flex-1 basis-48 text-sm text-stone-500">À la maison ce midi</span>
                                @elseif ($canEdit)
                                    <label for="canteen-{{ $person->id }}-{{ $date }}" class="sr-only">Menu de {{ $person->name }}, {{ \Illuminate\Support\Carbon::parse($date)->locale('fr')->isoFormat('dddd') }}</label>
                                    <input id="canteen-{{ $person->id }}-{{ $date }}" type="text" value="{{ $entry['label'] }}" maxlength="200"
                                           wire:change="saveLabel({{ $person->id }}, '{{ $date }}', $event.target.value)"
                                           placeholder="Menu pas encore noté" class="form-input min-w-0 flex-1 basis-48">
                                @else
                                    <span class="min-w-0 flex-1 basis-48 text-sm text-stone-800">{{ $entry['label'] ?: 'Menu pas encore noté' }}</span>
                                @endif

                                @if (! $home && $entry['families'] !== [])
                                    <span class="hidden text-xs text-stone-500 sm:inline">{{ collect($entry['families'])->map(fn ($f) => mb_strtolower(\App\Services\Planning\WeekBalance::FAMILIES[$f][0] ?? $f))->join(', ') }}</span>
                                @endif

                                @if ($canEdit)
                                    <button type="button" wire:click="toggleHome({{ $person->id }}, '{{ $date }}')" aria-pressed="{{ $home ? 'true' : 'false' }}"
                                            class="btn btn-ghost min-h-11 text-sm {{ $home ? 'text-sky-800' : 'text-stone-600' }}">
                                        {{ $home ? 'Remettre la cantine' : 'Pas de cantine' }}
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>

        {{-- ============================================================ Coller ou photographier le menu --}}
        @if ($canEdit)
            <section class="card mt-6 space-y-3 p-4 sm:p-5" aria-labelledby="paste-title">
                <h2 id="paste-title" class="font-display font-semibold text-stone-900">Le menu de l'école</h2>
                <p class="text-sm text-stone-600">
                    Collez le menu de la semaine tel que l'école le publie (site, application, message) : chaque jour
                    nommé (« Lundi : … ») remplit le midi correspondant. Ou prenez-le en photo.
                </p>

                @if ($people->count() > 1)
                    <fieldset class="flex flex-wrap items-center gap-2">
                        <legend class="mb-1 text-sm font-medium text-stone-700">Pour</legend>
                        @foreach ($people as $person)
                            <label wire:key="paste-for-{{ $person->id }}" class="flex min-h-10 cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm ring-1 ring-stone-200 has-checked:bg-sky-50 has-checked:ring-sky-300">
                                <input type="checkbox" wire:model="pasteFor" value="{{ $person->id }}" class="form-checkbox"> {{ $person->name }}
                            </label>
                        @endforeach
                    </fieldset>
                @endif

                <form wire:submit="paste" class="space-y-2">
                    <x-field label="Menu de la semaine" for="canteen-paste" error="pasted">
                        <textarea id="canteen-paste" wire:model="pasted" rows="5" class="form-input" placeholder="Lundi : potage, poisson pané, purée, yaourt&#10;Mardi : …"></textarea>
                    </x-field>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="submit" class="btn btn-primary"><x-icon name="list" class="size-4" /> Remplir la semaine</button>
                        @if ($ocr)
                            <label class="btn btn-secondary cursor-pointer">
                                <x-icon name="camera" class="size-4" /> Photo du menu
                                <input type="file" wire:model="photo" accept="image/*,application/pdf" class="sr-only" data-canteen-photo>
                            </label>
                            <span wire:loading wire:target="photo" class="text-sm text-stone-500">Lecture…</span>
                        @endif
                    </div>
                    @error('photo') <p class="form-error">{{ $message }}</p> @enderror
                </form>
                <p class="text-xs text-stone-500">
                    @if ($ocr)
                        La photo est lue par le service des tickets de caisse (compté dans son plafond) ; seule l'image est envoyée, sans nom.
                    @else
                        La photo demande le service de lecture des tickets (Paramètres › Tickets de caisse).
                    @endif
                    Rien n'est déduit d'un jour sans menu : il reste « cantine ».
                </p>
            </section>
        @endif
    @endif
</div>
