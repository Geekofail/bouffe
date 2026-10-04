<div class="mx-auto max-w-5xl">
    <a href="{{ route('stock.index') }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> Stock
    </a>

    <x-page-header title="Historique et gaspillage" subtitle="Ce qui entre, ce qui sort, et ce qui finit à la poubelle." />

    {{-- ============================================================ Bilan (16.3) --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card p-4">
            <p class="text-sm text-stone-500">Consommé avant la date</p>
            <p class="mt-1 text-2xl font-bold text-stone-900">
                {{ $summary['share'] !== null ? $summary['share'].' %' : '—' }}
            </p>
            <p class="text-xs text-stone-500">{{ $summary['consumed'] }} consommés · {{ $summary['wasted'] }} jetés</p>
        </div>

        <div class="card p-4">
            <p class="text-sm text-stone-500">Jeté sur la période</p>
            <p class="mt-1 text-2xl font-bold text-stone-900">{{ $summary['wasted'] }}</p>
            <p class="text-xs text-stone-500">{{ $months ? count($months) : 0 }} derniers mois</p>
        </div>

        <div class="card p-4">
            <p class="text-sm text-stone-500">Coût estimé du gaspillage</p>
            <p class="mt-1 text-2xl font-bold text-stone-900">
                {{ $summary['priced'] > 0 ? $stats->money($summary['cost']) : '—' }}
            </p>
            <p class="text-xs text-stone-500">
                @if ($summary['priced'] > 0)
                    {{ $summary['priced'] }} article(s) avec un prix connu sur {{ $summary['wasted'] }}
                @else
                    saisissez des prix en cochant vos courses
                @endif
            </p>
        </div>

        <div class="card p-4">
            <p class="text-sm text-stone-500">Tendance</p>
            @if ($summary['trend'] === null)
                <p class="mt-1 text-2xl font-bold text-stone-500">—</p>
                <p class="text-xs text-stone-500">pas encore assez de recul</p>
            @else
                <p @class(['mt-1 text-2xl font-bold', 'text-herb-700' => $summary['trend'] < 0, 'text-red-700' => $summary['trend'] > 0, 'text-stone-900' => $summary['trend'] === 0])>
                    {{ $summary['trend'] > 0 ? '+' : '' }}{{ $summary['trend'] }} %
                </p>
                <p class="text-xs text-stone-500">3 derniers mois vs 3 précédents</p>
            @endif
        </div>
    </div>

    {{-- ============================================================ Mois par mois --}}
    <section class="card mt-6 p-5">
        <h2 class="font-display font-semibold text-stone-900">Mois par mois</h2>
        <p class="text-sm text-stone-500">Chaque barre : ce qui a été consommé (vert) et ce qui a été jeté (rouge).</p>

        <div class="mt-4 flex items-end gap-2 sm:gap-4">
            @foreach ($months as $month)
                @php
                    $total = $month['wasted'] + $month['consumed'];
                    $height = (int) round($total / $peak * 100);
                @endphp
                <div class="flex min-w-0 flex-1 flex-col items-center gap-1">
                    <span class="text-xs font-medium text-stone-600 tabular-nums">{{ $month['share'] !== null ? $month['share'].' %' : '—' }}</span>
                    <div class="flex h-32 w-full flex-col justify-end overflow-hidden rounded-t-md bg-stone-100"
                         title="{{ $month['label'] }} : {{ $month['consumed'] }} consommés, {{ $month['wasted'] }} jetés">
                        @if ($total > 0)
                            <div class="w-full bg-red-400" style="height: {{ (int) round($height * $month['wasted'] / max(1, $total)) }}%"></div>
                            <div class="w-full bg-herb-500" style="height: {{ (int) round($height * $month['consumed'] / max(1, $total)) }}%"></div>
                        @endif
                    </div>
                    <span class="truncate text-xs text-stone-500">{{ $month['short'] }}</span>
                </div>
            @endforeach
        </div>
    </section>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        {{-- ======================================================== Les plus jetés --}}
        <section class="card h-fit p-5">
            <h2 class="font-display font-semibold text-stone-900">Le plus souvent jeté</h2>

            @if ($topWasted->isEmpty())
                <p class="mt-2 text-sm text-stone-500">Rien de jeté ces six derniers mois. C'est une bonne nouvelle.</p>
            @else
                <ul class="mt-3 divide-y divide-stone-100 text-sm">
                    @foreach ($topWasted as $row)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span class="min-w-0">
                                <span class="block truncate font-medium text-stone-800">{{ $row['label'] }}</span>
                                <span class="block text-xs text-stone-500">dernier : {{ $row['last']->locale('fr')->isoFormat('D MMM YYYY') }}</span>
                            </span>
                            <span class="shrink-0 text-right">
                                <span class="block font-semibold text-stone-900 tabular-nums">{{ $row['count'] }}×</span>
                                @if ($row['cost'] > 0)
                                    <span class="block text-xs text-stone-500">{{ $stats->money($row['cost']) }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            {{-- Durées observées différentes du réglage (16.2) --}}
            @if ($this->disagreements->isNotEmpty())
                <div class="mt-5 border-t border-stone-100 pt-4">
                    <h3 class="font-medium text-stone-900">Ce que disent vos achats</h3>
                    <p class="text-sm text-stone-500">La durée réellement observée chez vous diffère du réglage.</p>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($this->disagreements as $row)
                            <li wire:key="observed-{{ $row['ingredient']->id }}" class="rounded-lg bg-stone-50 p-3">
                                <p class="font-medium text-stone-800">{{ $row['ingredient']->name }}</p>
                                <p class="text-stone-600">
                                    Réglé sur {{ $row['configured'] }} j, tenu {{ $row['observed'] }} j en réalité.
                                </p>
                                <button type="button" wire:click="applyObserved({{ $row['ingredient']->id }})" class="mt-1 font-medium text-brand-700 hover:underline">
                                    Régler sur {{ $row['observed'] }} jours
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>

        {{-- ======================================================== Mouvements --}}
        <section class="card min-w-0 lg:col-span-2">
            <div class="flex flex-wrap items-center gap-2 border-b border-stone-200 px-4 py-3">
                <h2 class="font-display mr-auto font-semibold text-stone-900">Mouvements</h2>

                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Rechercher…" class="form-input w-auto py-1.5 text-sm" aria-label="Rechercher un article">

                <select wire:model.live="type" class="form-input w-auto py-1.5 text-sm" aria-label="Type de mouvement">
                    <option value="">Tous les mouvements</option>
                    @foreach ($types as $movementType)
                        <option value="{{ $movementType->value }}">{{ $movementType->label() }}</option>
                    @endforeach
                </select>
            </div>

            @if ($movements->isEmpty())
                <x-empty-state icon="archive" title="Aucun mouvement">
                    L'historique se remplit dès que vous rangez des courses ou consommez quelque chose.
                </x-empty-state>
            @else
                <ul class="divide-y divide-stone-100">
                    @foreach ($movements as $movement)
                        <li wire:key="movement-{{ $movement->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 text-sm">
                            <span @class([
                                'shrink-0 rounded-full px-2 py-0.5 text-xs font-medium',
                                'bg-herb-100 text-herb-800' => $movement->type->value === 'in',
                                'bg-stone-100 text-stone-700' => ! in_array($movement->type->value, ['in', 'waste'], true),
                                'bg-red-100 text-red-800' => $movement->type->value === 'waste',
                            ])>{{ $movement->type->label() }}</span>

                            <span class="min-w-0 flex-1 truncate font-medium text-stone-800">{{ $movement->label }}</span>

                            @if ($movement->quantity !== null)
                                <span class="text-stone-500 tabular-nums">
                                    {{ app(\App\Services\QuantityFormatter::class)->number((float) $movement->quantity) }} {{ $movement->unit?->label }}
                                </span>
                            @endif

                            @if ($movement->reason)
                                <span class="text-xs text-stone-500">{{ $movement->reason }}</span>
                            @endif

                            <span class="shrink-0 text-xs text-stone-500">
                                {{ $movement->created_at->locale('fr')->isoFormat('D MMM, HH:mm') }}
                                @if ($movement->user) · {{ $movement->user->name }} @endif
                            </span>
                        </li>
                    @endforeach
                </ul>

                <div class="border-t border-stone-100 px-4 py-3">
                    {{ $movements->links('pagination.simple') }}
                </div>
            @endif
        </section>
    </div>
</div>
