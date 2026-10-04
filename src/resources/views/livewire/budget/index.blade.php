<div>
    <x-page-header title="Budget" :subtitle="$label">
        <x-slot:actions>
            <button type="button" wire:click="previous" class="btn btn-secondary px-2.5" title="Période précédente">
                <x-icon name="chevron-left" class="size-4" /><span class="sr-only">Période précédente</span>
            </button>
            <button type="button" wire:click="next" @disabled($isCurrent) class="btn btn-secondary px-2.5 disabled:opacity-40" title="Période suivante">
                <x-icon name="chevron-right" class="size-4" /><span class="sr-only">Période suivante</span>
            </button>
            <a href="{{ route('budget.analyses') }}" wire:navigate class="btn btn-ghost" title="Analyses"><x-icon name="chart" class="size-4" /> <span class="sr-only sm:not-sr-only">Analyses</span></a>
            <a href="{{ route('prices.index') }}" wire:navigate class="btn btn-ghost" title="Prix et magasins"><x-icon name="tag" class="size-4" /> <span class="sr-only sm:not-sr-only">Prix</span></a>
            @if (auth()->user()->canEdit())
                <a href="{{ route('receipts.create') }}" wire:navigate class="btn btn-secondary" title="Ticket de caisse en photo"><x-icon name="receipt" class="size-4" /> <span class="sr-only sm:not-sr-only">Ticket</span></a>
                <button type="button" wire:click="add" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Dépense</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- ======================================================= Total --}}
    <section class="card mb-6 flex flex-wrap items-end gap-x-8 gap-y-3 p-5">
        <div>
            <p class="text-sm text-stone-500">Dépensé {{ $isCurrent ? 'à ce jour' : 'sur la période' }}</p>
            <p class="text-3xl font-bold text-stone-900 tabular-nums">{{ $tracker->money($total['spent']) }}</p>
        </div>
        @if ($total['budget'] !== null)
            <div>
                <p class="text-sm text-stone-500">Budget des postes suivis</p>
                <p class="text-xl font-semibold text-stone-700 tabular-nums">{{ $tracker->money($total['spentBudgeted']) }} / {{ $tracker->money($total['budget']) }}</p>
            </div>
        @endif
        @if ($waste['cost'] > 0)
            <div>
                <p class="text-sm text-stone-500">Jeté (valeur estimée)</p>
                <p class="text-xl font-semibold text-stone-700 tabular-nums" title="{{ $waste['priced'] }} produit(s) jeté(s) sur {{ $waste['count'] }} ont un prix connu">{{ $tracker->money($waste['cost']) }}</p>
            </div>
        @endif
        <p class="basis-full text-xs text-stone-500">
            {{ $this->expenses->count() }} dépense{{ $this->expenses->count() > 1 ? 's' : '' }} saisie{{ $this->expenses->count() > 1 ? 's' : '' }}
            @if ($listPrices['entries'] > 0) · {{ $listPrices['entries'] }} prix cochés en magasin sans ticket ({{ $tracker->money($listPrices['total']) }}, comptés dans les courses) @endif
        </p>
    </section>

    {{-- ======================================================= Postes (23.5, R25) --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($this->dashboard as $row)
            @php
                $category = $row['category'];
                [$barClass, $statusText] = match ($row['alert']) {
                    'over' => ['bg-red-500', 'Dépassé'],
                    'soon' => ['bg-amber-500', 'Plus de 80 %'],
                    'projected' => ['bg-amber-500', 'Au rythme actuel, dépassement'],
                    default => ['bg-herb-500', null],
                };
            @endphp
            <section wire:key="cat-{{ $category->id }}" class="card p-4">
                <div class="flex items-baseline justify-between gap-2">
                    <h2 class="font-display flex min-w-0 items-center gap-2 font-semibold text-stone-900">
                        <span class="size-2.5 shrink-0 rounded-full {{ \App\Support\Palette::dot($category->color) }}" aria-hidden="true"></span>
                        <span class="truncate">{{ $category->name }}</span>
                    </h2>
                    @if ($statusText)
                        <span @class(['flex shrink-0 items-center gap-1 text-xs font-medium', 'text-red-700' => $row['alert'] === 'over', 'text-amber-700' => $row['alert'] !== 'over'])>
                            <x-icon name="warning" class="size-3.5" /> {{ $statusText }}
                        </span>
                    @endif
                </div>

                <p class="mt-2 text-2xl font-bold text-stone-900 tabular-nums">
                    {{ $tracker->money($row['spent']) }}
                    @if ($row['budget'] !== null) <span class="text-base font-medium text-stone-500">/ {{ $tracker->money($row['budget'], 0) }}</span> @endif
                </p>

                @if ($row['budget'] !== null)
                    <div class="relative mt-2 h-2 rounded-full bg-stone-100" role="img"
                         aria-label="{{ $row['share'] }} % du budget dépensé{{ $row['pace'] !== null && $isCurrent ? ', rythme attendu '.$tracker->money($row['pace']) : '' }}">
                        <div class="h-2 rounded-full {{ $barClass }}" style="width: {{ min(100, $row['share']) }}%"></div>
                        @if ($isCurrent && $row['pace'] !== null)
                            {{-- Repère : où l'on devrait en être à cette date --}}
                            <div class="absolute -top-1 h-4 w-0.5 rounded bg-stone-700" style="left: {{ min(100, round($row['pace'] / $row['budget'] * 100)) }}%"
                                 title="Rythme : {{ $tracker->money($row['pace']) }} à cette date"></div>
                        @endif
                    </div>
                    <dl class="mt-3 space-y-0.5 text-sm text-stone-600">
                        @if ($isCurrent)
                            <div class="flex justify-between"><dt>Rythme à cette date</dt><dd class="tabular-nums">{{ $tracker->money($row['pace']) }}</dd></div>
                            <div class="flex justify-between"><dt>Projection fin de période</dt><dd class="tabular-nums">{{ $row['fragile'] ? '≈ ' : '' }}{{ $tracker->money($row['projection']) }}</dd></div>
                            @if ($row['per_week'] !== null)
                                <div class="flex justify-between"><dt>Reste par semaine</dt><dd class="tabular-nums">{{ $tracker->money($row['per_week']) }}</dd></div>
                            @endif
                        @else
                            <div class="flex justify-between"><dt>{{ $row['remaining'] >= 0 ? 'Restait' : 'Dépassement' }}</dt><dd class="tabular-nums">{{ $tracker->money(abs($row['remaining'])) }}</dd></div>
                        @endif
                    </dl>
                    @if ($isCurrent && $row['fragile'] && $row['spent'] > 0)
                        <p class="mt-1 text-xs text-stone-500">Projection fragile : moins de trois périodes d'historique.</p>
                    @endif
                @else
                    <p class="mt-2 text-xs text-stone-500">Pas de budget pour ce poste.
                        @if (auth()->user()->canEdit()) <a href="{{ route('settings.budget') }}" wire:navigate class="underline">Fixer un budget</a> @endif
                    </p>
                @endif
            </section>
        @endforeach
    </div>

    {{-- ======================================================= Qui a payé (23.8) --}}
    @if ($payers->isNotEmpty())
        @php $max = $payers->max('total'); $min = $payers->min('total'); @endphp
        <section class="card mb-6 p-4">
            <h2 class="font-display mb-2 font-semibold text-stone-900">Qui a payé</h2>
            <ul class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                @foreach ($payers as $row)
                    <li><span class="text-stone-600">{{ $row['user']->name }}</span> <strong class="tabular-nums">{{ $tracker->money($row['total']) }}</strong></li>
                @endforeach
            </ul>
            @if ($payers->count() >= 2 && $max - $min >= 1)
                <p class="mt-1 text-sm text-stone-500">{{ $payers->sortByDesc('total')->first()['user']->name }} a avancé {{ $tracker->money($max - $min) }} de plus sur la période.</p>
            @endif
        </section>
    @endif

    {{-- ======================================================= Dépenses de la période --}}
    <section class="card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-stone-200 px-4 py-3">
            <h2 class="font-display font-semibold text-stone-900">Dépenses</h2>
            <div class="flex items-center gap-2">
                <select wire:model.live="categoryFilter" class="form-input w-auto py-1.5 text-sm" aria-label="Filtrer par poste">
                    <option value="">Tous les postes</option>
                    @foreach ($this->dashboard as $row) <option value="{{ $row['category']->id }}">{{ $row['category']->name }}</option> @endforeach
                </select>
                <a href="{{ route('budget.export', ['du' => $from->toDateString(), 'au' => $to->toDateString()]) }}" class="btn btn-ghost px-2 text-sm" title="Exporter la période (tableur)">
                    <x-icon name="download" class="size-4" /><span class="sr-only sm:not-sr-only">CSV</span>
                </a>
            </div>
        </div>

        @if ($this->expenses->isEmpty())
            <x-empty-state icon="euro" title="Aucune dépense sur la période">
                Ajoutez vos tickets avec le bouton « Dépense » ou le bouton + : trois champs suffisent.
            </x-empty-state>
        @else
            <ul class="divide-y divide-stone-100">
                @foreach ($this->expenses as $expense)
                    <li wire:key="exp-{{ $expense->id }}">
                        <button type="button" wire:click="edit({{ $expense->id }})" @disabled(! auth()->user()->canEdit()) class="flex w-full items-center gap-3 px-4 py-2.5 text-left hover:bg-stone-50">
                            <span class="w-16 shrink-0 text-sm whitespace-nowrap text-stone-500 tabular-nums">{{ $expense->spent_on->locale('fr')->isoFormat('D MMM') }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium text-stone-800">{{ $expense->placeLabel() }}</span>
                                <span class="block truncate text-xs text-stone-500">
                                    {{ $expense->splits->count() > 1 ? $expense->splits->map(fn ($s) => $s->category?->name.' '.$tracker->money((float) $s->amount))->join(' · ') : $expense->category?->name }}
                                    @if ($expense->persons) · {{ $expense->persons }} pers. @endif
                                    @if ($expense->source === 'recurring') · récurrente @endif
                                    @if ($expense->payer) · {{ $expense->payer->name }} @endif
                                </span>
                            </span>
                            <span class="font-semibold text-stone-900 tabular-nums">{{ $tracker->money((float) $expense->amount) }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
