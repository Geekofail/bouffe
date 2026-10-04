{{-- Planning : bandeaux au-dessus des repas — qui cuisine, équilibre, règles, stock à consommer, propositions (lot 36). --}}
{{-- ============================================================ Séjours (lot 34) --}}
@foreach ($this->stays as $stay)
    <a href="{{ route('stays.show', ['stay' => $stay, 'onglet' => 'repas']) }}" wire:navigate wire:key="week-stay-{{ $stay->id }}"
       class="mb-3 flex items-center gap-3 rounded-xl bg-sky-50 px-4 py-2.5 text-sm text-sky-900 ring-1 ring-sky-100 hover:bg-sky-100 print:hidden" data-week-stay>
        <x-icon name="sun" class="size-5 shrink-0" />
        <span class="flex-1">Séjour <strong>« {{ $stay->name }} »</strong> {{ $stay->period() }} : ses repas et ses courses sont dans le séjour.</span>
        <x-icon name="chevron-right" class="size-4" />
    </a>
@endforeach

{{-- ============================================================ Infos de la semaine (lot 37, 37.3) --}}
{{-- Sur téléphone, l'astuce, qui cuisine, l'équilibre, les règles et le stock se replient en une ligne :
     les repas du jour arrivent tout de suite. Sur grand écran, tout reste affiché. --}}
@php
    $hintUser = auth()->user();
    $cooks = $this->cookCounts;
    $weekInfos = array_values(array_filter([
        $hintUser && ! $hintUser->preference('hints_off', false) && ! in_array('planning', (array) $hintUser->preference('hints_seen', []), true) ? 'astuce' : null,
        $cooks['total'] > 0 && ($cooks['mine'] + $cooks['together'] + $cooks['others']) > 0 ? 'qui cuisine' : null,
        $balance['meals'] > 0 ? 'équilibre' : null,
        $this->rulesStatus ? 'règles' : null,
        $this->expiringStock->isNotEmpty() ? $this->expiringStock->count().' produit'.($this->expiringStock->count() > 1 ? 's' : '').' à consommer' : null,
    ]));
@endphp
@if ($weekInfos !== [])
    <div x-data="{ infos: false }" data-week-infos>
        <button type="button" x-on:click="infos = ! infos" x-bind:aria-expanded="infos"
                class="mb-3 flex min-h-11 w-full items-center gap-2 rounded-xl bg-white px-4 py-2 text-left text-sm ring-1 ring-stone-200 md:hidden print:hidden">
            <x-icon name="info" class="size-5 shrink-0 text-stone-400" />
            <span class="min-w-0 flex-1 truncate">
                <strong class="text-stone-800">{{ count($weekInfos) }} info{{ count($weekInfos) > 1 ? 's' : '' }} sur la semaine</strong>
                <span class="text-stone-500">· {{ implode(', ', $weekInfos) }}</span>
            </span>
            <span class="text-stone-400 transition" x-bind:class="infos && 'rotate-180'"><x-icon name="chevron-down" class="size-4" /></span>
        </button>

        <div class="max-md:hidden" x-bind:class="{ 'max-md:hidden': ! infos }">
            {{-- Aide contextuelle (lot 30, 30.3) --}}
            <x-hint key="planning">
                <span class="max-md:hidden">Glissez un repas d'une case à l'autre pour le déplacer.</span>
                <span class="md:hidden">Balayez l'écran vers la gauche ou la droite pour passer d'un jour à l'autre ; « Tout » montre la semaine entière.</span>
                Un repas retiré ou déplacé par erreur ? « Annuler » reste proposé 10 secondes.
            </x-hint>

            {{-- ============================================================ Qui cuisine (14.7) --}}
            @if ($cooks['total'] > 0 && ($cooks['mine'] + $cooks['together'] + $cooks['others']) > 0)
                <div class="mb-3 flex flex-wrap items-center gap-2 text-xs text-stone-600 print:hidden">
                    <x-icon name="users" class="size-4 text-stone-400" />
                    <span>Vous cuisinez <strong>{{ $cooks['mine'] }}</strong> repas</span>
                    @if ($cooks['together'] > 0) <span>· ensemble : {{ $cooks['together'] }}</span> @endif
                    @if ($cooks['others'] > 0) <span>· quelqu'un d'autre : {{ $cooks['others'] }}</span> @endif
                    @if ($cooks['unassigned'] > 0) <span class="text-stone-500">· {{ $cooks['unassigned'] }} sans personne désignée</span> @endif
                </div>
            @endif

            {{-- ============================================================ Équilibre de la semaine (17.5) --}}
            @if ($balance['meals'] > 0)
                <details class="mb-3 rounded-xl bg-white px-4 py-2 ring-1 ring-stone-200 print:hidden">
                    <summary class="cursor-pointer text-sm font-medium text-stone-700">
                        Équilibre de la semaine
                        <span class="font-normal text-stone-500">· {{ $balance['meals'] }} repas{{ ($balance['canteen'] ?? 0) > 0 ? ', dont '.$balance['canteen'].' à la cantine' : '' }}</span>
                    </summary>

                    <div class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($balance['families'] as $family)
                            <div class="flex items-center gap-3" title="{{ $family['help'] }}">
                                <span class="w-40 shrink-0 text-xs text-stone-600">{{ $family['label'] }}</span>
                                <span class="h-2 flex-1 overflow-hidden rounded-full bg-stone-100">
                                    <span @class([
                                            'block h-2 rounded-full',
                                            'bg-herb-500' => $family['count'] >= $family['target'],
                                            'bg-amber-400' => $family['count'] < $family['target'],
                                        ])
                                          style="width: {{ min(100, (int) round($family['count'] / max(1, $family['target']) * 100)) }}%"></span>
                                </span>
                                <span class="w-12 shrink-0 text-right text-xs text-stone-500 tabular-nums">{{ $family['count'] }} / {{ $family['target'] }}</span>
                            </div>
                        @endforeach
                    </div>

                    <p class="mt-3 text-xs text-stone-500">
                        Repères indicatifs, pour voir d'un coup d'œil ce qui domine la semaine : Bouffe ne donne aucun
                        conseil nutritionnel.
                        @if ($balance['without'] > 0) {{ $balance['without'] }} repas n'ont pas pu être classés. @endif
                    </p>
                </details>
            @endif

            {{-- ============================================================ Règles de la semaine (14.4) --}}
            @if ($this->rulesStatus)
                <div class="mb-4 flex flex-wrap items-center gap-2 text-xs print:hidden">
                    @foreach ($this->rulesStatus as $status)
                        <span @class([
                            'rounded-full px-2.5 py-1 font-medium ring-1 ring-inset',
                            'bg-red-50 text-red-800 ring-red-200' => $status['state'] === 'over',
                            'bg-amber-50 text-amber-900 ring-amber-200' => $status['state'] === 'under',
                            'bg-stone-50 text-stone-600 ring-stone-200' => $status['state'] === 'ok',
                        ])>{{ $status['message'] }}</span>
                    @endforeach
                    <a href="{{ route('settings.planning') }}" wire:navigate class="text-stone-500 underline hover:text-stone-600">Règles</a>
                </div>
            @endif

            {{-- ============================================================ Stock à consommer --}}
            @if ($this->expiringStock->isNotEmpty())
                @php $expiring = $this->expiringStock; @endphp
                <div class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl bg-orange-50 px-4 py-3 text-sm text-orange-900 ring-1 ring-orange-100 print:hidden" wire:key="expiring-stock">
                    <x-icon name="pantry" class="size-5 shrink-0" />
                    {{-- Lot 28 (E1) : le texte réclame 16 rem avant de céder la place aux liens, qui passent dessous sur téléphone. --}}
                    <p class="min-w-0 grow basis-64">
                        <strong>{{ $expiring->count() }} produit{{ $expiring->count() > 1 ? 's' : '' }}</strong> à consommer d'ici dimanche :
                        {{ $expiring->take(4)->map(fn ($row) => $row['item']->name().' ('.(str_starts_with($row['badge']['text'], 'J-') ? $row['badge']['text'] : mb_strtolower($row['badge']['text'])).')')->join(', ') }}{{ $expiring->count() > 4 ? '…' : '' }}
                    </p>
                    @php $expiringIngredients = $expiring->map(fn ($row) => $row['item']->ingredient_id)->filter()->unique()->take(3)->values()->all(); @endphp
                    @if ($expiringIngredients !== [])
                        <a href="{{ route('suggestions', ['utiliser' => $expiringIngredients]) }}" wire:navigate class="font-medium whitespace-nowrap underline">Idées de recettes</a>
                    @endif
                    <a href="{{ route('stock.index', ['filtre' => 'alertes']) }}" wire:navigate class="font-medium whitespace-nowrap underline">Voir le stock</a>
                </div>
            @endif
        </div>
    </div>
@endif

{{-- ============================================================ Proposition de restes --}}
@if ($leftoverOffer)
    <div class="mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-sky-50 px-4 py-3 text-sm text-sky-900 ring-1 ring-sky-100" wire:key="leftover-offer">
        <x-icon name="sparkles" class="size-5 shrink-0" />
        <p class="min-w-0 grow basis-64">
            Il reste <strong>{{ \App\Services\Planning\Appetites::label($leftoverOffer['remaining']) }}</strong>.
            Placer {{ \App\Services\Planning\Appetites::label($leftoverOffer['servings']) }} de restes
            <strong>{{ \Illuminate\Support\Carbon::parse($leftoverOffer['date'])->locale('fr')->isoFormat('dddd D') }} · {{ mb_strtolower($leftoverOffer['slot']) }}</strong> ?
        </p>
        <div class="flex gap-2">
            <button type="button" wire:click="acceptLeftoverOffer" class="btn btn-primary py-1.5">Oui, placer les restes</button>
            <button type="button" wire:click="dismissLeftoverOffer" class="btn btn-secondary py-1.5">Non merci</button>
        </div>
    </div>
@endif

{{-- ============================================================ Adapter les portions --}}
@if ($servingsOffer)
    <div class="mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-violet-50 px-4 py-3 text-sm text-violet-900 ring-1 ring-violet-100" wire:key="servings-offer">
        <x-icon name="users" class="size-5 shrink-0" />
        <p class="min-w-0 grow basis-64">
            Adapter les portions {{ $servingsOffer['count'] > 1 ? 'des '.$servingsOffer['count'].' plats' : 'du plat' }} de
            <strong>{{ \Illuminate\Support\Carbon::parse($servingsOffer['date'])->locale('fr')->isoFormat('dddd D') }} · {{ mb_strtolower($servingsOffer['slot']) }}</strong>
            ({{ \App\Services\Planning\Appetites::format($servingsOffer['before']) }} → {{ \App\Services\Planning\Appetites::label($servingsOffer['after']) }}) ?
        </p>
        <div class="flex gap-2">
            <button type="button" wire:click="acceptServingsOffer" class="btn btn-primary py-1.5">Oui, adapter</button>
            <button type="button" wire:click="dismissServingsOffer" class="btn btn-secondary py-1.5">Non</button>
        </div>
    </div>
@endif
