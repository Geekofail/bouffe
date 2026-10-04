@php
    // « 12 % », « 4,2 % » — sans signe ; le sens est dit par les mots autour.
    $pct = fn (float $value, int $decimals = 0) => number_format(abs($value) * 100, $decimals, ',', "\u{202F}")."\u{00A0}%";
    $signed = fn (float $value, int $decimals = 1) => ($value > 0 ? '+' : ($value < 0 ? '−' : '')).$pct($value, $decimals);
    $canEdit = auth()->user()->canEdit();
@endphp

<div>
    <x-page-header title="Prix et magasins" subtitle="D'après les prix relevés : tickets lus, articles cochés avec leur prix, prix notés à la main.">
        <x-slot:actions>
            <a href="{{ route('budget.index') }}" wire:navigate class="btn btn-ghost"><x-icon name="euro" class="size-4" /> <span class="sr-only sm:not-sr-only">Budget</span></a>
            @if ($canEdit)
                <button type="button" wire:click="openForm" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Noter un prix</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- Aide contextuelle (lot 30, 30.3) --}}
    <x-hint key="prices">
        Un prix payé en promotion ? Cochez « En promotion » en le notant (c'est automatique pour une ligne de ticket avec remise).
        Il apparaît comme « meilleur prix vu », sans fausser vos prix habituels ni l'indice de votre panier.
    </x-hint>

    <nav class="-mx-4 mb-6 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="Vues des prix">
        <div class="flex min-w-max gap-1 rounded-xl bg-stone-100 p-1">
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="$set('tab', '{{ $key }}')" aria-pressed="{{ $tab === $key ? 'true' : 'false' }}"
                        @class(['rounded-lg px-3 py-1.5 text-sm font-medium transition', 'bg-white text-stone-900 shadow-sm' => $tab === $key, 'text-stone-600 hover:text-stone-900' => $tab !== $key])>
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </nav>

    @if ($tab === 'magasins')
        {{-- ================================================================ C1 — Magasins --}}
        @if ($stores['stores']->isEmpty())
            <div class="card">
                <x-empty-state icon="store" title="Aucun prix relevé dans un magasin">
                    Les prix arrivent tout seuls avec les tickets lus et les articles cochés avec leur prix sur une liste rangée par magasin. Vous pouvez aussi en noter à la main, en rayon.
                </x-empty-state>
            </div>
        @else
            <section class="card mb-6 overflow-hidden">
                <h2 class="font-display border-b border-stone-200 px-4 py-3 font-semibold text-stone-900">Les magasins face à {{ $stores['reference']->name }}</h2>
                <ul class="divide-y divide-stone-100">
                    @foreach ($stores['stores'] as $row)
                        <li wire:key="store-{{ $row['store']->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3">
                            <span class="size-2.5 shrink-0 rounded-full {{ \App\Support\Palette::dot($row['store']->color) }}" aria-hidden="true"></span>
                            <span class="min-w-0 flex-1 font-medium text-stone-900">{{ $row['store']->name }}</span>
                            <span class="basis-full pl-5 text-sm text-stone-700 sm:basis-auto sm:pl-0">
                                @if ($row['store']->id === $stores['reference']->id)
                                    Référence
                                @elseif ($row['diff'] === null)
                                    <span class="text-stone-500">{{ $row['common'] }} produit{{ $row['common'] > 1 ? 's' : '' }} en commun : trop peu pour comparer</span>
                                @elseif (abs($row['diff']) < 0.01)
                                    Mêmes prix
                                @else
                                    <strong class="font-semibold">{{ $pct($row['diff']) }} {{ $row['diff'] < 0 ? 'moins cher' : 'plus cher' }}</strong>
                                    <span class="text-stone-500">sur {{ $row['common'] }} produits en commun</span>
                                @endif
                            </span>
                            <span class="basis-full pl-5 text-xs text-stone-500">
                                {{ $row['products'] }} produit{{ $row['products'] > 1 ? 's' : '' }} relevé{{ $row['products'] > 1 ? 's' : '' }}@if ($row['cheapest'] > 0) · le moins cher pour {{ $row['cheapest'] }}@endif
                            </span>
                        </li>
                    @endforeach
                </ul>
                <p class="border-t border-stone-100 px-4 py-2 text-xs text-stone-500">
                    Dernier prix de chaque produit sur les 6 derniers mois, ramené au kilo, au litre ou à la pièce. L'écart est une moyenne sur les produits relevés dans les deux magasins
                    (au moins {{ \App\Services\Pricing\PriceComparison::MIN_COMMON }}). Magasin de référence : celui des listes de courses.
                </p>
            </section>

            <section class="card overflow-hidden">
                <div class="flex flex-wrap items-center gap-3 border-b border-stone-200 px-4 py-3">
                    <h2 class="font-display font-semibold text-stone-900">Produits</h2>
                    <span class="text-sm text-stone-500">{{ $compared }} comparé{{ $compared > 1 ? 's' : '' }} sur {{ $total }}</span>
                    <label class="ml-auto flex items-center gap-2 text-sm text-stone-600">
                        <input type="checkbox" wire:model.live="all" class="rounded border-stone-300 text-brand-600">
                        Relevés dans un seul magasin aussi
                    </label>
                    <div class="relative basis-full sm:basis-60">
                        <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" />
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Chercher un produit" aria-label="Chercher un produit" class="form-input pl-9">
                    </div>
                </div>

                @if ($products->isEmpty())
                    <p class="px-4 py-6 text-sm text-stone-500">
                        @if ($search !== '') Aucun produit ne correspond. @else Aucun produit relevé dans deux magasins pour l'instant. @endif
                    </p>
                @else
                    <ul class="divide-y divide-stone-100">
                        @foreach ($products as $product)
                            @php $isOpen = $open === $product['key']; @endphp
                            <li wire:key="product-{{ $product['key'] }}">
                                <button type="button" wire:click="toggle('{{ $product['key'] }}')" aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                                        class="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-stone-50">
                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium text-stone-900">
                                            {{ $product['ingredient']->name }}
                                            @if ($product['gap'] !== null && $product['gap'] >= \App\Services\Pricing\PriceComparison::MIN_GAP)
                                                <span class="ml-1 text-sm font-normal text-stone-500">jusqu'à {{ $pct($product['gap']) }} d'écart</span>
                                            @endif
                                        </p>
                                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                                            @foreach ($product['prices'] as $row)
                                                <span @class([
                                                    'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs ring-1 ring-inset',
                                                    'bg-green-50 text-green-800 ring-green-200 font-medium' => $loop->first && $product['prices']->count() > 1,
                                                    'bg-stone-50 text-stone-700 ring-stone-200' => ! ($loop->first && $product['prices']->count() > 1),
                                                ]) title="Relevé le {{ $row['observed_on']->locale('fr')->isoFormat('D MMMM YYYY') }}">
                                                    <span class="size-1.5 rounded-full {{ \App\Support\Palette::dot($row['store']->color) }}" aria-hidden="true"></span>
                                                    {{ $row['store']->name }} · <span class="tabular-nums">{{ $row['label'] }}</span>
                                                    @if ($row['stale']) <span class="text-stone-500">(ancien)</span> @endif
                                                </span>
                                            @endforeach
                                        </div>
                                        @if ($product['best'])
                                            {{-- Lot 30 (R35) : une promotion ne se compare pas aux prix courants, elle est donnée à part. --}}
                                            <p class="mt-1.5 text-xs text-stone-600">
                                                Meilleur prix vu : <span class="font-medium text-stone-800 tabular-nums">{{ $product['best']['label'] }}</span>
                                                chez {{ $product['best']['store']->name }} le {{ $product['best']['observed_on']->locale('fr')->isoFormat('D MMM') }}
                                                <span class="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-900">promo</span>
                                            </p>
                                        @endif
                                    </div>
                                    <x-icon :name="$isOpen ? 'chevron-up' : 'chevron-down'" class="mt-1 size-4 shrink-0 text-stone-400" />
                                </button>

                                @if ($isOpen)
                                    <div class="border-t border-stone-100 bg-stone-50/60 px-4 py-3">
                                        <div class="mb-2 flex items-center gap-2">
                                            <h3 class="text-sm font-semibold text-stone-800">Derniers relevés</h3>
                                            @if ($canEdit)
                                                <button type="button" wire:click="openForm({{ $product['ingredient']->id }})" class="ml-auto text-sm font-medium text-brand-700 hover:underline">Noter un prix</button>
                                            @endif
                                        </div>
                                        <ul class="space-y-1 text-sm">
                                            @foreach ($this->history as $price)
                                                <li wire:key="price-{{ $price->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                                                    <span class="w-28 shrink-0 whitespace-nowrap text-stone-500 tabular-nums">{{ $price->observed_on->locale('fr')->isoFormat('D MMM YYYY') }}</span>
                                                    <span class="min-w-0 flex-1 text-stone-800">
                                                        {{ $price->store?->name ?? 'Magasin non précisé' }} ·
                                                        <span class="tabular-nums">{{ $prices->money((float) $price->price) }}</span>
                                                        @if ($price->quantity !== null)
                                                            <span class="text-stone-500">pour {{ rtrim(rtrim(number_format((float) $price->quantity, 3, ',', ''), '0'), ',') }} {{ $price->unit?->label ?? 'pièce' }}</span>
                                                        @endif
                                                    </span>
                                                    @if ($price->is_promo)
                                                        <span class="rounded-full bg-amber-100 px-1.5 py-0.5 text-xs font-semibold text-amber-900">promo</span>
                                                    @endif
                                                    <span class="text-xs text-stone-500">{{ ['manual' => 'noté', 'shopping' => 'liste', 'receipt' => 'ticket'][$price->source] ?? $price->source }}</span>
                                                    @if ($canEdit)
                                                        <button type="button" wire:click="deletePrice({{ $price->id }})" wire:confirm="Supprimer ce relevé ?" class="btn btn-ghost -my-1 px-1.5 text-stone-500 hover:text-red-700" title="Supprimer ce relevé">
                                                            <x-icon name="delete" class="size-4" /><span class="sr-only">Supprimer ce relevé</span>
                                                        </button>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif
    @else
        {{-- ================================================================ C2 — Évolution --}}
        @if ($rises->isNotEmpty())
            <section class="card mb-6 overflow-hidden ring-amber-200">
                <h2 class="flex items-center gap-2 border-b border-amber-100 bg-amber-50 px-4 py-3 font-semibold text-amber-900">
                    <x-icon name="trending-up" class="size-5" /> Hausses marquées
                </h2>
                <ul class="divide-y divide-stone-100">
                    @foreach ($rises as $rise)
                        <li wire:key="rise-{{ $loop->index }}" class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 px-4 py-2.5 text-sm">
                            <span class="font-medium text-stone-900">{{ $rise['ingredient']->name }}</span>
                            <span class="text-stone-500">{{ $rise['store']?->name ?? 'magasin non précisé' }}</span>
                            <span class="ml-auto font-semibold text-amber-900 tabular-nums">{{ $signed($rise['change'], 0) }}</span>
                            <span class="basis-full text-xs text-stone-600 tabular-nums">
                                {{ $rise['label_before'] }} le {{ $rise['before_on']->locale('fr')->isoFormat('D MMM') }} → {{ $rise['label_after'] }} le {{ $rise['after_on']->locale('fr')->isoFormat('D MMM') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
                <p class="border-t border-stone-100 px-4 py-2 text-xs text-stone-500">Dernier relevé au moins {{ $pct(\App\Services\Pricing\PersonalInflation::ALERT) }} au-dessus du précédent, dans le même magasin, depuis moins de {{ \App\Services\Pricing\PersonalInflation::ALERT_DAYS }} jours. Une promotion qui se termine compte aussi.</p>
            </section>
        @endif

        <section class="card mb-6 p-5">
            <h2 class="font-display font-semibold text-stone-900">Notre panier</h2>
            @if ($index['change'] === null)
                <p class="mt-2 text-sm text-stone-600">
                    @if ($index['observations'] === 0)
                        Aucun prix relevé sur les 12 derniers mois.
                    @else
                        Pas encore assez de recul : il faut au moins {{ \App\Services\Pricing\PersonalInflation::MIN_BASKET }} produits relevés dans les
                        {{ \App\Services\Pricing\PersonalInflation::BASE_MONTHS }} premiers mois puis revus dans le même magasin.
                        Aujourd'hui : {{ $index['basket'] }} produit{{ $index['basket'] > 1 ? 's' : '' }}, {{ $index['observations'] }} relevé{{ $index['observations'] > 1 ? 's' : '' }}.
                    @endif
                </p>
            @else
                @php
                    $months = $index['months'];
                    $values = array_column($months, 'index');
                    $low = min(100, min($values));
                    $high = max(100, max($values));
                    $pad = max(1, ($high - $low) * 0.15);
                    [$low, $high] = [$low - $pad, $high + $pad];
                    $n = count($months);
                    // Positions en pourcentage de la zone du graphique : les points et les mois restent alignés à toute largeur.
                    $x = fn (int $i) => $n > 1 ? round(4 + $i * (92 / ($n - 1)), 2) : 50;
                    $y = fn (float $v) => round(100 - ($v - $low) / ($high - $low) * 100, 2);
                    $points = collect($months)->map(fn ($m, $i) => $x($i).','.$y($m['index']))->join(' ');
                @endphp
                <div class="mt-2 flex flex-wrap items-end gap-x-6 gap-y-1">
                    <p class="text-3xl font-bold text-stone-900 tabular-nums">{{ $signed($index['change']) }}</p>
                    <p class="text-sm text-stone-600">depuis {{ $index['since']->locale('fr')->isoFormat('MMMM YYYY') }}, sur {{ $index['basket'] }} produits suivis</p>
                </div>

                <figure class="mt-5">
                    <div class="relative h-40" role="img"
                         aria-label="Indice du panier par mois, 100 au départ : {{ collect($months)->map(fn ($m) => $m['label'].' '.number_format($m['index'], 1, ',', ''))->join(', ') }}">
                        <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="absolute inset-0 size-full overflow-visible" aria-hidden="true">
                            <line x1="0" x2="100" y1="{{ $y(100) }}" y2="{{ $y(100) }}" class="stroke-stone-300" stroke-width="1" stroke-dasharray="4 4" vector-effect="non-scaling-stroke" />
                            <polyline points="{{ $points }}" fill="none" class="stroke-brand-600" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                        </svg>
                        @foreach ($months as $i => $month)
                            <span wire:key="point-{{ $i }}" class="group absolute flex size-7 -translate-x-1/2 -translate-y-1/2 items-center justify-center"
                                  style="left: {{ $x($i) }}%; top: {{ $y($month['index']) }}%" title="{{ $month['label'] }} : {{ number_format($month['index'], 1, ',', '') }}">
                                <span class="size-2.5 rounded-full bg-brand-600 ring-2 ring-white transition group-hover:scale-150"></span>
                            </span>
                        @endforeach
                    </div>
                    <div class="relative mt-2 h-4 text-[11px] text-stone-500 sm:text-xs" aria-hidden="true">
                        @foreach ($months as $i => $month)
                            <span @class(['absolute -translate-x-1/2 whitespace-nowrap', 'hidden sm:inline' => $n > 6 && ($n - 1 - $i) % 3 !== 0])
                                  style="left: {{ $x($i) }}%">{{ $month['short'] }}</span>
                        @endforeach
                    </div>
                    <figcaption class="mt-3 text-xs text-stone-500">
                        Pointillés : 100, le niveau de {{ $index['since']->locale('fr')->isoFormat('MMMM YYYY') }}. Chaque mois : le dernier prix connu de chaque produit du panier, dans le même magasin,
                        pondéré par ce qu'on y a dépensé. Changer de magasin ne compte pas comme une hausse. {{ $index['observations'] }} relevés sur 12 mois.
                    </figcaption>
                    <details class="mt-2 text-sm">
                        <summary class="cursor-pointer text-xs font-medium text-stone-600 hover:text-stone-900">Voir les chiffres</summary>
                        <table class="mt-2 text-sm">
                            <tbody>
                                @foreach ($months as $month)
                                    <tr><th scope="row" class="pr-4 text-left font-normal text-stone-600">{{ $month['label'] }}</th><td class="text-right text-stone-900 tabular-nums">{{ number_format($month['index'], 1, ',', '') }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </details>
                </figure>
            @endif
        </section>

        <section class="card overflow-hidden">
            <h2 class="font-display border-b border-stone-200 px-4 py-3 font-semibold text-stone-900">Produits suivis</h2>
            @if ($series->isEmpty())
                <p class="px-4 py-6 text-sm text-stone-500">Un produit est suivi dès qu'il a été relevé deux fois dans le même magasin, à au moins 4 semaines d'écart.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-stone-500">
                                <th class="px-4 py-2 font-medium">Produit</th>
                                <th class="hidden px-2 py-2 font-medium sm:table-cell">Premier relevé</th>
                                <th class="px-2 py-2 font-medium">Dernier relevé</th>
                                <th class="px-4 py-2 text-right font-medium">Évolution</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($series as $s)
                                <tr wire:key="series-{{ $s['key'] }}">
                                    <td class="px-4 py-2">
                                        <span class="font-medium text-stone-900">{{ $s['ingredient']->name }}</span>
                                        <span class="block text-xs text-stone-500">{{ $s['store']?->name ?? 'Magasin non précisé' }} · {{ $s['count'] }} relevés</span>
                                    </td>
                                    <td class="hidden px-2 py-2 whitespace-nowrap text-stone-600 tabular-nums sm:table-cell">
                                        {{ $s['first_label'] }}<span class="block text-xs text-stone-500">{{ $s['first']['on']->locale('fr')->isoFormat('D MMM YYYY') }}</span>
                                    </td>
                                    <td class="px-2 py-2 whitespace-nowrap text-stone-800 tabular-nums">
                                        {{ $s['last_label'] }}<span class="block text-xs text-stone-500">{{ $s['last']['on']->locale('fr')->isoFormat('D MMM YYYY') }}</span>
                                    </td>
                                    <td @class(['px-4 py-2 text-right font-semibold whitespace-nowrap tabular-nums',
                                                'text-amber-800' => $s['change'] >= \App\Services\Pricing\PersonalInflation::ALERT,
                                                'text-green-800' => $s['change'] <= -0.05,
                                                'text-stone-800' => $s['change'] > -0.05 && $s['change'] < \App\Services\Pricing\PersonalInflation::ALERT])>
                                        {{ abs($s['change']) < 0.001 ? '=' : $signed($s['change']) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    {{-- ==================================================================== Noter un prix --}}
    <x-modal :show="$showForm" title="Noter un prix" close="closeForm">
        <form wire:submit="savePrice" id="price-form" class="space-y-4">
            <datalist id="price-ingredients">
                @foreach ($this->ingredientNames as $name) <option value="{{ $name }}"></option> @endforeach
            </datalist>
            <x-field label="Produit" for="note-name" error="noteName">
                <input id="note-name" type="text" wire:model="noteName" list="price-ingredients" maxlength="150" autocomplete="off" class="form-input" placeholder="ex. Beurre">
            </x-field>
            <x-field label="Magasin" for="note-store" error="noteStoreId">
                <select id="note-store" wire:model="noteStoreId" class="form-input">
                    <option value="">—</option>
                    @foreach (\App\Models\Store::query()->ordered()->get() as $store) <option value="{{ $store->id }}">{{ $store->name }}</option> @endforeach
                </select>
            </x-field>
            <div class="grid grid-cols-3 gap-3">
                <x-field label="Prix (€)" for="note-price" error="notePrice">
                    <input id="note-price" type="text" inputmode="decimal" wire:model="notePrice" class="form-input" placeholder="2,49">
                </x-field>
                <x-field label="Pour" for="note-quantity" error="noteQuantity">
                    <input id="note-quantity" type="text" inputmode="decimal" wire:model="noteQuantity" class="form-input" placeholder="500">
                </x-field>
                <x-field label="Unité" for="note-unit" error="noteUnitId">
                    <select id="note-unit" wire:model="noteUnitId" class="form-input">
                        <option value="">pièce</option>
                        @foreach ($this->units as $unit) @continue($unit->code === 'piece') <option value="{{ $unit->id }}">{{ $unit->label }}</option> @endforeach
                    </select>
                </x-field>
            </div>
            <p class="-mt-2 text-xs text-stone-500">Le prix tel qu'affiché : « 2,49 € pour 500 g ». Sans quantité : pour une unité.</p>
            <x-field label="Relevé le" for="note-date" error="noteDate">
                <input id="note-date" type="date" wire:model="noteDate" max="{{ now()->toDateString() }}" class="form-input">
            </x-field>
            {{-- Lot 30 (R35) --}}
            <label class="flex items-start gap-3 text-sm text-stone-700">
                <input type="checkbox" wire:model="notePromo" class="form-checkbox mt-0.5">
                <span>En promotion <span class="block text-xs text-stone-500">Montré comme « meilleur prix vu », sans changer le prix habituel ni l'indice du panier.</span></span>
            </label>
        </form>
        <x-slot:footer>
            <button type="button" wire:click="closeForm" class="btn btn-secondary">Annuler</button>
            <button type="submit" form="price-form" class="btn btn-primary">Enregistrer</button>
        </x-slot:footer>
    </x-modal>
</div>
