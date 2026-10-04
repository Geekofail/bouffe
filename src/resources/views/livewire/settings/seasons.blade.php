<div>
    <x-page-header title="Saisons" subtitle="Les mois où chaque fruit ou légume est de saison chez nous." />
    <x-settings-nav />

    {{-- ============================================================ Ce mois-ci --}}
    <div class="card mb-6 p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-display flex items-center gap-2 font-semibold text-stone-900">
                <x-icon name="leaf" class="size-5 text-herb-600" /> De saison en {{ mb_strtolower(\App\Services\Seasons\SeasonCalendar::monthName($currentMonth)) }}
            </h2>
            <p class="text-sm text-stone-500">{{ $datedCount }} ingrédient{{ $datedCount > 1 ? 's' : '' }} avec une saison</p>
        </div>

        @if ($inSeason->isEmpty())
            <p class="mt-2 text-sm text-stone-500">Aucun ingrédient n'a de saison enregistrée pour ce mois.</p>
        @else
            <div class="mt-3 flex flex-wrap gap-1.5">
                @foreach ($inSeason as $ingredient)
                    <span class="rounded-full bg-herb-50 px-2.5 py-1 text-xs font-medium text-herb-800">{{ $ingredient->name }}</span>
                @endforeach
            </div>
        @endif

        <p class="mt-4 text-sm text-stone-500">
            Ces valeurs de départ correspondent à la pleine saison dans la région : elles ne viennent d'aucune
            source officielle et se modifient librement ci-dessous. Un ingrédient sans mois coché n'est jamais
            signalé hors saison.
            <button type="button" wire:click="restoreDefaults" class="font-medium text-brand-700 hover:underline">Reprendre les valeurs proposées</button>
        </p>
    </div>

    {{-- ============================================================ Filtres --}}
    <div class="mb-4 flex flex-wrap gap-2">
        <div class="relative min-w-56 flex-1">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Chercher un ingrédient…" class="form-input pl-10" aria-label="Chercher un ingrédient">
        </div>

        <select wire:model.live="aisleId" class="form-input w-auto" aria-label="Rayon">
            <option value="">Tous les rayons</option>
            @foreach ($this->aisles as $aisle) <option value="{{ $aisle->id }}">{{ $aisle->name }}</option> @endforeach
        </select>

        <select wire:model.live="filter" class="form-input w-auto" aria-label="Filtre">
            <option value="dates">Avec une saison</option>
            <option value="vides">Sans saison</option>
            <option value="tous">Tous les ingrédients</option>
        </select>
    </div>

    {{-- ============================================================ Tableau --}}
    <div class="card overflow-x-auto">
        @if ($this->ingredients->isEmpty())
            <x-empty-state icon="leaf" title="Aucun ingrédient">Changez les filtres pour en voir d'autres.</x-empty-state>
        @else
            <table class="w-full min-w-[46rem] text-sm">
                <thead>
                    <tr class="border-b border-stone-200 text-xs text-stone-500">
                        <th class="px-4 py-2 text-left font-medium">Ingrédient</th>
                        @foreach ($months as $number => $name)
                            <th @class(['w-8 py-2 text-center font-medium', 'text-brand-700' => $number === $currentMonth])
                                title="{{ $name }}">{{ mb_substr($name, 0, 1) }}{{ in_array($number, [6, 7], true) ? mb_substr($name, 1, 1) : '' }}</th>
                        @endforeach
                        <th class="w-10"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach ($this->ingredients as $ingredient)
                        @php $selected = $ingredient->season_months ?? []; @endphp
                        <tr wire:key="season-{{ $ingredient->id }}" class="hover:bg-stone-50">
                            <td class="px-4 py-2">
                                <span class="font-medium text-stone-800">{{ $ingredient->name }}</span>
                                <span class="block text-xs text-stone-500">{{ $ingredient->aisle?->name }}</span>
                            </td>

                            @foreach ($months as $number => $name)
                                @php $on = in_array($number, $selected, true); @endphp
                                <td class="py-1 text-center">
                                    <button type="button" wire:click="toggle({{ $ingredient->id }}, {{ $number }})"
                                            @class([
                                                'size-6 rounded transition',
                                                'bg-herb-500 text-white' => $on,
                                                'bg-stone-100 hover:bg-stone-200' => ! $on,
                                                'ring-2 ring-brand-400' => $number === $currentMonth,
                                            ])
                                            title="{{ $ingredient->name }} — {{ $name }} : {{ $on ? 'de saison' : 'hors saison' }}">
                                        <span class="sr-only">{{ $name }}</span>
                                    </button>
                                </td>
                            @endforeach

                            <td class="pr-3 text-right">
                                @if ($selected !== [])
                                    <button type="button" wire:click="clear({{ $ingredient->id }})" class="btn btn-ghost px-2" title="Pas de saison">
                                        <x-icon name="close" class="size-4" /><span class="sr-only">Retirer la saison de {{ $ingredient->name }}</span>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
