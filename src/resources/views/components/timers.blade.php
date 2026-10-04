{{--
    Minuteurs partagés (lot 41, 41.1) : tous ceux du foyer, lancés de n'importe quel appareil.

    <x-timers source="recette:12" />            mode cuisine (les boutons d'étape envoient « bouffe-timer »)
    <x-timers source="cuisine" :big="true">…</x-timers>   écran de cuisine (le contenu : minuteurs rapides)
--}}
@props(['source' => null, 'big' => false])

<div wire:ignore x-data="bouffeTimers(@js($source))" x-on:bouffe-timer.window="add($event.detail.minutes, $event.detail.label)"
     data-timers-panel {{ $attributes->merge(['class' => 'space-y-2']) }}>
    <template x-for="timer in timers" :key="(timer.local ? 'l' : 's') + timer.id">
        <div class="flex items-center gap-3 rounded-xl px-4 ring-1" :class="[finished(timer) ? 'bg-red-50 text-red-900 ring-red-200 animate-pulse' : 'bg-stone-900 text-white ring-stone-900', @js($big) ? 'py-3' : 'py-2.5']">
            <x-icon name="clock" class="size-5 shrink-0" />
            <span class="min-w-0 flex-1">
                <span @class(['block', 'line-clamp-2' => $big, 'truncate text-base' => ! $big]) x-text="timer.label"></span>
                <span class="block truncate text-xs opacity-75" x-show="timer.local || timer.by"
                      x-text="timer.local ? 'sur cet appareil (sans réseau)' : (timer.mine ? 'lancé par vous' : 'lancé par ' + timer.by)"></span>
            </span>
            <span @class(['font-bold tabular-nums', 'text-3xl' => $big, 'text-xl' => ! $big]) x-text="display(timer)"></span>
            <button type="button" x-on:click="remove(timer)" @class(['rounded-lg px-3', 'min-h-11 text-base' => $big, 'min-h-10 text-sm' => ! $big])
                    :class="finished(timer) ? 'bg-red-600 text-white' : 'bg-white/15 hover:bg-white/25'"
                    x-text="finished(timer) ? 'OK' : 'Arrêter'"></button>
        </div>
    </template>
    {{ $slot }}
</div>
