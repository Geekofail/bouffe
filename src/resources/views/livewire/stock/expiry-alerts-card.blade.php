<div>
    @if ($hasStock || $undoOffer)
        <section class="card p-5">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 class="font-display flex items-center gap-2 text-lg font-semibold text-stone-900">
                    <x-icon name="pantry" class="size-5 text-brand-600" /> À consommer rapidement
                    @if ($this->alerts->isNotEmpty())
                        <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-600 tabular-nums">{{ $this->alerts->count() }}</span>
                    @endif
                </h2>
                <a href="{{ route('stock.index', ['filtre' => 'alertes']) }}" wire:navigate class="text-sm font-medium whitespace-nowrap text-brand-700 hover:underline">Voir le stock</a>
            </div>

            @if ($undoOffer)
                <div wire:key="card-undo-{{ $undoOffer['movement_id'] }}" x-data x-init="setTimeout(() => $wire.dismissUndo(), 12000)"
                     class="mb-3 flex items-center gap-3 rounded-lg bg-stone-900 px-3 py-2 text-sm text-white">
                    <span class="flex-1">{{ $undoOffer['message'] }}</span>
                    <button type="button" wire:click="undo" class="rounded-md bg-white/15 px-2 py-0.5 font-medium hover:bg-white/25">Annuler</button>
                </div>
            @endif

            @if ($shown->isEmpty())
                <p class="flex items-center gap-2 text-sm text-herb-700"><x-icon name="success" class="size-5" /> Rien ne presse dans le stock.</p>
            @else
                <ul class="divide-y divide-stone-100">
                    @foreach ($shown as $row)
                        @php
                            $item = $row['item'];
                            $canFreeze = ! $item->isFrozen() && ($item->isPrepared() || $item->ingredient?->freezer_months !== null) && $row['level'] !== \App\Enums\ExpiryLevel::Expired;
                            $canOpen = ! $item->opened_on && ! $item->isFrozen() && $item->ingredient?->days_after_opening !== null;
                        @endphp
                        <li wire:key="alert-{{ $item->id }}" class="py-2.5">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                <div class="min-w-0 flex-1">
                                    <p class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium text-stone-900">{{ $item->name() }}</span>
                                        <x-badge :color="$row['badge']['color']" title="{{ $row['badge']['title'] }}">{{ $row['badge']['text'] }}</x-badge>
                                    </p>
                                    <p class="text-xs text-stone-500">
                                        @if ($item->quantity !== null) {{ $formatter->format((float) $item->quantity, $item->unit) }} · @endif
                                        {{ $item->location->name }}
                                        @if ($item->opened_on && ! $item->isFrozen()) · ouvert @endif
                                        @if ($row['level'] === \App\Enums\ExpiryLevel::Expired) · <span class="font-medium text-red-700">date limite dépassée : à jeter</span>
                                        @elseif ($row['level'] === \App\Enums\ExpiryLevel::DdmPassed) · <span class="text-amber-700">encore consommable, à vérifier</span> @endif
                                    </p>
                                </div>
                                <div class="flex flex-wrap gap-1">
                                    <button type="button" wire:click="consume({{ $item->id }})" class="btn btn-secondary px-2.5 py-1 text-xs" title="Consommé">✓ Consommé</button>
                                    <button type="button" wire:click="askWaste({{ $item->id }})" @class(['btn px-2.5 py-1 text-xs', 'btn-secondary text-red-700' => $wastingId !== $item->id, 'bg-red-600 text-white' => $wastingId === $item->id])>Jeté</button>
                                    @if ($canFreeze)
                                        <button type="button" wire:click="freeze({{ $item->id }})" class="btn btn-secondary px-2.5 py-1 text-xs">Congeler</button>
                                    @endif
                                    @if ($canOpen)
                                        <button type="button" wire:click="open({{ $item->id }})" class="btn btn-secondary px-2.5 py-1 text-xs">Ouvert</button>
                                    @endif
                                    <button type="button" wire:click="askDate({{ $item->id }})" class="btn btn-ghost px-2 py-1 text-xs" title="Nouvelle date">Date…</button>
                                    @if ($item->ingredient_id && $row['level'] !== \App\Enums\ExpiryLevel::Expired)
                                        <a href="{{ route('suggestions', ['utiliser' => [$item->ingredient_id]]) }}" wire:navigate class="btn btn-ghost px-2 py-1 text-xs" title="Trouver une recette qui l'utilise">Recette…</a>
                                    @endif
                                </div>
                            </div>

                            @if ($wastingId === $item->id)
                                <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                                    <span class="text-stone-500">Pourquoi ?</span>
                                    @foreach (\App\Livewire\Stock\Index::WASTE_REASONS as $reason)
                                        <button type="button" wire:click="waste({{ $item->id }}, '{{ $reason }}')" class="rounded-full bg-red-50 px-2.5 py-1 text-red-800 ring-1 ring-red-200 hover:bg-red-100">{{ ucfirst($reason) }}</button>
                                    @endforeach
                                </div>
                            @endif

                            @if ($datingId === $item->id)
                                <form wire:submit="saveDate" class="mt-2 flex flex-wrap items-center gap-2">
                                    <input type="date" wire:model="newDate" class="form-input w-auto py-1 text-sm" aria-label="Nouvelle date limite">
                                    <button type="submit" class="btn btn-primary px-3 py-1 text-xs">Enregistrer</button>
                                    @error('newDate') <span class="form-error mt-0">{{ $message }}</span> @enderror
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($ideas = $this->recipeIdeas)
                    <a href="{{ route('suggestions', ['utiliser' => $ideas['ingredients']->pluck('id')->all()]) }}" wire:navigate
                       class="mt-3 flex items-center gap-3 rounded-lg bg-green-50 px-3 py-2.5 text-sm text-green-900 ring-1 ring-green-100 hover:bg-green-100">
                        <span aria-hidden="true">♻</span>
                        <span class="min-w-0 flex-1">
                            <strong>À utiliser rapidement</strong> : {{ $ideas['ingredients']->pluck('name')->join(', ') }}
                            <span class="block text-green-800">
                                {{ $ideas['count'] === 0 ? 'Chercher une recette qui les utilise' : $ideas['count'].' recette'.($ideas['count'] > 1 ? 's possibles' : ' possible') }}
                            </span>
                        </span>
                        <span class="font-semibold whitespace-nowrap">Que cuisiner ?</span>
                    </a>
                @endif
                @if ($this->alerts->count() > $shown->count())
                    <a href="{{ route('stock.index', ['filtre' => 'alertes']) }}" wire:navigate class="mt-2 inline-block text-sm text-brand-700 hover:underline">
                        + {{ $this->alerts->count() - $shown->count() }} autre{{ $this->alerts->count() - $shown->count() > 1 ? 's' : '' }}
                    </a>
                @endif
            @endif
        </section>
    @endif
</div>
