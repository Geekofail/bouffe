@php
    $plural = fn (int $n, string $word) => $n.' '.$word.($n > 1 ? 's' : '');
    $top = $stats['top']->take(5);
    $veg = $stats['vegetarian'];
    $season = $stats['season'];
    $left = $stats['leftovers'];
    $waste = $stats['waste'];
    $until = $stats['complete'] ? 'toute l\'année' : 'du 1er janvier au '.$stats['to']->locale('fr')->isoFormat('D MMMM');
@endphp
<div data-year-review>
    <a href="{{ route('planner.stats') }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800 print:hidden">
        <x-icon name="chevron-left" class="size-4" /> Statistiques
    </a>

    <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
        @if (count($years) > 1)
            <div class="flex flex-wrap gap-2" role="group" aria-label="Année">
                @foreach ($years as $y)
                    <a href="{{ route('planner.year', $y) }}" wire:navigate aria-current="{{ $y === $year ? 'page' : 'false' }}"
                       @class(['rounded-full px-4 py-1.5 text-sm font-semibold ring-1 ring-inset', 'bg-brand-600 text-white ring-brand-600' => $y === $year, 'bg-white text-stone-600 ring-stone-200 hover:text-stone-900' => $y !== $year])>{{ $y }}</a>
                @endforeach
            </div>
        @endif
        <div class="ml-auto flex gap-2" x-data="{ copied: false, text: @js($shareText) }">
            <button type="button" class="btn btn-secondary"
                    x-on:click="navigator.share ? navigator.share({ title: @js('L\'année '.$year.' en cuisine'), text }).catch(() => {}) : (navigator.clipboard?.writeText(text), copied = true)">
                <x-icon name="share" class="size-4" /> <span x-text="copied ? 'Texte copié ✓' : 'Partager'">Partager</span>
            </button>
            <button type="button" class="btn btn-secondary" x-on:click="window.print()"><x-icon name="printer" class="size-4" /> Imprimer</button>
        </div>
    </div>

    <header class="mb-6 rounded-2xl bg-brand-600 p-6 text-white sm:p-8">
        <p class="text-sm font-semibold tracking-wide text-white uppercase">{{ \App\Support\CurrentHousehold::get()?->name ?? 'Bouffe' }}</p>
        <h1 class="font-display text-4xl font-semibold sm:text-5xl">L'année {{ $year }} en cuisine</h1>
        <p class="mt-1 text-white">{{ ucfirst($until) }}{{ $stats['complete'] ? '' : ' — l\'année n\'est pas finie' }}.</p>

        @if ($stats['dishes'] > 0)
            <dl class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div><dt class="text-sm text-white">plats mangés</dt><dd class="font-display text-4xl font-semibold tabular-nums">{{ number_format($stats['dishes'], 0, ',', ' ') }}</dd></div>
                <div><dt class="text-sm text-white">repas</dt><dd class="font-display text-4xl font-semibold tabular-nums">{{ number_format($stats['occasions'], 0, ',', ' ') }}</dd></div>
                <div><dt class="text-sm text-white">recettes différentes</dt><dd class="font-display text-4xl font-semibold tabular-nums">{{ $stats['distinct'] }}</dd></div>
                <div><dt class="text-sm text-white">découvertes</dt><dd class="font-display text-4xl font-semibold tabular-nums">{{ $stats['new']['count'] }}</dd></div>
            </dl>
        @endif
    </header>

    @if ($stats['dishes'] === 0)
        <div class="card">
            <x-empty-state icon="calendar" title="Aucun repas marqué « mangé » en {{ $year }}">
                Le bilan se remplit au fil des repas cochés « mangé » dans le planning.
            </x-empty-state>
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-2">
            {{-- ============================================================ Les plus cuisinées --}}
            <section class="card p-4 sm:p-5">
                <h2 class="font-display mb-1 text-xl font-semibold text-stone-900">Vos classiques</h2>
                @if ($first = $top->first())
                    <p class="mb-4 text-stone-600"><strong class="text-stone-900">{{ $first['recipe']->title }}</strong>, {{ $first['count'] }} fois : c'est la recette de l'année.</p>
                    <x-stats.bars unit=" ×" :rows="$top->map(fn ($row) => ['label' => $row['recipe']->title, 'value' => $row['count'], 'href' => route('recipes.show', $row['recipe'])])->all()" />
                @else
                    <p class="text-sm text-stone-500">Aucune recette cuisinée (seulement des restes ou des repas libres).</p>
                @endif
            </section>

            {{-- ============================================================ Mois par mois --}}
            <section class="card p-4 sm:p-5">
                <h2 class="font-display mb-1 text-xl font-semibold text-stone-900">Mois par mois</h2>
                <p class="mb-4 text-sm text-stone-500">Plats mangés chaque mois.</p>
                @php $peak = max(1, collect($stats['months'])->max('dishes')); @endphp
                <div class="flex h-40 items-end gap-1.5 border-b border-stone-200" aria-hidden="true">
                    @foreach ($stats['months'] as $month)
                        <div class="flex h-full flex-1 flex-col items-center justify-end" title="{{ ucfirst($month['month']->locale('fr')->isoFormat('MMMM')) }} : {{ $month['dishes'] }} plats">
                            <span class="mb-1 text-xs text-stone-600 tabular-nums">{{ $month['dishes'] ?: '' }}</span>
                            <span class="w-full max-w-6 rounded-t bg-brand-500" style="height: {{ $month['dishes'] ? max(4, round($month['dishes'] / $peak * 85)) : 0 }}%"></span>
                        </div>
                    @endforeach
                </div>
                <div class="mt-1 flex gap-1.5 text-center text-[10px] text-stone-500" aria-hidden="true">
                    @foreach ($stats['months'] as $month) <span class="flex-1 truncate">{{ $month['label'] }}</span> @endforeach
                </div>
                <table class="sr-only">
                    <caption>Plats mangés par mois</caption>
                    <thead><tr><th>Mois</th><th>Plats</th></tr></thead>
                    <tbody>@foreach ($stats['months'] as $month)<tr><td>{{ $month['month']->locale('fr')->isoFormat('MMMM') }}</td><td>{{ $month['dishes'] }}</td></tr>@endforeach</tbody>
                </table>
            </section>
        </div>

        {{-- ============================================================ Habitudes --}}
        <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-stats.tile label="Sans viande ni poisson" icon="leaf" :value="$veg['share'] === null ? '—' : $veg['share'].' %'"
                          :base="$veg['count'].' plats principaux sur '.$veg['mains'].', soit '.number_format($veg['perWeek'], 1, ',', ' ').' par semaine'" />
            <x-stats.tile label="De saison" icon="sun" :value="$season['share'] === null ? '—' : $season['share'].' %'"
                          :base="$season['count'].' plats sur '.$season['base'].' avec des fruits ou légumes'" />
            <x-stats.tile label="Restes finis" icon="lunchbox" :value="$left['share'] === null ? '—' : $left['share'].' %'"
                          :base="\App\Services\Planning\PlanningStats::leftoversBase($left)" />
            <x-stats.tile label="Planning suivi" icon="calendar" :value="$stats['followed']['share'] === null ? '—' : $stats['followed']['share'].' %'"
                          :base="$stats['followed']['eaten'].' plats mangés sur '.$stats['followed']['planned'].' planifiés'" />
        </div>

        {{-- ============================================================ Gaspillage --}}
        <section class="card mt-6 p-4 sm:p-5">
            <h2 class="font-display mb-1 text-xl font-semibold text-stone-900">À la poubelle</h2>
            @if ($waste['count'] === 0)
                <p class="text-stone-600">Rien n'a été marqué « jeté » dans le stock {{ $stats['complete'] ? 'cette année' : 'depuis le 1er janvier' }}.</p>
            @else
                <p class="text-stone-600">
                    {{ $plural($waste['count'], 'article') }} jeté{{ $waste['count'] > 1 ? 's' : '' }}@if ($waste['priced'] > 0), pour environ <strong class="text-stone-900">{{ $prices->money($waste['cost']) }}</strong>
                        <span class="text-sm text-stone-500">(d'après les prix relevés de {{ $waste['priced'] }} d'entre eux)</span>@endif.
                </p>
            @endif
            @if ($wasteDiff !== null)
                <p class="mt-2 text-stone-600">
                    @if ($wasteDiff > 0.5)
                        <strong class="text-herb-700">{{ $prices->money($wasteDiff) }} de moins</strong> que l'an dernier sur la même période.
                    @elseif ($wasteDiff < -0.5)
                        {{ $prices->money(abs($wasteDiff)) }} de plus que l'an dernier sur la même période.
                    @else
                        Autant que l'an dernier sur la même période.
                    @endif
                    <span class="text-sm text-stone-500">({{ $stats['previous']['from']->locale('fr')->isoFormat('D MMM YYYY') }} – {{ $stats['previous']['to']->locale('fr')->isoFormat('D MMM YYYY') }} : {{ $prices->money($stats['previous']['waste']['cost']) }}, {{ $stats['previous']['waste']['priced'] }} articles avec un prix)</span>
                </p>
            @elseif (! $stats['previous'])
                <p class="mt-2 text-sm text-stone-500">Pas de comparaison : aucun repas mangé l'an dernier sur la même période.</p>
            @endif
        </section>

        @if ($stats['new']['titles'] !== [])
            <section class="card mt-6 p-4 sm:p-5">
                <h2 class="font-display mb-1 text-xl font-semibold text-stone-900">Découvertes de l'année</h2>
                <p class="text-stone-600">{{ implode(' · ', array_slice($stats['new']['titles'], 0, 20)) }}{{ count($stats['new']['titles']) > 20 ? '…' : '' }}</p>
            </section>
        @endif

        <p class="mt-6 text-xs text-stone-500">
            Chiffres tirés du planning (repas marqués « mangé ») et du stock (articles marqués « jeté »). Un plat = une recette,
            des restes ou un repas libre dans une case ; un repas = un créneau d'un jour. Rien n'est estimé à la place des repas non clôturés.
        </p>
    @endif
</div>
