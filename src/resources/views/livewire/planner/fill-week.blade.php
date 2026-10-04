<div>
    <a href="{{ route('planner.week', ['semaine' => $this->weekStart()->toDateString()]) }}" wire:navigate
       class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> Planning
    </a>

    <x-page-header :title="'Remplir la semaine du '.$this->weekStart()->locale('fr')->isoFormat('D MMMM')"
                   subtitle="Bouffe propose, vous ajustez. Rien n'est enregistré avant « Valider ».">
        <x-slot:actions>
            <button type="button" wire:click="regenerate" class="btn btn-secondary" wire:loading.attr="disabled">
                <x-icon name="undo" class="size-4" /> Relancer tout
            </button>
            <button type="button" wire:click="cancel" class="btn btn-ghost">Annuler</button>
            <button type="button" wire:click="apply" class="btn btn-primary" @disabled(count($proposals) === 0)>
                <x-icon name="check" class="size-4" />
                Valider {{ count($proposals) }} repas
            </button>
        </x-slot:actions>
    </x-page-header>

    {{-- ============================================================ Options --}}
    <section class="card mb-6 space-y-3 p-4">
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-stone-700">
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model.live="useStock" class="form-checkbox">
                Utiliser le stock en priorité
            </label>
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model.live="useWishes" class="form-checkbox">
                Placer les envies
            </label>
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model.live="useLeftovers" class="form-checkbox">
                Prévoir les restes
            </label>
        </div>

        <div class="flex flex-wrap items-center gap-2 border-t border-stone-200 pt-3">
            <span class="text-sm text-stone-500">Créneaux à remplir</span>
            @foreach ($this->mealSlots as $slot)
                @php $on = in_array($slot->id, $slotIds, true); @endphp
                <button type="button" wire:click="toggleSlot({{ $slot->id }})" wire:key="slot-{{ $slot->id }}"
                        aria-pressed="{{ $on ? 'true' : 'false' }}"
                        @class([
                            'rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset transition',
                            'bg-brand-600 text-white ring-brand-600' => $on,
                            'bg-white text-stone-600 ring-stone-200 hover:text-stone-900' => ! $on,
                        ])>
                    {{ $slot->name }}
                </button>
            @endforeach
        </div>

        @if ($this->rulesSummary)
            <div class="flex flex-wrap gap-2 border-t border-stone-200 pt-3 text-xs">
                @foreach ($this->rulesSummary as $status)
                    <span @class([
                        'rounded-full px-2 py-1 font-medium',
                        'bg-red-100 text-red-800' => $status['state'] === 'over',
                        'bg-amber-100 text-amber-900' => $status['state'] === 'under',
                        'bg-stone-100 text-stone-600' => $status['state'] === 'ok',
                    ])>{{ $status['message'] }}</span>
                @endforeach
            </div>
        @endif
    </section>

    {{-- ============================================================ Propositions --}}
    @if (count($proposals) === 0)
        <div class="card p-8 text-center">
            @if ($this->emptyCells === 0)
                <x-empty-state icon="calendar" title="La semaine est déjà complète">
                    Toutes les cases des créneaux choisis sont occupées.
                </x-empty-state>
            @else
                <x-empty-state icon="sparkles" title="Aucune proposition">
                    Ajoutez des recettes au carnet, ou assouplissez les règles de la semaine.
                </x-empty-state>
            @endif
        </div>
    @else
        <div class="space-y-4">
            @foreach ($this->byDay as $date => $dayProposals)
                <section wire:key="day-{{ $date }}" class="card overflow-hidden">
                    <h2 class="border-b border-stone-200 bg-stone-50 px-4 py-2 text-sm font-semibold text-stone-700">
                        {{ \Illuminate\Support\Carbon::parse($date)->locale('fr')->isoFormat('dddd D MMMM') }}
                    </h2>

                    <ul class="divide-y divide-stone-100">
                        @foreach ($dayProposals as $proposal)
                            <li wire:key="p-{{ $proposal['key'] }}" class="flex flex-wrap items-start gap-3 p-4">
                                <span class="w-20 shrink-0 pt-0.5 text-sm font-medium text-stone-500">{{ $proposal['slot'] }}</span>

                                <div class="min-w-48 flex-1">
                                    <p class="font-semibold text-stone-900">
                                        @if ($proposal['type'] === 'leftover')
                                            <x-icon name="duplicate" class="inline size-4 text-stone-400" />
                                        @endif
                                        {{ $proposal['title'] }}
                                        <span class="ml-1 text-sm font-normal text-stone-500">· {{ \App\Services\Planning\Appetites::label($proposal['servings']) }}</span>
                                    </p>

                                    @if ($proposal['reasons'])
                                        <p class="mt-0.5 text-xs text-herb-700">{{ implode(' · ', $proposal['reasons']) }}</p>
                                    @endif

                                    @foreach ($proposal['warnings'] as $warning)
                                        <p class="mt-0.5 text-xs text-amber-700">
                                            <x-icon name="warning" class="inline size-3.5" /> {{ $warning }}
                                        </p>
                                    @endforeach
                                </div>

                                <div class="flex items-center gap-1">
                                    @if ($proposal['type'] === 'recipe')
                                        <button type="button" wire:click="reroll('{{ $proposal['key'] }}')" title="Proposer autre chose"
                                                class="btn btn-ghost px-2" @disabled($proposal['locked'])>
                                            <x-icon name="shuffle" class="size-4" /><span class="sr-only">Relancer</span>
                                        </button>
                                        <button type="button" wire:click="toggleLock('{{ $proposal['key'] }}')"
                                                title="{{ $proposal['locked'] ? 'Déverrouiller' : 'Garder cette proposition' }}"
                                                @class(['btn px-2', 'btn-secondary' => $proposal['locked'], 'btn-ghost' => ! $proposal['locked']])>
                                            <x-icon name="{{ $proposal['locked'] ? 'lock' : 'lock-open' }}" class="size-4" />
                                            <span class="sr-only">Verrouiller</span>
                                        </button>
                                    @endif
                                    <button type="button" wire:click="remove('{{ $proposal['key'] }}')" title="Laisser la case vide"
                                            class="btn btn-ghost px-2 hover:text-red-600">
                                        <x-icon name="close" class="size-4" /><span class="sr-only">Retirer</span>
                                    </button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>

        <p class="mt-4 text-sm text-stone-500">
            {{ $this->lockedCount }} case(s) gardée(s) lors d'une relance.
            Les cases déjà occupées du planning ne sont jamais touchées.
        </p>
    @endif
</div>
