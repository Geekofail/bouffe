<div>
    <x-page-header title="Surplus à donner" subtitle="Trop de courgettes, une part de lasagnes en trop : proposez-les à vos proches plutôt que de les jeter." />
    <x-linked-nav />

    @unless ($hasLinks)
        <div class="mb-6 flex gap-3 rounded-xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200">
            <x-icon name="info" class="size-5 shrink-0" />
            <p>Aucun foyer relié pour l'instant : vos annonces ne seront vues de personne. <a href="{{ route('linked.index') }}" wire:navigate class="font-medium underline">Relier un foyer</a></p>
        </div>
    @endunless

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- ============================================================ Chez les proches --}}
        <section class="card p-4 sm:p-5">
            <h2 class="font-display mb-3 font-semibold text-stone-900">Proposé par vos proches</h2>
            <ul class="divide-y divide-stone-100">
                @forelse ($theirs as $offer)
                    @php $mineReserved = (int) $offer->reserved_by_household_id === (int) $me; @endphp
                    <li wire:key="t-{{ $offer->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-stone-900">{{ $offer->label }}{{ $offer->quantity ? ' · '.$offer->quantity : '' }}</p>
                            <p class="text-sm text-stone-500">{{ $offer->household?->name }} · jusqu'au {{ $offer->available_until->locale('fr')->isoFormat('dddd D MMMM') }}{{ $offer->note ? ' · '.$offer->note : '' }}</p>
                        </div>
                        @if ($mineReserved)
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-800 ring-1 ring-emerald-200"><x-icon name="check" class="size-3.5" /> Réservé pour nous</span>
                            <button type="button" wire:click="release({{ $offer->id }})" class="text-sm text-stone-500 underline hover:text-red-700">Annuler</button>
                        @else
                            <button type="button" wire:click="reserve({{ $offer->id }})" class="btn btn-primary py-1 text-sm">Je prends</button>
                        @endif
                    </li>
                @empty
                    <li class="py-2 text-sm text-stone-500">Rien à prendre en ce moment.</li>
                @endforelse
            </ul>
        </section>

        {{-- ============================================================ Nos annonces --}}
        <section class="card p-4 sm:p-5">
            <h2 class="font-display mb-3 font-semibold text-stone-900">Nos annonces</h2>

            @if ($canEdit)
                <form wire:submit="offer" class="mb-4 grid gap-3 rounded-lg bg-stone-50 p-3 sm:grid-cols-2">
                    <x-field label="Du stock" for="sp-item" optional class="sm:col-span-2" help="Retiré du stock à la remise seulement.">
                        <select id="sp-item" wire:model.live="stockItemId" class="form-input">
                            <option value="">— Autre chose —</option>
                            @foreach ($stockItems as $item)
                                <option value="{{ $item->id }}">{{ $item->name() }}{{ $item->expires_on ? ' (jusqu\'au '.$item->expires_on->locale('fr')->isoFormat('D MMM').')' : '' }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    <x-field label="Quoi" for="sp-label" error="label">
                        <input id="sp-label" type="text" maxlength="150" wire:model="label" class="form-input" placeholder="{{ $stockItemId ? 'Nom de l\'article du stock' : 'Courgettes' }}">
                    </x-field>
                    <x-field label="Combien" for="sp-qty" error="quantity" optional>
                        <input id="sp-qty" type="text" maxlength="60" wire:model="quantity" class="form-input" placeholder="1 kg, une part…">
                    </x-field>
                    <x-field label="Jusqu'au" for="sp-until" error="until">
                        <input id="sp-until" type="date" wire:model="until" class="form-input">
                    </x-field>
                    <x-field label="Précision" for="sp-note" error="note" optional>
                        <input id="sp-note" type="text" maxlength="255" wire:model="note" class="form-input" placeholder="À prendre le soir">
                    </x-field>
                    <div class="sm:col-span-2"><button type="submit" class="btn btn-primary"><x-icon name="share" class="size-4" /> Proposer</button></div>
                </form>
            @endif

            <ul class="divide-y divide-stone-100">
                @forelse ($mine as $offer)
                    @php $status = $offer->status(); @endphp
                    <li wire:key="m-{{ $offer->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5">
                        <div class="min-w-0 flex-1">
                            <p @class(['font-medium', 'text-stone-900' => in_array($status, ['open', 'reserved'], true), 'text-stone-500 line-through' => ! in_array($status, ['open', 'reserved'], true)])>{{ $offer->label }}{{ $offer->quantity ? ' · '.$offer->quantity : '' }}</p>
                            <p class="text-sm text-stone-500">
                                @switch($status)
                                    @case('reserved') Réservé par {{ $offer->reservedBy?->name }}{{ $offer->reservedByUser ? ' ('.$offer->reservedByUser->name.')' : '' }} @break
                                    @case('handed') Remis le {{ $offer->handed_at->locale('fr')->isoFormat('D MMMM') }} @break
                                    @case('cancelled') Retiré @break
                                    @case('expired') Date dépassée @break
                                    @default En attente, jusqu'au {{ $offer->available_until->locale('fr')->isoFormat('dddd D MMMM') }}
                                @endswitch
                            </p>
                        </div>
                        @if ($canEdit && $status === 'reserved')
                            <button type="button" wire:click="handOver({{ $offer->id }})" class="btn btn-primary py-1 text-sm"><x-icon name="check" class="size-4" /> Remis</button>
                        @endif
                        @if ($canEdit && in_array($status, ['open', 'reserved', 'expired'], true))
                            <button type="button" wire:click="cancel({{ $offer->id }})" wire:confirm="Retirer cette annonce ?" class="text-sm text-stone-500 underline hover:text-red-700">Retirer</button>
                        @endif
                    </li>
                @empty
                    <li class="py-2 text-sm text-stone-500">Aucune annonce.</li>
                @endforelse
            </ul>
        </section>
    </div>
</div>
