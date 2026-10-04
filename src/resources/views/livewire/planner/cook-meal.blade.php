{{-- Lot 41 (41.3) : relu toutes les 10 secondes, pour voir les étapes cochées sur l'autre téléphone. --}}
<div class="text-lg" x-data="{ wakeSupported: window.bouffeWakeLock.supported }" wire:poll.10s.visible>
    @php
        $plan = $this->plan;
        $tasks = $plan['tasks'];
        $dishes = $plan['dishes'];
        $serve = $this->serve();
        $styleOf = fn ($meal) => match ($meal->course) {
            \App\Enums\Course::Starter, \App\Enums\Course::Aperitif => 'bg-course-entree text-course-entree-ink',
            \App\Enums\Course::Dessert => 'bg-course-dessert text-course-dessert-ink',
            default => 'bg-course-plat text-course-plat-ink',
        };
        $states = $this->states;
        $members = $this->members;
        $shared = $members->count() >= 2;
        $canEdit = auth()->user()->canEdit();
        $nameOf = fn (?int $id) => $id ? ($members->firstWhere('id', $id)?->name ?? '?') : null;
        $visible = $who === 'moi' ? $tasks->filter(fn ($task) => $this->isMine($states[$task['key']])) : $tasks;
        $doneCount = collect($states)->where('done', true)->count();
        $nextKey = $visible->first(fn ($task) => ! $states[$task['key']]['done'])['key'] ?? null;
        $sameDay = fn ($at) => $at->isSameDay($serve);
    @endphp

    {{-- ============================================================ En-tête --}}
    <div class="mb-4 flex items-center gap-3">
        <a href="{{ route('planner.week', ['semaine' => \Illuminate\Support\Carbon::parse($date)->startOfWeek()->toDateString()]) }}" wire:navigate
           class="btn btn-ghost px-2 text-base" title="Quitter le mode cuisine">
            <x-icon name="close" class="size-6" /><span class="sr-only">Quitter</span>
        </a>
        <div class="min-w-0 flex-1">
            <h1 class="truncate text-base font-semibold text-stone-900">Cuisiner le repas</h1>
            <p class="text-sm text-stone-500">
                {{ ucfirst(\Illuminate\Support\Carbon::parse($date)->locale('fr')->isoFormat('dddd D MMMM')) }} · {{ mb_strtolower($this->slot->name) }}
                @if ($this->occasion?->title) · {{ $this->occasion->title }} @endif
            </p>
        </div>
        <label class="flex items-center gap-2 text-sm text-stone-600">
            Prêt à
            <input type="time" wire:model.live.debounce.500ms="time" class="form-input w-32 py-1.5 text-base" aria-label="Heure du repas">
        </label>
    </div>

    @if ($dishes->isEmpty())
        <section class="card p-5">
            <x-empty-state icon="recipes" title="Aucun plat à cuisiner dans cette case">
                Ajoutez des recettes au repas dans le planning (entrée, plat, dessert), puis revenez ici.
            </x-empty-state>
        </section>
    @else
        {{-- ============================================================ Les plats --}}
        <section class="card mb-4 p-4">
            <ul class="space-y-2">
                @foreach ($dishes as $dish)
                    <li wire:key="dish-{{ $dish['meal']->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 text-base">
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $styleOf($dish['meal']) }}">{{ $dish['course'] ?? 'Plat' }}</span>
                        <span class="min-w-0 flex-1 font-medium text-stone-900">{{ $dish['recipe']->title }}</span>
                        <span class="basis-full text-sm text-stone-600 tabular-nums sm:basis-auto">
                            {{ $dish['reheat'] ? 'réchauffer' : 'commencer' }} à {{ $dish['start']->format('H:i') }}@unless ($sameDay($dish['start'])) ({{ $dish['start']->locale('fr')->isoFormat('ddd') }})@endunless
                            · servi à {{ $dish['moment']->format('H:i') }}
                        </span>
                        {{-- Lot 41 (41.3) : qui cuisine ce plat. --}}
                        @if ($shared)
                            <select wire:change="assignDish({{ $dish['meal']->id }}, $event.target.value)" @disabled(! $canEdit)
                                    class="form-input w-auto min-w-36 py-1.5 text-sm" aria-label="Qui cuisine : {{ $dish['recipe']->title }}">
                                <option value="" @selected(! $dish['meal']->cook_user_id && ! $dish['meal']->cook_together)>Qui ?</option>
                                <option value="{{ \App\Services\Kitchen\MealTasks::TOGETHER }}" @selected($dish['meal']->cook_together)>Ensemble</option>
                                @foreach ($members as $member)
                                    <option value="{{ $member->id }}" @selected(! $dish['meal']->cook_together && (int) $dish['meal']->cook_user_id === $member->id)>{{ $member->name }}</option>
                                @endforeach
                            </select>
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="mt-3 text-sm text-stone-500">Heures indicatives, calculées d'après les temps des recettes et les durées citées dans les étapes.</p>
            {{-- Lot 41 (41.3) : à deux, chacun peut ne voir que ses étapes. --}}
            @if ($shared)
                <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-stone-100 pt-3">
                    <button type="button" wire:click="toggleMine" aria-pressed="{{ $who === 'moi' ? 'true' : 'false' }}"
                            @class(['btn min-h-11 px-3 text-sm', 'btn-primary' => $who === 'moi', 'btn-secondary' => $who !== 'moi'])>
                        <x-icon name="user" class="size-4" /> {{ $who === 'moi' ? 'Mes étapes seulement' : 'Voir mes étapes seulement' }}
                    </button>
                    <span class="min-w-0 flex-1 basis-48 text-sm text-stone-500">Répartissez les plats (« Qui ? ») ou une étape ; les étapes cochées se voient sur l'autre téléphone.</span>
                </div>
            @endif
        </section>

        {{-- ============================================================ Minuteurs (par plat) --}}
        {{-- Lot 41 (41.1) : les minuteurs de toute la maison, arrêtables d'ici. --}}
        <x-timers :source="'repas:'.$date.'-'.$slotId" class="mb-4 space-y-2" />

        {{-- ============================================================ Mise en place --}}
        @if ($this->ingredients !== [])
            <details class="card mb-4 p-4">
                <summary class="cursor-pointer text-base font-semibold text-stone-900">Mise en place : les ingrédients</summary>
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    @foreach ($dishes as $dish)
                        @if (isset($this->ingredients[$dish['meal']->id]))
                            <div wire:key="mep-{{ $dish['meal']->id }}">
                                <p class="mb-1 text-sm font-semibold tracking-wide text-brand-700 uppercase">{{ $dish['recipe']->title }} · {{ \App\Services\Planning\Appetites::label($dish['meal']->servings) }}</p>
                                <ul class="space-y-0.5 text-base">
                                    @foreach ($this->ingredients[$dish['meal']->id] as $line)
                                        <li><span class="font-semibold tabular-nums">{{ $line['quantity'] }}</span> {{ $line['name'] }} @if ($line['optional']) <span class="text-sm text-stone-500">(facultatif)</span> @endif</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endforeach
                </div>
            </details>
        @endif

        {{-- ============================================================ Étapes entrelacées --}}
        <ol class="space-y-3" aria-label="Étapes du repas, dans l'ordre">
            @if ($who === 'moi' && $visible->count() < $tasks->count())
                <li class="px-1 text-sm text-stone-500">{{ $tasks->count() - $visible->count() }} étape{{ $tasks->count() - $visible->count() > 1 ? 's' : '' }} confiée{{ $tasks->count() - $visible->count() > 1 ? 's' : '' }} à quelqu'un d'autre, masquée{{ $tasks->count() - $visible->count() > 1 ? 's' : '' }}.</li>
            @endif
            @foreach ($visible as $task)
                @php
                    $dish = $dishes[$task['dish']];
                    $state = $states[$task['key']];
                    $isDone = $state['done'];
                    $isNext = $task['key'] === $nextKey;
                    $photos = $dish['meal']->isRecipe() ? $dish['recipe']->photos->where('kind', 'step')->where('step_number', $task['number']) : collect();
                @endphp
                <li wire:key="task-{{ $task['key'] }}" @class(['card p-4 transition', 'ring-2 ring-brand-500' => $isNext, 'opacity-60' => $isDone])>
                    <div class="mb-2 flex flex-wrap items-center gap-2">
                        <span class="text-xl font-bold text-stone-900 tabular-nums">{{ $task['start']->format('H:i') }}</span>
                        @unless ($sameDay($task['start']))
                            <span class="text-sm text-stone-500">{{ $task['start']->locale('fr')->isoFormat('dddd') }}</span>
                        @endunless
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $styleOf($dish['meal']) }}">{{ $task['title'] }}</span>
                        <span class="text-sm text-stone-500">étape {{ $task['number'] }}/{{ $task['count'] }} · {{ \App\Support\Duration::format($task['minutes']) }}</span>
                        @if ($isNext) <span class="text-sm font-semibold text-brand-700">Maintenant</span> @endif
                        <label class="ml-auto flex min-h-10 items-center gap-2 text-sm text-stone-600">
                            <input type="checkbox" wire:click="toggle('{{ $task['key'] }}')" @checked($isDone) class="form-checkbox size-5">
                            {{ $isDone && $state['done_by'] && $state['done_by'] !== auth()->user()->name ? 'faite par '.$state['done_by'] : 'faite' }}
                        </label>
                    </div>

                    {{-- Lot 41 (41.3) : à qui revient l'étape. --}}
                    @if ($shared)
                        <div class="mb-2 flex flex-wrap items-center gap-2 text-sm">
                            <span @class(['rounded-full px-2 py-0.5 font-medium', 'bg-brand-50 text-brand-800 ring-1 ring-brand-200' => $state['user_id'] === (int) auth()->id(), 'bg-stone-100 text-stone-700' => $state['user_id'] !== (int) auth()->id()])>
                                {{ $state['together'] ? 'Ensemble' : ($state['user_id'] ? ($state['user_id'] === (int) auth()->id() ? 'Vous' : $nameOf($state['user_id'])) : 'À prendre') }}
                            </span>
                            @if ($canEdit && ! $isDone)
                                <select wire:change="assignStep('{{ $task['key'] }}', $event.target.value)" class="form-input w-auto py-1 text-sm" aria-label="Qui fait l'étape {{ $task['number'] }} de {{ $task['title'] }} ?">
                                    <option value="" @selected(! $state['override'])>Comme le plat</option>
                                    @foreach ($members as $member)
                                        <option value="{{ $member->id }}" @selected($state['override'] && $state['user_id'] === $member->id)>{{ $member->name }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </div>
                    @endif

                    @if ($task['group']) <p class="text-sm font-medium tracking-wide text-stone-500 uppercase">{{ $task['group'] }}</p> @endif
                    <p @class(['leading-relaxed whitespace-pre-line text-stone-800', 'line-through decoration-stone-400' => $isDone])>{{ $task['text'] }}</p>

                    @if ($photos->isNotEmpty())
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($photos as $photo)
                                <img src="{{ $photo->url('large') }}" alt="{{ $photo->caption ?: 'Photo de l\'étape' }}" loading="lazy" class="max-h-48 rounded-lg object-cover">
                            @endforeach
                        </div>
                    @endif

                    @if ($task['timers'] !== [] && ! $isDone)
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($task['timers'] as $timer)
                                <button type="button" x-data
                                        x-on:click="window.dispatchEvent(new CustomEvent('bouffe-timer', { detail: { minutes: {{ $timer['minutes'] }}, label: @js($task['title'].' · '.$timer['label']) } }))"
                                        class="btn btn-secondary text-base">
                                    <x-icon name="clock" class="size-5" /> Minuteur {{ $timer['label'] }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach

            <li class="card flex flex-wrap items-center gap-3 bg-herb-50 p-4 ring-1 ring-herb-100">
                <span class="text-xl font-bold text-herb-900 tabular-nums">{{ $serve->format('H:i') }}</span>
                <span class="min-w-0 flex-1 text-base text-herb-900">À table !</span>
                @if ($this->meals->contains(fn ($meal) => ! $meal->cooked_at))
                    <button type="button" wire:click="markAllCooked" class="btn btn-primary text-base">Tout marquer mangé</button>
                @else
                    <span class="text-base text-herb-800">Repas marqué comme mangé ✓</span>
                @endif
            </li>
        </ol>
    @endif

    {{-- ============================================================ Barre du bas --}}
    <div class="fixed inset-x-0 bottom-0 border-t border-stone-200 bg-white/95 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur">
        <div class="mx-auto flex max-w-3xl items-center gap-3">
            <p class="min-w-0 flex-1 truncate text-center text-sm text-stone-500">
                Étapes faites : {{ $doneCount }} / {{ $tasks->count() }}
                · <span x-show="wakeSupported" x-cloak>écran maintenu allumé</span><span x-show="! wakeSupported" x-cloak>écran non maintenu allumé (demande HTTPS)</span>
            </p>
        </div>
    </div>

    <livewire:stock.meal-stock-dialog />
</div>
