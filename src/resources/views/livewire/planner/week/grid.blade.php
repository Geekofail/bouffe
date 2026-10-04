{{-- Planning : les repas de la semaine, en grille ou en liste (lot 36). --}}
@if ($this->activeSlots->isEmpty())
    <div class="card">
        <x-empty-state icon="clock" title="Aucun créneau actif">
            Activez au moins un créneau (Déjeuner, Dîner…) dans
            <a href="{{ route('settings.slots') }}" wire:navigate class="text-brand-700 underline">Paramètres → Créneaux</a>.
        </x-empty-state>
    </div>
@else
    {{-- ============================================================ Vue liste (14.9) --}}
    @if ($view === 'liste')
        <div class="space-y-3" wire:loading.class="opacity-70" wire:target="previousWeek,nextWeek,currentWeek,clearWeek">
            @foreach ($days as $day)
                @php
                    $dateKey = $day->toDateString();
                    $isToday = $dateKey === $today;
                    $dayMeals = $this->activeSlots->map(fn ($slot) => ['slot' => $slot, 'meals' => $this->meals->get($dateKey.'|'.$slot->id, collect())]);
                @endphp

                <section wire:key="list-{{ $dateKey }}" @class(['card overflow-hidden rounded-2xl', 'ring-2 ring-brand-200' => $isToday])>
                    <header @class([
                        'flex items-baseline justify-between gap-2 px-4 py-2',
                        'bg-brand-50 text-brand-800' => $isToday,
                        'bg-stone-100 text-stone-700' => ! $isToday,
                    ])>
                        <span class="font-semibold capitalize">{{ $day->locale('fr')->isoFormat('dddd D MMMM') }}</span>
                        @if ($isToday) <span class="text-xs font-medium">Aujourd'hui</span> @endif
                    </header>

                    <ul class="divide-y divide-stone-100">
                        @foreach ($dayMeals as $row)
                            @php
                                $cellKey = $dateKey.'|'.$row['slot']->id;
                                $cellReminders = $this->reminders->get($cellKey, collect());
                            @endphp
                            <li wire:key="list-cell-{{ $cellKey }}" class="flex items-start gap-3 px-4 py-2.5">
                                <span class="w-20 shrink-0 pt-1 text-xs font-semibold tracking-wide text-stone-500 uppercase">{{ $row['slot']->name }}</span>

                                <div class="min-w-0 flex-1 space-y-1.5">
                                    @forelse ($row['meals'] as $meal)
                                        <x-planner.meal-chip :meal="$meal" :conflict="$this->mealConflicts[$meal->id] ?? null" :stock-conflict="$this->stockConflicts[$meal->id] ?? null"
                                                             :dimmed="$mineOnly && ! $this->isMine($meal)" />
                                    @empty
                                        <button type="button" wire:click="openPicker('{{ $dateKey }}', {{ $row['slot']->id }})"
                                                class="flex w-full items-center gap-1 rounded-md border border-dashed border-stone-300 py-1.5 text-xs font-medium text-stone-500 hover:border-brand-400 hover:bg-brand-50 hover:text-brand-700">
                                            <x-icon name="plus" class="ml-2 size-4" /> Ajouter
                                        </button>
                                    @endforelse

                                    @php $canteenEntries = $this->canteenAt($dateKey, $row['slot']->id); @endphp
                                    @if ($canteenEntries->isNotEmpty())
                                        @include('livewire.planner.week.canteen', ['entries' => $canteenEntries, 'dateKey' => $dateKey])
                                    @endif

                                    @if ($cellReminders->isNotEmpty())
                                        <button type="button" x-data x-on:click="Livewire.dispatch('open-notifications')"
                                                class="flex items-center gap-1 rounded-md bg-sky-50 px-1.5 py-0.5 text-xs font-medium text-sky-800 ring-1 ring-sky-200">
                                            <x-icon name="clock" class="size-3.5" /> {{ $cellReminders->pluck('title')->join(' · ') }}
                                        </button>
                                    @endif
                                </div>

                                <button type="button" wire:click="openOccasion('{{ $dateKey }}', {{ $row['slot']->id }})"
                                        class="rounded-md p-1.5 text-stone-300 hover:bg-violet-50 hover:text-violet-700" title="Convives et invités">
                                    <x-icon name="users" class="size-4" /><span class="sr-only">Convives</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @else
    @php $slotCount = $this->activeSlots->count(); @endphp

    {{--
        Grille unique et responsive :
        - mobile : une carte par jour, créneaux empilés ;
        - ordinateur (lg) : 8 colonnes (libellés + 7 jours), chaque jour occupe toutes les lignes
          grâce à « subgrid », ce qui aligne les créneaux d'un jour à l'autre.
    --}}
    {{-- Lot 28 (28.3) : sur téléphone, un jour à la fois (bande des jours, balayage) ; « Semaine » montre tout. --}}
    <div wire:key="week-days-{{ $weekStart->toDateString() }}" data-week-days data-day="{{ $dayIndex }}" data-all="{{ $allDays ? 1 : 0 }}"
         x-data="bouffeWeekDays()" x-on:touchstart.passive="start($event)" x-on:touchend="end($event)">
    <nav class="sticky top-14 z-20 -mx-4 mb-3 flex gap-0.5 bg-stone-50/95 px-4 py-2 backdrop-blur md:hidden print:hidden" aria-label="Jours de la semaine">
        @foreach ($days as $i => $day)
            @php
                $dateKey = $day->toDateString();
                $count = $this->activeSlots->sum(fn ($slot) => $this->meals->get($dateKey.'|'.$slot->id, collect())->count());
            @endphp
            <button type="button" data-day-chip="{{ $i }}" x-on:click="show({{ $i }})"
                    @class(['flex min-h-11 min-w-0 flex-1 flex-col items-center justify-center rounded-lg px-0.5 py-1 text-xs ring-1 ring-inset',
                            'bg-white text-stone-700 ring-stone-200' => $dateKey !== $today,
                            'bg-brand-50 text-brand-800 ring-brand-200' => $dateKey === $today])
                    aria-label="{{ ucfirst($day->locale('fr')->isoFormat('dddd D MMMM')) }} : {{ $count }} repas">
                <span class="font-medium capitalize">{{ $day->locale('fr')->isoFormat('dd') }}</span>
                <span class="text-sm font-semibold tabular-nums">{{ $day->format('j') }}</span>
                <span class="mt-0.5 flex h-1.5 gap-0.5" aria-hidden="true">
                    @for ($k = 0; $k < min($count, 3); $k++) <span class="size-1.5 rounded-full bg-current opacity-60"></span> @endfor
                </span>
            </button>
        @endforeach
        <button type="button" wire:click="toggleAllDays" aria-pressed="{{ $allDays ? 'true' : 'false' }}"
                title="Toute la semaine"
                @class(['flex min-h-11 min-w-0 flex-1 flex-col items-center justify-center rounded-lg px-0.5 text-xs font-medium ring-1 ring-inset',
                        'bg-stone-900 text-white ring-stone-900' => $allDays,
                        'bg-white text-stone-700 ring-stone-200' => ! $allDays])>
            Tout
        </button>
    </nav>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-[6.5rem_repeat(7,minmax(0,1fr))] xl:grid-rows-[auto_repeat(var(--slots),minmax(7rem,auto))] xl:gap-x-2 xl:gap-y-0"
         style="--slots: {{ $slotCount }}; --rows: {{ $slotCount + 1 }};"
         wire:loading.class="opacity-70" wire:target="previousWeek,nextWeek,currentWeek,clearWeek">

        {{-- Colonne des libellés de créneaux (ordinateur) --}}
        <div class="hidden xl:row-span-(--rows) xl:grid xl:grid-rows-subgrid">
            <div></div>
            @foreach ($this->activeSlots as $slot)
                <div class="flex items-start justify-end border-t border-stone-200 pt-3 pr-2 text-sm font-semibold text-stone-500">{{ $slot->name }}</div>
            @endforeach
        </div>

        @foreach ($days as $i => $day)
            @php
                $dateKey = $day->toDateString();
                $isToday = $dateKey === $today;
                $isPast = $dateKey < $today;
            @endphp

            <section wire:key="day-{{ $dateKey }}" data-day-index="{{ $i }}"
                     @class([
                         'card min-w-0 overflow-hidden rounded-2xl xl:row-span-(--rows) xl:grid xl:grid-cols-1 xl:grid-rows-subgrid xl:overflow-visible xl:rounded-none xl:bg-transparent xl:shadow-none xl:ring-0',
                         'ring-2 ring-brand-200 xl:ring-0' => $isToday,
                     ])>
                {{-- En-tête du jour --}}
                <header @class([
                    'flex items-baseline gap-2 px-4 py-2.5 xl:flex-col xl:items-center xl:gap-0 xl:rounded-xl xl:px-1 xl:py-2',
                    'bg-brand-50 text-brand-800' => $isToday,
                    'bg-stone-100 text-stone-700 xl:bg-transparent' => ! $isToday,
                ])>
                    <span class="text-sm font-semibold capitalize">{{ $day->locale('fr')->isoFormat('dddd') }}</span>
                    <span @class(['text-sm xl:text-xl xl:font-bold', 'text-brand-800' => $isToday, 'text-stone-600 xl:text-stone-800' => ! $isToday])>
                        {{ $day->locale('fr')->isoFormat('D MMM') }}
                    </span>
                    @if ($isToday) <span class="ml-auto text-xs font-medium xl:ml-0">Aujourd'hui</span> @endif
                </header>

                @foreach ($this->activeSlots as $slot)
                    @php
                        $cellKey = $dateKey.'|'.$slot->id;
                        $cellMeals = $this->meals->get($cellKey, collect());
                        $occasion = $this->occasions->get($cellKey);
                    @endphp

                    <div wire:key="cell-{{ $cellKey }}"
                         @class([
                             'group/cell flex flex-col gap-1.5 border-t border-stone-100 px-3 py-2 xl:mb-2 xl:rounded-2xl xl:border xl:p-1.5',
                             // Lot 29 (29.5) : case vide discrète (pointillés, « + » seul), case remplie sur fond doux.
                             'xl:border-dashed xl:border-stone-300 xl:bg-transparent' => $cellMeals->isEmpty() && ! $isToday,
                             'xl:border-stone-200 xl:bg-stone-100/60' => $cellMeals->isNotEmpty() && ! $isToday,
                             'xl:border-brand-200 xl:bg-brand-50/50' => $isToday,
                             'xl:opacity-80' => $isPast && $cellMeals->isEmpty(),
                         ])>
                        <div class="flex min-w-0 items-center justify-between gap-2">
                            <span class="text-xs font-semibold tracking-wide text-stone-500 uppercase xl:hidden">{{ $slot->name }}</span>
                            @if ($occasion)
                                <button type="button" wire:click="openOccasion('{{ $dateKey }}', {{ $slot->id }})"
                                        class="flex min-w-0 items-center gap-1 rounded-md bg-violet-50 px-1.5 py-0.5 text-xs font-medium text-violet-800 ring-1 ring-violet-200 hover:bg-violet-100 xl:w-full"
                                        title="{{ trim(($occasion->title ? $occasion->title.' — ' : '').$this->dinersSummaryFor($cellKey).' — '.app(\App\Services\Planning\OccasionService::class)->guestSummary($occasion), ' —') }}">
                                    <x-icon name="users" class="size-3.5 shrink-0" />
                                    <span class="tabular-nums" aria-hidden="true">{{ $this->dinersFor($cellKey) }}</span>
                                    <span class="sr-only">Convives : {{ $this->dinersSummaryFor($cellKey) }}</span>
                                    @if ($occasion->title) <span class="truncate font-normal">· {{ $occasion->title }}</span> @endif
                                </button>
                                @if ($occasion->title || $occasion->guests->isNotEmpty())
                                    <a href="{{ route('receptions.show', $occasion) }}" wire:navigate
                                       class="shrink-0 rounded-md p-0.5 text-violet-500 hover:bg-violet-50 hover:text-violet-800" title="Menu et rétroplanning de la réception">
                                        <x-icon name="cake" class="size-4" /><span class="sr-only">Réception</span>
                                    </a>
                                @endif
                            @else
                                <button type="button" wire:click="openOccasion('{{ $dateKey }}', {{ $slot->id }})"
                                        class="ml-auto rounded-md p-1.5 text-stone-300 xl:p-0.5 transition hover:bg-violet-50 hover:text-violet-700 xl:hidden xl:group-hover/cell:block"
                                        title="Convives et invités">
                                    <x-icon name="users" class="size-4" /><span class="sr-only">Convives</span>
                                </button>
                            @endif
                        </div>

                        @php $canteenEntries = $this->canteenAt($dateKey, $slot->id); @endphp
                        @if ($canteenEntries->isNotEmpty())
                            @include('livewire.planner.week.canteen', ['entries' => $canteenEntries, 'dateKey' => $dateKey])
                        @endif

                        @php $cellReminders = $this->reminders->get($cellKey, collect()); @endphp
                        @if ($cellReminders->isNotEmpty())
                            <button type="button" x-data x-on:click="Livewire.dispatch('open-notifications')"
                                    class="flex items-center gap-1 self-start rounded-md bg-sky-50 px-1.5 py-0.5 text-xs font-medium text-sky-800 ring-1 ring-sky-200 hover:bg-sky-100"
                                    title="{{ $cellReminders->pluck('title')->join(' · ') }}">
                                <x-icon name="clock" class="size-3.5 shrink-0" />
                                <span>{{ $cellReminders->count() }}</span>
                            </button>
                        @endif

                        <ul wire:sort="moveMeal" wire:sort:group="meals" wire:sort:group-id="{{ $cellKey }}" class="flex min-h-2 flex-col gap-1.5">
                            @foreach ($cellMeals as $meal)
                                <li wire:key="meal-{{ $meal->id }}" wire:sort:item="{{ $meal->id }}">
                                    <x-planner.meal-chip :meal="$meal" :conflict="$this->mealConflicts[$meal->id] ?? null" :stock-conflict="$this->stockConflicts[$meal->id] ?? null"
                                                         :dimmed="$mineOnly && ! $this->isMine($meal)" />
                                </li>
                            @endforeach
                        </ul>

                        <button type="button" wire:click="openPicker('{{ $dateKey }}', {{ $slot->id }})"
                                @class([
                                    'flex min-h-10 items-center justify-center gap-1 rounded-xl border border-dashed border-stone-300 py-1.5 text-sm font-medium text-stone-600 transition hover:border-brand-400 hover:bg-brand-50 hover:text-brand-700 xl:min-h-8 xl:border-0 xl:text-stone-500',
                                    'xl:flex-1' => $cellMeals->isEmpty(),
                                    'xl:opacity-0 xl:group-hover/cell:opacity-100 xl:focus:opacity-100' => $cellMeals->isNotEmpty(),
                                ])
                                title="Ajouter un repas">
                            <x-icon name="plus" class="size-4" /> <span class="xl:sr-only">Ajouter</span>
                        </button>
                    </div>
                @endforeach
            </section>
        @endforeach
    </div>
    </div>

    @endif

    @if ($this->hiddenMealsCount > 0)
        <p class="mt-4 text-sm text-amber-700">
            {{ $this->hiddenMealsCount }} repas sont planifiés sur des créneaux désactivés et n'apparaissent pas.
            <a href="{{ route('settings.slots') }}" wire:navigate class="underline">Gérer les créneaux</a>
        </p>
    @endif

    @if ($view !== 'liste')
        <p class="mt-4 hidden text-xs text-stone-500 xl:block">Astuce : glissez-déposez un repas pour le déplacer ; cliquez dessus pour le modifier.</p>
    @endif
@endif
