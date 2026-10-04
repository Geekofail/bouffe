@php
    $fmt = fn ($n) => number_format((float) $n, $n == (int) $n ? 0 : 1, ',', ' ');
    $followed = $stats['followed'];
    $veg = $stats['vegetarian'];
    $season = $stats['season'];
    $left = $stats['leftovers'];
    $range = $stats['from']->locale('fr')->isoFormat('D MMM YYYY').' – '.$stats['to']->locale('fr')->isoFormat('D MMM YYYY');
@endphp
<div data-planner-stats>
    <a href="{{ route('planner.week') }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> Planning
    </a>

    <x-page-header title="Statistiques" :subtitle="'Ce qui a vraiment été cuisiné et mangé · '.$range">
        <x-slot:actions>
            <a href="{{ route('planner.year') }}" wire:navigate class="btn btn-secondary"><x-icon name="star" class="size-4" /> L'année en cuisine</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-6 flex flex-wrap gap-2" role="group" aria-label="Période">
        @foreach ($periods as $key => $label)
            <button type="button" wire:click="setPeriod('{{ $key }}')" aria-pressed="{{ $period === $key ? 'true' : 'false' }}"
                    @class(['rounded-full px-4 py-2 text-sm font-semibold ring-1 ring-inset transition', 'bg-brand-600 text-white ring-brand-600' => $period === $key, 'bg-white text-stone-600 ring-stone-200 hover:text-stone-900' => $period !== $key])>
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($stats['dishes'] === 0 && $followed['planned'] === 0)
        <div class="card">
            <x-empty-state icon="chart" title="Rien à compter sur cette période">
                Les statistiques se remplissent quand des repas du planning sont marqués « mangé ».
            </x-empty-state>
        </div>
    @else
        <div wire:loading.class="opacity-60" wire:target="setPeriod" class="space-y-6">
            {{-- ============================================================ Chiffres clés --}}
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <x-stats.tile label="Plats mangés" icon="check" :value="$fmt($stats['dishes'])"
                              :base="$stats['occasions'].' repas (un repas = un créneau, entrée et dessert compris)'" />

                <x-stats.tile label="Planning suivi" icon="calendar" :value="$followed['share'] === null ? '—' : $followed['share'].' %'"
                              :base="$followed['eaten'].' mangés sur '.$followed['planned'].' plats planifiés'.($followed['skipped'] ? ', '.$followed['skipped'].' pas faits' : '').($followed['pending'] ? ', '.$followed['pending'].' à clôturer' : '')" />

                <x-stats.tile label="Recettes différentes" icon="recipes" :value="$fmt($stats['distinct'])"
                              :base="$stats['new']['count'] > 0 ? 'dont '.$stats['new']['count'].' cuisinée'.($stats['new']['count'] > 1 ? 's' : '').' pour la première fois' : 'aucune nouvelle recette'" />

                <x-stats.tile label="Sans viande ni poisson" icon="leaf" :value="$fmt($veg['perWeek']).' / semaine'"
                              :base="$veg['count'].' plats principaux sur '.$veg['mains'].($veg['share'] !== null ? ' ('.$veg['share'].' %)' : '').', sur '.$stats['weeks'].' semaine'.($stats['weeks'] > 1 ? 's' : '')" />

                <x-stats.tile label="De saison" icon="sun" :value="$season['share'] === null ? '—' : $season['share'].' %'"
                              :base="$season['base'] > 0 ? $season['count'].' plats sur '.$season['base'].' avec des fruits ou légumes, au mois où ils ont été mangés' : 'aucun plat avec des fruits ou légumes de saison connue'" />

                <x-stats.tile label="Restes finis" icon="lunchbox" :value="$left['share'] === null ? '—' : $left['share'].' %'"
                              :base="\App\Services\Planning\PlanningStats::leftoversBase($left)" />
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                {{-- ============================================================ Recettes les plus cuisinées --}}
                <section class="card p-4 sm:p-5">
                    <h2 class="font-display mb-1 text-lg font-semibold text-stone-900">Les plus cuisinées</h2>
                    <p class="mb-4 text-sm text-stone-500">Nombre de fois où la recette a été cuisinée et mangée (les restes ne comptent pas).</p>
                    @if ($stats['top']->isEmpty())
                        <p class="text-sm text-stone-500">Aucune recette cuisinée sur la période.</p>
                    @else
                        <x-stats.bars unit=" ×" :rows="$stats['top']->map(fn ($row) => [
                            'label' => $row['recipe']->title,
                            'value' => $row['count'],
                            'href' => route('recipes.show', $row['recipe']),
                            'title' => $row['recipe']->title.' : '.$row['count'].' fois, dernière le '.$row['last']->locale('fr')->isoFormat('D MMMM'),
                        ])->all()" />
                    @endif
                </section>

                {{-- ============================================================ Végétarien par semaine --}}
                <section class="card p-4 sm:p-5">
                    <h2 class="font-display mb-1 text-lg font-semibold text-stone-900">Plats sans viande ni poisson, par semaine</h2>
                    <p class="mb-4 text-sm text-stone-500">Plats principaux mangés ; semaines commençant le lundi.</p>
                    @php $peak = max(1, collect($veg['weeks'])->max('count')); @endphp
                    <div class="flex h-40 items-end gap-1.5 border-b border-stone-200" aria-hidden="true">
                        @foreach ($veg['weeks'] as $week)
                            <div class="group flex h-full flex-1 flex-col items-center justify-end" title="Semaine du {{ $week['start']->locale('fr')->isoFormat('D MMM') }} : {{ $week['count'] }} sur {{ $week['mains'] }} plats principaux">
                                <span class="mb-1 text-xs text-stone-600 tabular-nums">{{ $week['count'] ?: '' }}</span>
                                <span class="w-full max-w-6 rounded-t bg-herb-500" style="height: {{ $week['count'] ? max(4, round($week['count'] / $peak * 85)) : 0 }}%"></span>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-1 flex gap-1.5 text-center text-[10px] text-stone-500" aria-hidden="true">
                        @foreach ($veg['weeks'] as $week)
                            <span class="flex-1 truncate">{{ $loop->first || $loop->last || $loop->iteration % 3 === 1 ? $week['start']->locale('fr')->isoFormat('D/M') : '' }}</span>
                        @endforeach
                    </div>
                    @if ($veg['count'] === 0)
                        <p class="mt-2 text-sm text-stone-500">Aucun plat principal sans viande ni poisson sur la période.</p>
                    @endif
                    <table class="sr-only">
                        <caption>Plats sans viande ni poisson par semaine</caption>
                        <thead><tr><th>Semaine du</th><th>Sans viande ni poisson</th><th>Plats principaux</th></tr></thead>
                        <tbody>
                            @foreach ($veg['weeks'] as $week)
                                <tr><td>{{ $week['start']->locale('fr')->isoFormat('D MMMM') }}</td><td>{{ $week['count'] }}</td><td>{{ $week['mains'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="mt-3 text-xs text-stone-500">Classement d'après les ingrédients principaux, comme l'équilibre de la semaine du planning : œufs, fromage et légumineuses comptent comme sans viande.</p>
                </section>
            </div>

            @if ($stats['new']['titles'] !== [])
                <section class="card p-4 sm:p-5">
                    <h2 class="font-display mb-2 text-lg font-semibold text-stone-900">Découvertes</h2>
                    <p class="text-sm text-stone-600">Cuisinées pour la première fois : {{ implode(' · ', array_slice($stats['new']['titles'], 0, 12)) }}{{ count($stats['new']['titles']) > 12 ? '…' : '' }}</p>
                </section>
            @endif
        </div>
    @endif
</div>
