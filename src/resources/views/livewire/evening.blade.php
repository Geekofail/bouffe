<div class="mx-auto max-w-2xl" data-evening>
    {{-- Pastille de l'icône (37.1) : les repas encore à clôturer. --}}
    <span hidden data-app-badge="{{ $toClose->count() }}" wire:key="app-badge-{{ $toClose->count() }}" x-data x-init="document.dispatchEvent(new Event('bouffe-badge'))"></span>

    <x-page-header title="Ce soir" :subtitle="ucfirst($now->locale('fr')->isoFormat('dddd D MMMM'))">
        <x-slot:actions>
            <a href="{{ route('settings.notifications') }}#ce-soir" wire:navigate class="btn btn-ghost text-sm" title="Heure du rendez-vous du soir">
                <x-icon name="bell" class="size-4" />
                @if ($settings['on'])
                    Rendez-vous à {{ str_replace(':', ' h ', $settings['time']) }}
                @else
                    Rendez-vous désactivé
                @endif
            </a>
        </x-slot:actions>
    </x-page-header>

    @if ($nothingLeft && $leftovers->isEmpty())
        <div class="card mb-6 flex items-center gap-4 p-5" data-evening-done>
            <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-herb-50 text-herb-700"><x-icon name="success" class="size-7" /></span>
            <div>
                <p class="font-display text-lg font-semibold text-stone-900">Tout est fait pour ce soir</p>
                <p class="text-sm text-stone-500">Les repas sont clôturés et rien n'est à préparer pour demain. Bonne soirée !</p>
            </div>
        </div>
    @endif

    {{-- ============================================================ 1. C'était mangé ? --}}
    @if ($toClose->isNotEmpty())
        <section class="card mb-6 p-4 sm:p-5" data-evening-close>
            <h2 class="font-display flex items-center gap-2 text-lg font-semibold text-stone-900">
                <x-icon name="check" class="size-5 text-herb-600" /> C'était mangé ?
            </h2>
            <p class="mb-2 text-sm text-stone-500">« Mangé » met le stock à jour ; « Pas fait » ne retire rien.</p>

            @if ($recent->isNotEmpty())
                <ul class="divide-y divide-stone-100">
                    @foreach ($recent as $meal)
                        @include('livewire.evening.meal-row')
                    @endforeach
                </ul>
            @endif

            {{-- Les jours d'avant : du rattrapage, replié pour ne pas noyer le repas du soir. --}}
            @if ($older->isNotEmpty())
                <details class="mt-2 rounded-xl bg-stone-50 px-3 py-2 ring-1 ring-stone-200" data-evening-older @if ($recent->isEmpty()) open @endif>
                    <summary class="min-h-10 cursor-pointer py-2 text-sm font-medium text-stone-700">
                        {{ $older->count() }} repas des jours précédents
                    </summary>
                    <ul class="divide-y divide-stone-100">
                        @foreach ($older as $meal)
                            @include('livewire.evening.meal-row')
                        @endforeach
                    </ul>
                    @if ($older->count() >= 2)
                        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-stone-200 pt-2 pb-1 text-sm text-stone-500">
                            <span>Stock déjà à jour ?</span>
                            <button type="button" wire:click="closeAllWithoutStock" wire:confirm="Marquer tous les repas des jours passés comme mangés, sans rien retirer du stock ?" class="btn btn-ghost px-2 py-1 text-sm">
                                Tout marquer mangé, sans toucher au stock
                            </button>
                        </div>
                    @endif
                </details>
            @endif

            @if ($recent->count() >= 2 && $canEdit)
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-stone-100 pt-3">
                    <button type="button" wire:click="closeAllAsPlanned" wire:loading.attr="disabled" class="btn btn-secondary min-h-11 text-herb-800">
                        <x-icon name="check" class="size-5" /> Tout comme prévu
                    </button>
                    <span class="min-w-0 flex-1 basis-48 text-sm text-stone-500">Les {{ $recent->count() }} repas d'aujourd'hui et d'hier, stock compris ; « Annuler » reste proposé 10 secondes.</span>
                </div>
            @endif
        </section>
    @endif

    {{-- ============================================================ Restes à placer --}}
    @if ($leftovers->isNotEmpty())
        <section class="card mb-6 p-4 sm:p-5" data-evening-leftovers>
            <h2 class="font-display flex items-center gap-2 text-lg font-semibold text-stone-900">
                <x-icon name="archive" class="size-5 text-sky-600" /> Restes à placer
            </h2>
            <p class="mb-2 text-sm text-stone-500">Les portions en trop, dans la prochaine case libre.</p>
            <ul class="divide-y divide-stone-100">
                @foreach ($leftovers as $row)
                    <li wire:key="ev-left-{{ $row['meal']->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5">
                        <span class="min-w-0 flex-1 basis-48">
                            <span class="block truncate font-medium text-stone-900">{{ $row['meal']->recipe?->title }}</span>
                            <span class="block text-xs text-stone-500">{{ \App\Services\Planning\Appetites::label($row['remaining']) }} en trop</span>
                        </span>
                        @if ($canEdit)
                            <button type="button" wire:click="placeLeftovers({{ $row['meal']->id }})" class="btn btn-secondary min-h-11">
                                <x-icon name="calendar" class="size-4" /> Placer
                            </button>
                        @endif
                        <a href="{{ route('planner.week', ['repas' => $row['meal']->id]) }}" wire:navigate class="text-sm font-medium text-brand-700 hover:underline">Choisir la case</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- ============================================================ 2. Pour demain --}}
    @if ($preparations->isNotEmpty() || $lunchboxes->isNotEmpty())
        <section class="card mb-6 p-4 sm:p-5" data-evening-prep>
            <h2 class="font-display flex items-center gap-2 text-lg font-semibold text-stone-900">
                <x-icon name="clock" class="size-5 text-sky-600" /> À préparer pour demain
            </h2>

            <ul class="mt-2 divide-y divide-stone-100">
                @foreach ($preparations as $reminder)
                    <li wire:key="ev-prep-{{ $reminder->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5">
                        <x-icon :name="$reminder->type->icon()" class="size-5 shrink-0 text-sky-600" />
                        <span class="min-w-0 flex-1 basis-48">
                            <span class="block font-medium text-stone-900">{{ $reminder->title }}</span>
                            @if ($reminder->detail) <span class="block text-xs text-stone-500">{{ $reminder->detail }}</span> @endif
                        </span>
                        <span class="flex gap-2">
                            <button type="button" wire:click="reminderDone({{ $reminder->id }})" class="btn btn-secondary min-h-11 text-herb-800">
                                <x-icon name="check" class="size-4" /> C'est fait
                            </button>
                            <button type="button" wire:click="reminderIgnored({{ $reminder->id }})" class="btn btn-ghost min-h-11" title="Pas besoin">
                                <x-icon name="close" class="size-4" /><span class="sr-only">Pas besoin</span>
                            </button>
                        </span>
                    </li>
                @endforeach

                @foreach ($lunchboxes as $box)
                    <li wire:key="ev-box-{{ $box->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5">
                        <x-icon name="lunchbox" class="size-5 shrink-0 text-sky-600" />
                        <span class="min-w-0 flex-1 basis-48">
                            <span class="block font-medium text-stone-900">{{ $box->lunchboxLabel() }}</span>
                            <span class="block text-xs text-stone-500">{{ $box->leftoverOf?->recipe?->title }} · {{ \App\Services\Planning\Appetites::label($box->servings) }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>

            @if ($lunchboxes->isNotEmpty())
                <a href="{{ route('planner.lunchboxes', ['du' => $tomorrowDate->toDateString(), 'au' => $tomorrowDate->toDateString()]) }}" target="_blank" class="mt-2 inline-flex min-h-10 items-center gap-1.5 text-sm font-medium text-brand-700 hover:underline">
                    <x-icon name="printer" class="size-4" /> Étiquettes des gamelles
                </a>
            @endif
        </section>
    @endif

    {{-- ============================================================ 3. Au menu demain --}}
    <section class="card mb-6 p-4 sm:p-5" data-evening-tomorrow>
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-display flex items-center gap-2 text-lg font-semibold text-stone-900">
                <x-icon name="calendar" class="size-5 text-stone-400" /> Demain, {{ $tomorrowDate->locale('fr')->isoFormat('dddd') }}
            </h2>
            <a href="{{ route('planner.week', ['semaine' => $tomorrowDate->copy()->startOfWeek()->toDateString()]) }}" wire:navigate class="text-sm font-medium whitespace-nowrap text-brand-700 hover:underline">Planning</a>
        </div>
        @if ($tomorrow->isEmpty())
            <p class="mt-2 text-sm text-stone-500">Rien de prévu pour l'instant.</p>
        @else
            <ul class="mt-2 space-y-1 text-sm">
                @foreach ($tomorrow as $meal)
                    <li wire:key="ev-tm-{{ $meal->id }}" class="flex gap-3">
                        <span class="w-20 shrink-0 text-xs font-semibold tracking-wide text-stone-500 uppercase">{{ $meal->slot?->name }}</span>
                        <span class="min-w-0 flex-1 text-stone-800">{{ $meal->label() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
