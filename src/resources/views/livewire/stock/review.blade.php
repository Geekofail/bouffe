<div class="mx-auto max-w-2xl">
    <a href="{{ route('stock.index') }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> Stock
    </a>

    @php
        $total = $this->items->count();
        $current = $this->current;
    @endphp

    <x-page-header title="Revue du stock"
                   :subtitle="$total === 0 ? 'Tout a bougé ces deux derniers mois.' : $total.' article'.($total > 1 ? 's' : '').' n\''.($total > 1 ? 'ont' : 'a').' pas bougé depuis '.intdiv($days, 30).' mois.'" />

    <x-hint key="stock-review" title="Le saviez-vous ?">
        Un article « bouge » quand il est ajouté, ouvert, entamé, déplacé ou vérifié. Ceux qui dorment depuis deux mois
        sont peut-être finis depuis longtemps : un geste suffit pour remettre la fiche d'aplomb.
    </x-hint>

    @if ($this->locations->count() > 1 || $location)
        <nav class="-mx-4 mb-4 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="Emplacements">
            <div class="flex min-w-max gap-1 rounded-xl bg-stone-100 p-1">
                <button type="button" wire:click="$set('location', 0)" aria-pressed="{{ $location === 0 ? 'true' : 'false' }}"
                        @class(['rounded-lg px-3 py-1.5 text-sm font-medium transition', 'bg-white text-stone-900 shadow-sm' => $location === 0, 'text-stone-600 hover:text-stone-900' => $location !== 0])>Tout</button>
                @foreach ($this->locations as $loc)
                    <button type="button" wire:key="loc-{{ $loc['id'] }}" wire:click="$set('location', {{ $loc['id'] }})" aria-pressed="{{ $location === $loc['id'] ? 'true' : 'false' }}"
                            @class(['flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium whitespace-nowrap transition', 'bg-white text-stone-900 shadow-sm' => $location === $loc['id'], 'text-stone-600 hover:text-stone-900' => $location !== $loc['id']])>
                        {{ $loc['name'] }} <span class="text-xs text-stone-500 tabular-nums">{{ $loc['count'] }}</span>
                    </button>
                @endforeach
            </div>
        </nav>
    @endif

    @if ($current)
        @php $position = $this->items->search(fn ($i) => $i->id === $current->id) + 1; @endphp
        <article class="card p-5" wire:key="review-{{ $current->id }}" aria-labelledby="review-name">
            <p class="text-xs font-medium tracking-wide text-stone-500 uppercase">{{ $position }} sur {{ $total }}@if ($done > 0) · {{ $done }} déjà revu{{ $done > 1 ? 's' : '' }}@endif</p>
            <h2 id="review-name" class="font-display mt-2 text-2xl font-semibold text-stone-900">{{ $current->name() }}</h2>
            <p class="mt-1 text-sm text-stone-600">
                {{ $current->location?->name }}
                @if ($current->quantity !== null) · {{ $formatter->format((float) $current->quantity, $current->unit) }} @endif
                @if ($current->opened_on) · ouvert le {{ $current->opened_on->locale('fr')->isoFormat('D MMMM') }} @endif
            </p>
            <p class="mt-1 text-sm text-stone-600">Rien depuis le {{ $current->idle_since->locale('fr')->isoFormat('D MMMM YYYY') }} ({{ $current->idle_since->locale('fr')->diffForHumans() }}).</p>

            <div class="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <button type="button" wire:click="keep({{ $current->id }})" class="btn btn-primary min-h-11"><x-icon name="check" class="size-4" /> Toujours là</button>
                <button type="button" wire:click="finish({{ $current->id }}, 'fini')" class="btn btn-secondary min-h-11">Fini</button>
                <button type="button" wire:click="finish({{ $current->id }}, 'jete')" class="btn btn-secondary min-h-11 text-red-700">Jeté</button>
                <button type="button" wire:click="skip({{ $current->id }})" class="btn btn-ghost min-h-11">Plus tard</button>
            </div>
        </article>

        @if ($total > 1)
            <details class="mt-4">
                <summary class="cursor-pointer text-sm font-medium text-stone-600">Voir la liste ({{ $total }})</summary>
                <ul class="card mt-2 divide-y divide-stone-100 text-sm">
                    @foreach ($this->items as $item)
                        <li class="flex items-center justify-between gap-3 px-4 py-2" wire:key="idle-{{ $item->id }}">
                            <span class="min-w-0 truncate text-stone-900">{{ $item->name() }} <span class="text-stone-500">· {{ $item->location?->name }}</span></span>
                            <span class="shrink-0 text-xs text-stone-500">{{ $item->idle_since->locale('fr')->isoFormat('D MMM') }}</span>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    @elseif ($total > 0)
        <div class="card p-6 text-center">
            <p class="text-stone-700">Les articles restants ont été passés pour plus tard.</p>
            <button type="button" wire:click="restart" class="btn btn-secondary mt-4">Les revoir</button>
        </div>
    @else
        <div class="card">
            <x-empty-state dish="assiette" title="{{ $done > 0 ? 'Revue terminée' : 'Rien à passer en revue' }}">
                {{ $done > 0 ? $done.' article'.($done > 1 ? 's' : '').' revu'.($done > 1 ? 's' : '').'. Le stock est à jour.' : 'Chaque article a bougé ces deux derniers mois.' }}
                <x-slot:actions>
                    <a href="{{ route('stock.index') }}" wire:navigate class="btn btn-secondary">Retour au stock</a>
                </x-slot:actions>
            </x-empty-state>
        </div>
    @endif
</div>
