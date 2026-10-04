<div>
    <x-page-header title="Analyses du budget" :subtitle="$rangeLabel">
        <x-slot:actions>
            <div class="inline-flex rounded-lg bg-stone-100 p-0.5 text-sm" role="group" aria-label="Période analysée">
                @foreach ([3, 6, 12] as $n)
                    <button type="button" wire:click="$set('range', {{ $n }})" aria-pressed="{{ $range === $n ? 'true' : 'false' }}"
                            @class(['rounded-md px-2.5 py-1 font-medium', 'bg-white text-stone-900 shadow-sm' => $range === $n, 'text-stone-600 hover:text-stone-900' => $range !== $n])>
                        {{ $n }} mois
                    </button>
                @endforeach
            </div>
            <a href="{{ route('prices.index') }}" wire:navigate class="btn btn-ghost" title="Prix et magasins"><x-icon name="tag" class="size-4" /> <span class="sr-only sm:not-sr-only">Prix</span></a>
            <a href="{{ route('budget.index') }}" wire:navigate class="btn btn-secondary"><x-icon name="euro" class="size-4" /> <span class="sr-only sm:not-sr-only">Budget</span></a>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="min-w-0 space-y-6 lg:col-span-2">
            {{-- ================================================ Total par période (une seule série) --}}
            <section class="card p-5">
                <h2 class="font-display font-semibold text-stone-900">Dépenses par période</h2>
                <p class="text-sm text-stone-500">Tous postes confondus. Survolez une barre pour le détail.</p>

                <div class="mt-4 flex items-end gap-1 sm:gap-3" role="img"
                     aria-label="Total dépensé par période : {{ collect($periods)->map(fn ($p) => $p['label'].' '.$tracker->money($p['total'], 0))->join(', ') }}">
                    @foreach ($periods as $period)
                        @php $height = (int) round($period['total'] / $peak * 100); @endphp
                        <div class="group flex min-w-0 flex-1 flex-col items-center gap-1"
                             title="{{ $period['label'] }} : {{ $tracker->money($period['total']) }}">
                            <span @class(['text-xs font-medium text-stone-600 tabular-nums', 'hidden sm:block' => count($periods) > 6])>
                                {{ $period['total'] > 0 ? $tracker->money($period['total'], 0) : '—' }}
                            </span>
                            <div class="flex h-36 w-full items-end">
                                <div class="w-full rounded-t bg-brand-500 transition-all group-hover:bg-brand-600"
                                     style="height: {{ max($period['total'] > 0 ? 3 : 0, $height) }}%"></div>
                            </div>
                            <span class="w-full truncate text-center text-[11px] text-stone-500 sm:text-xs">{{ $period['short'] }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="mt-0 border-t border-stone-300"></div>
            </section>

            {{-- ================================================ Tableau par poste (vue tableau) --}}
            <section class="card overflow-hidden">
                <h2 class="font-display border-b border-stone-200 px-4 py-3 font-semibold text-stone-900">Par poste</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-stone-500">
                                <th class="sticky left-0 bg-white px-4 py-2 font-medium">Poste</th>
                                @foreach ($periods as $period)
                                    <th class="px-2 py-2 text-right font-medium whitespace-nowrap" title="{{ $period['label'] }}">{{ $period['short'] }}</th>
                                @endforeach
                                <th class="px-4 py-2 text-right font-medium">Moyenne</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($categories as $category)
                                @php
                                    $values = collect($periods)->map(fn ($p) => $p['categories'][$category->id] ?? 0.0);
                                @endphp
                                <tr wire:key="row-{{ $category->id }}">
                                    <th scope="row" class="sticky left-0 bg-white px-4 py-2 text-left font-medium text-stone-800">
                                        <span class="flex items-center gap-2 whitespace-nowrap">
                                            <span class="size-2 shrink-0 rounded-full {{ \App\Support\Palette::dot($category->color) }}" aria-hidden="true"></span>
                                            {{ $category->name }}
                                        </span>
                                    </th>
                                    @foreach ($values as $value)
                                        <td class="px-2 py-2 text-right whitespace-nowrap text-stone-700 tabular-nums">{{ $value > 0 ? $tracker->money($value, 0) : '—' }}</td>
                                    @endforeach
                                    <td class="px-4 py-2 text-right font-medium whitespace-nowrap text-stone-900 tabular-nums">{{ $tracker->money($values->avg(), 0) }}</td>
                                </tr>
                            @endforeach
                            <tr class="bg-stone-50 font-semibold">
                                <th scope="row" class="sticky left-0 bg-stone-50 px-4 py-2 text-left text-stone-900">Total</th>
                                @foreach ($periods as $period)
                                    <td class="px-2 py-2 text-right whitespace-nowrap text-stone-900 tabular-nums">{{ $tracker->money($period['total'], 0) }}</td>
                                @endforeach
                                <td class="px-4 py-2 text-right whitespace-nowrap text-stone-900 tabular-nums">{{ $tracker->money(collect($periods)->avg('total'), 0) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- ================================================ Magasins et lieux --}}
            <section class="card overflow-hidden">
                <h2 class="font-display border-b border-stone-200 px-4 py-3 font-semibold text-stone-900">Magasins et lieux</h2>
                @if ($places->isEmpty())
                    <p class="px-4 py-6 text-sm text-stone-500">Aucune dépense saisie sur la période.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-stone-500">
                                    <th class="px-4 py-2 font-medium">Lieu</th>
                                    <th class="px-2 py-2 text-right font-medium">Passages</th>
                                    <th class="px-2 py-2 text-right font-medium">Total</th>
                                    <th class="px-4 py-2 text-right font-medium whitespace-nowrap">Panier moyen</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                @foreach ($places as $place)
                                    <tr>
                                        <td class="max-w-40 truncate px-4 py-2 font-medium text-stone-800 sm:max-w-none">{{ $place['label'] }}</td>
                                        <td class="px-2 py-2 text-right text-stone-600 tabular-nums">{{ $place['visits'] }}</td>
                                        <td class="px-2 py-2 text-right whitespace-nowrap text-stone-900 tabular-nums">{{ $tracker->money($place['total']) }}</td>
                                        <td class="px-4 py-2 text-right whitespace-nowrap text-stone-600 tabular-nums">{{ $tracker->money($place['average']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="border-t border-stone-100 px-4 py-2 text-xs text-stone-500">Dépenses récurrentes exclues.</p>
                @endif
            </section>
        </div>

        {{-- ==================================================== Chiffres clés --}}
        <aside class="space-y-4">
            <section class="card p-4">
                <h2 class="font-display font-semibold text-stone-900">Un repas, par personne</h2>
                <dl class="mt-3 grid grid-cols-2 gap-3">
                    <div class="rounded-xl bg-stone-50 p-3">
                        <dt class="text-xs text-stone-500">À la maison</dt>
                        <dd class="text-xl font-bold text-stone-900 tabular-nums">{{ $meals['home'] !== null ? $tracker->money($meals['home']) : '—' }}</dd>
                        <dd class="text-xs text-stone-500">{{ $meals['home_covers'] }} couvert{{ $meals['home_covers'] > 1 ? 's' : '' }} mangé{{ $meals['home_covers'] > 1 ? 's' : '' }}</dd>
                    </div>
                    <div class="rounded-xl bg-stone-50 p-3">
                        <dt class="text-xs text-stone-500">Dehors</dt>
                        <dd class="text-xl font-bold text-stone-900 tabular-nums">{{ $meals['out'] !== null ? $tracker->money($meals['out']) : '—' }}</dd>
                        <dd class="text-xs text-stone-500">{{ $meals['out_covers'] }} couvert{{ $meals['out_covers'] > 1 ? 's' : '' }}</dd>
                    </div>
                </dl>
                @unless ($meals['home_reliable'])
                    <p class="mt-2 flex gap-1.5 text-xs text-stone-600"><x-icon name="info" class="size-4 shrink-0" />
                        Trop peu de repas marqués mangés sur la période pour estimer le coût à la maison sans le fausser.</p>
                @endunless
                <p class="mt-2 text-xs text-stone-500">
                    Maison : courses alimentaires ÷ convives des repas marqués mangés. Dehors : restaurant, à emporter et midi au travail ÷ personnes indiquées.
                </p>
            </section>

            <section class="card p-4">
                <h2 class="font-display font-semibold text-stone-900">Planning prévu et courses réelles</h2>
                <dl class="mt-3 space-y-1 text-sm">
                    <div class="flex justify-between gap-2"><dt class="text-stone-600">Coût estimé des repas planifiés</dt><dd class="font-medium whitespace-nowrap tabular-nums">{{ $planned['known'] ? ($planned['missing'] > 0 ? 'au moins ' : '').$tracker->money($planned['planned']) : '—' }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-stone-600">Courses alimentaires</dt><dd class="font-medium whitespace-nowrap tabular-nums">{{ $tracker->money($planned['actual']) }}</dd></div>
                    @if ($planned['gap'] !== null)
                        <div class="flex justify-between gap-2 border-t border-stone-100 pt-1">
                            <dt class="text-stone-600">Écart</dt>
                            <dd class="font-semibold whitespace-nowrap tabular-nums">{{ $planned['gap'] >= 0 ? '+' : '−' }}{{ $tracker->money(abs($planned['gap'])) }}</dd>
                        </div>
                    @endif
                </dl>
                <p class="mt-2 text-xs text-stone-500">
                    @if ($planned['missing'] > 0)
                        {{ $planned['missing'] }} ingrédient{{ $planned['missing'] > 1 ? 's' : '' }} du planning sans prix : l'estimation est incomplète, l'écart n'est donc pas calculé.
                    @else
                        L'écart couvre aussi ce qui n'est pas au planning (petit-déjeuners, goûters, réserves).
                    @endif
                </p>
            </section>

            <section class="card p-4">
                <h2 class="font-display font-semibold text-stone-900">Gaspillage</h2>
                @if ($waste['count'] === 0)
                    <p class="mt-2 text-sm text-stone-500">Rien de jeté sur la période.</p>
                @else
                    <p class="mt-2 text-2xl font-bold text-stone-900 tabular-nums">{{ $waste['priced'] > 0 ? $tracker->money($waste['cost']) : '—' }}</p>
                    <p class="text-sm text-stone-600">
                        {{ $waste['count'] }} produit{{ $waste['count'] > 1 ? 's' : '' }} jeté{{ $waste['count'] > 1 ? 's' : '' }},
                        dont {{ $waste['priced'] }} avec un prix connu.
                    </p>
                    <p class="mt-1 text-xs text-stone-500">Valeur estimée à partir des prix de référence : un produit sans prix ne compte pas.</p>
                @endif
            </section>
        </aside>
    </div>
</div>
