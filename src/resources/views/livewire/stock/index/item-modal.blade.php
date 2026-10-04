{{-- Stock : fenêtre d'un article (lot 36). --}}
{{-- ============================================================ Fenêtre : article --}}
@php $selected = $this->selectedItem; @endphp
<x-modal :show="(bool) $selected" :title="$selected?->name() ?? ''" close="closeItem">
    @if ($selected)
        @php
            $badge = $expiry->badge($selected);
            $isPresence = $selected->ingredient?->stock_mode === \App\Enums\StockMode::Presence;
        @endphp
        <div class="space-y-4">
            <p class="flex flex-wrap items-center gap-2 text-sm text-stone-600">
                <x-icon name="pantry" class="size-4" /> {{ $selected->location->name }}
                @if ($selected->quantity !== null) · <strong class="text-stone-900">{{ $formatter->format((float) $selected->quantity, $selected->unit) }}</strong> @endif
                @if ($badge) <x-badge :color="$badge['color']">{{ $badge['text'] }}</x-badge> @endif
                @if ($selected->isFrozen()) <x-badge color="blue">congelé le {{ $selected->frozen_on->locale('fr')->isoFormat('D MMM') }}</x-badge>
                @elseif ($selected->opened_on) <x-badge color="violet">ouvert le {{ $selected->opened_on->locale('fr')->isoFormat('D MMM') }}</x-badge> @endif
            </p>
            @if ($badge) <p class="-mt-2 text-xs text-stone-500">{{ $badge['title'] }} · ajouté {{ $selected->created_at->locale('fr')->diffForHumans() }}</p> @endif

            {{-- Stock réservé (lot 21, R24) --}}
            @if ($reserved = $this->reservations[$selected->id] ?? null)
                <div class="rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-900 ring-1 ring-sky-100">
                    <p><strong>{{ $formatter->format($reserved['reserved'], $selected->unit) }}</strong> réservé{{ $reserved['reserved'] >= 2 ? 's' : '' }} pour les repas prévus
                        @if ($selected->quantity !== null)
                            · disponible : <strong>{{ $formatter->format(max(0, (float) $selected->quantity - $reserved['reserved']), $selected->unit) }}</strong>
                        @endif
                    </p>
                    <p class="text-xs text-sky-700">{{ collect($reserved['meals'])->pluck('label')->unique()->join(' · ') }}</p>
                </div>
            @endif

            {{-- Actions rapides --}}
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                <button type="button" wire:click="finish({{ $selected->id }})" class="btn btn-primary justify-center py-2.5"><x-icon name="success" class="size-4" /> Terminé</button>
                <button type="button" wire:click="$toggle('showWaste')" class="btn btn-secondary justify-center py-2.5 text-red-700"><x-icon name="delete" class="size-4" /> Jeté</button>
                @unless ($isPresence)
                    @if (! $selected->opened_on && ! $selected->isFrozen() && $selected->ingredient?->days_after_opening !== null)
                        <button type="button" wire:click="openItem({{ $selected->id }})" class="btn btn-secondary justify-center py-2.5">Ouvert</button>
                    @endif
                    @if ($selected->isFrozen())
                        <button type="button" wire:click="thaw({{ $selected->id }})" class="btn btn-secondary justify-center py-2.5">Décongeler</button>
                    @elseif ($selected->isPrepared() || $selected->ingredient?->freezer_months !== null)
                        <button type="button" wire:click="freeze({{ $selected->id }})" class="btn btn-secondary justify-center py-2.5">Congeler</button>
                    @endif
                @endunless
            </div>

            @if ($showWaste)
                <div class="flex flex-wrap items-center gap-2 rounded-lg bg-red-50 p-3 text-sm text-red-900">
                    <span class="font-medium">Pourquoi ?</span>
                    @foreach (\App\Livewire\Stock\Index::WASTE_REASONS as $reason)
                        <button type="button" wire:click="waste({{ $selected->id }}, '{{ $reason }}')" class="rounded-full bg-white px-3 py-1 ring-1 ring-red-200 hover:bg-red-100">{{ ucfirst($reason) }}</button>
                    @endforeach
                </div>
            @endif

            @unless ($isPresence)
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="text-stone-600">Il en reste :</span>
                    @foreach (['3/4' => '¾', '1/2' => '½', '1/4' => '¼'] as $fraction => $symbol)
                        <button type="button" wire:click="remaining({{ $selected->id }}, '{{ $fraction }}')" class="min-w-11 rounded-lg bg-stone-100 px-3 py-1.5 text-base font-semibold text-stone-800 hover:bg-stone-200">{{ $symbol }}</button>
                    @endforeach
                </div>
            @endunless

            <div class="flex items-center gap-2 text-sm">
                <label for="move-to" class="text-stone-600">Déplacer vers</label>
                <select id="move-to" class="form-input w-auto py-1.5" x-on:change="$wire.moveTo({{ $selected->id }}, parseInt($event.target.value))">
                    <option value="">…</option>
                    @foreach ($this->locations->where('id', '!=', $selected->storage_location_id) as $loc) <option value="{{ $loc->id }}">{{ $loc->name }}</option> @endforeach
                </select>
            </div>

            {{-- Correction --}}
            <details class="text-sm" @if ($errors->any()) open @endif>
                <summary class="cursor-pointer font-medium text-stone-700">Modifier la quantité, la date ou la note</summary>
                <form id="stock-item-form" wire:submit="saveItem" class="mt-3 space-y-3">
                    @unless ($isPresence)
                        <div class="grid grid-cols-[1fr_auto] gap-2">
                            <x-field label="Quantité" for="edit-qty" error="editQuantity" optional>
                                <input id="edit-qty" type="text" inputmode="decimal" wire:model="editQuantity" class="form-input">
                            </x-field>
                            <x-field label="Unité" for="edit-unit">
                                <select id="edit-unit" wire:model="editUnitId" class="form-input">
                                    <option value="">—</option>
                                    @foreach ($this->units as $unit) <option value="{{ $unit->id }}">{{ $unit->label }}</option> @endforeach
                                </select>
                            </x-field>
                        </div>
                    @endunless
                    <div class="grid grid-cols-2 gap-2">
                        <x-field label="Date limite" for="edit-date" error="editExpiresOn" optional>
                            <input id="edit-date" type="date" wire:model="editExpiresOn" class="form-input">
                        </x-field>
                        <x-field label="Type" for="edit-type">
                            <select id="edit-type" wire:model="editExpiryType" class="form-input">
                                @foreach ($expiryTypes as $type) <option value="{{ $type->value }}">{{ $type->label() }}</option> @endforeach
                            </select>
                        </x-field>
                    </div>
                    <x-field label="Note" for="edit-note" error="editNote" optional>
                        <input id="edit-note" type="text" wire:model="editNote" maxlength="255" class="form-input">
                    </x-field>
                    <button type="submit" class="btn btn-secondary w-full">Enregistrer les modifications</button>
                </form>
            </details>
        </div>

        <x-slot:footer>
            <button type="button" wire:click="closeItem" class="btn btn-secondary">Fermer</button>
        </x-slot:footer>
    @endif
</x-modal>
