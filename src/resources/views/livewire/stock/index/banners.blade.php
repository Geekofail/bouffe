{{-- Stock : bandeaux — annuler, réassort, vérification, apprentissages, revue, minimum (lot 36). --}}
{{-- ============================================================ Bandeaux --}}
@if ($undoOffer)
    <div wire:key="undo-{{ $undoOffer['movement_id'] }}" x-data x-init="setTimeout(() => $wire.dismissUndo(), 12000)"
         class="mb-3 flex items-center gap-3 rounded-xl bg-stone-900 px-4 py-2.5 text-sm text-white shadow">
        <x-icon name="success" class="size-5 shrink-0 text-herb-400" />
        <span class="flex-1">{{ $undoOffer['message'] }}</span>
        <button type="button" wire:click="undo" class="rounded-md bg-white/15 px-2.5 py-1 font-medium hover:bg-white/25">Annuler</button>
    </div>
@endif

@if ($restockOffer)
    <div class="mb-3 flex flex-wrap items-center gap-3 rounded-xl bg-sky-50 px-4 py-2.5 text-sm text-sky-900 ring-1 ring-sky-100">
        <x-icon name="cart" class="size-5 shrink-0" />
        <span class="flex-1">Ajouter « {{ $restockOffer['name'] }} » à la liste de courses en cours ?</span>
        <button type="button" wire:click="addToShoppingList" class="btn btn-primary py-1">Ajouter</button>
        <button type="button" wire:click="dismissRestock" class="btn btn-secondary py-1">Non</button>
    </div>
@endif

{{-- Vérification ciblée (lot 21, 22.5) --}}
@if ($this->toCheck->isNotEmpty() && $filter === '' && trim($search) === '')
    <div class="mb-3 flex flex-wrap items-center gap-3 rounded-xl bg-sky-50 px-4 py-3 text-sm text-sky-900 ring-1 ring-sky-100">
        <x-icon name="info" class="size-5 shrink-0" />
        <span class="flex-1">{{ $this->toCheck->count() }} article{{ $this->toCheck->count() > 1 ? 's' : '' }} à vérifier : le stock a peut-être dérivé.</span>
        <button type="button" wire:click="$set('showCheck', true)" class="btn btn-primary py-1">Vérifier</button>
    </div>
@endif

{{-- Bouffe a remarqué (lot 30, 30.4 et 30.5, R36) --}}
@if ($this->learnings->isNotEmpty() && $filter === '' && trim($search) === '' && auth()->user()->canEdit())
    <section class="mb-3 rounded-xl bg-herb-50 px-4 py-3 text-sm text-herb-900 ring-1 ring-herb-100" aria-label="Bouffe a remarqué">
        <p class="flex items-center gap-2 font-semibold"><x-icon name="sparkles" class="size-5 shrink-0" /> Bouffe a remarqué</p>
        <ul class="mt-2 space-y-3">
            @foreach ($this->learnings as $learning)
                <li wire:key="learn-{{ $learning['key'] }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 pl-7">
                    <div class="min-w-0 flex-1 basis-60">
                        <p>{{ $learning['text'] }}</p>
                        <p class="text-xs text-herb-800">{{ $learning['detail'] }}</p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" wire:click="applyLearning('{{ $learning['kind'] }}', {{ $learning['ingredient']->id }})" class="btn btn-primary py-1.5">Appliquer</button>
                        <button type="button" wire:click="dismissLearning('{{ $learning['kind'] }}', {{ $learning['ingredient']->id }})" class="btn btn-secondary py-1.5">Non merci</button>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
@endif

{{-- Inventaire par ancienneté (lot 30, 30.8) --}}
@if ($this->idleCount >= \App\Services\Stock\StockReview::BANNER_MIN && $filter === '' && trim($search) === '' && auth()->user()->canEdit())
    <div class="mb-3 flex flex-wrap items-center gap-3 rounded-xl bg-stone-100 px-4 py-3 text-sm text-stone-800">
        <x-icon name="clock" class="size-5 shrink-0" />
        <span class="flex-1">{{ $this->idleCount }} articles n'ont pas bougé depuis 2 mois.</span>
        <a href="{{ route('stock.review') }}" wire:navigate class="btn btn-secondary py-1">Les passer en revue</a>
    </div>
@endif

<x-modal :show="$showCheck" title="Vérification rapide" close="closeCheck" max-width="max-w-lg">
    <p class="mb-3 text-sm text-stone-500">Quelques articles seulement, choisis parce que leur fiche semble ne plus correspondre. Un geste par article.</p>
    <ul class="divide-y divide-stone-100">
        @foreach ($this->toCheck as $row)
            @php $item = $row['item']; @endphp
            <li wire:key="check-{{ $item->id }}" class="py-2.5">
                <p class="font-medium text-stone-900">{{ $item->name() }}
                    <span class="text-sm font-normal text-stone-500">· {{ $item->location->name }}@if ($item->quantity !== null) · {{ $formatter->format((float) $item->quantity, $item->unit) }}@endif</span>
                </p>
                <p class="text-xs text-stone-500">{{ $row['reason'] }}</p>
                <div class="mt-1.5 flex flex-wrap gap-2">
                    <button type="button" wire:click="markChecked({{ $item->id }})" class="btn btn-secondary px-2 py-1 text-xs"><x-icon name="check" class="size-3.5" /> Toujours là</button>
                    <button type="button" wire:click="finish({{ $item->id }})" class="btn btn-secondary px-2 py-1 text-xs">Terminé</button>
                    <button type="button" wire:click="waste({{ $item->id }}, 'périmé')" class="btn btn-ghost px-2 py-1 text-xs text-red-700">Jeté</button>
                    <button type="button" wire:click="select({{ $item->id }})" x-on:click="$wire.set('showCheck', false)" class="btn btn-ghost px-2 py-1 text-xs">Corriger la quantité</button>
                </div>
            </li>
        @endforeach
    </ul>
</x-modal>

@if ($filter === 'minimum' && $this->belowMinimum->isNotEmpty())
    <div class="mb-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-100">
        <div class="flex flex-wrap items-center gap-3">
            <span class="flex-1 font-medium">{{ $this->belowMinimum->count() }} ingrédient{{ $this->belowMinimum->count() > 1 ? 's' : '' }} sous le stock minimum</span>
            <button type="button" wire:click="addMinimumToShoppingList" class="btn btn-primary py-1"><x-icon name="cart" class="size-4" /> Ajouter à la liste en cours</button>
        </div>
        <ul class="mt-2 space-y-0.5">
            @foreach ($this->belowMinimum as $row)
                <li wire:key="min-{{ $row['ingredient']->id }}"><span class="font-medium">{{ $row['ingredient']->name }}</span> : {{ $row['text'] }}</li>
            @endforeach
        </ul>
    </div>
@endif
