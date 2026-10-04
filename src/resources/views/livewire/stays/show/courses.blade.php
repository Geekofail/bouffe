{{-- Séjour — courses (34.2). --}}
@php $summary = $this->shoppingSummary; $list = $summary['list']; $organizer = $role === \App\Services\Stays\StayCoorganizers::ORGANIZER; @endphp
<div class="grid gap-6 lg:grid-cols-3" data-stay-shopping>
    <section class="card space-y-4 p-4 sm:p-5 lg:col-span-2">
        @if (! $list)
            <h2 class="font-display text-lg font-semibold text-stone-900">La liste de courses du séjour</h2>
            <p class="text-sm text-stone-600">
                Calculée sur les repas du séjour, pour les présents de chaque jour. Elle ne tient pas compte du stock de la maison
                (on n'y est pas), sauf de ce que vous emportez (onglet « À emporter »). Elle n'apparaît pas parmi les listes en cours
                de la maison.
            </p>
            @if ($canEdit)
                <button type="button" wire:click="createList" class="btn btn-primary"><x-icon name="cart" class="size-4" /> Préparer la liste</button>
            @endif
        @else
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="font-display flex-1 text-lg font-semibold text-stone-900">{{ $list->name }}</h2>
                <x-shopping.progress :checked="$summary['checked']" :total="$summary['total']" class="w-40" />
            </div>
            <p class="text-sm text-stone-600">
                {{ $summary['total'] }} article{{ $summary['total'] > 1 ? 's' : '' }}, {{ $summary['checked'] }} coché{{ $summary['checked'] > 1 ? 's' : '' }}.
                @if ($summary['updated']) Calculée le {{ $summary['updated']->locale('fr')->isoFormat('D MMMM à HH:mm') }}. @endif
            </p>
            <div class="flex flex-wrap gap-2">
                {{-- Lot 42 : la liste est rangée chez l'organisateur ; un foyer qui co-organise la voit comme une liste groupée et y coche en magasin. --}}
                <a href="{{ $organizer ? route('shopping.show', $list) : route('linked.list', $list) }}" wire:navigate class="btn btn-primary"><x-icon name="list" class="size-4" /> Ouvrir la liste</a>
                <a href="{{ route('shopping.store', $list) }}" class="btn btn-secondary"><x-icon name="store" class="size-4" /> Mode magasin</a>
                @if ($canEdit)
                    <button type="button" wire:click="refreshList" class="btn btn-ghost"><x-icon name="rotate" class="size-4" /> Mettre à jour d'après les repas</button>
                @endif
            </div>
            @if ($list->shared_with_links)
                <p class="rounded-lg bg-sky-50 p-3 text-sm text-sky-900 ring-1 ring-sky-100">
                    Liste ouverte aux foyers reliés qui viennent : ils la voient dans leurs courses et peuvent y ajouter leurs articles.
                </p>
            @endif
        @endif
    </section>

    <aside class="space-y-3 text-sm text-stone-600">
        <div class="card p-4">
            <h3 class="mb-1 font-semibold text-stone-900">Qui paie ?</h3>
            <p>Chacun coche ce qu'il achète. Le ticket de caisse se note dans l'onglet <button type="button" wire:click="selectTab('frais')" class="font-medium text-brand-700 underline">Frais</button> : Bouffe calcule ensuite qui doit combien.</p>
        </div>
    </aside>
</div>
