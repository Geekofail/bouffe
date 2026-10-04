<div>
    <x-modal :show="$show" :title="$expenseId ? 'Modifier la dépense' : 'Nouvelle dépense'" close="close" max-width="max-w-lg">
        <form id="expense-form" wire:submit="save" class="space-y-4">
            @unless ($expenseId)
                <a href="{{ route('receipts.create') }}" wire:navigate x-on:click="$wire.close()" class="-mt-1 flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
                    <x-icon name="receipt" class="size-4" /> Un ticket de caisse ? Prenez-le en photo : il remplira aussi les prix et le stock.
                </a>
            @endunless
            <div class="grid grid-cols-2 gap-3">
                <x-field label="Montant (€)" for="expense-amount" error="amount">
                    <input id="expense-amount" type="text" inputmode="decimal" wire:model="amount" placeholder="72,40"
                           x-init="$nextTick(() => $el.focus())" @class(['form-input text-lg font-semibold', 'form-input-error' => $errors->has('amount')])>
                </x-field>
                <x-field label="Date" for="expense-date" error="spentOn">
                    <input id="expense-date" type="date" wire:model.live="spentOn" max="{{ today()->toDateString() }}" class="form-input">
                </x-field>
            </div>

            <x-field label="Poste" for="expense-category" error="categoryId">
                <select id="expense-category" wire:model.live="categoryId" class="form-input">
                    @foreach ($this->categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </x-field>

            <div class="grid grid-cols-2 gap-3">
                <x-field label="Magasin" for="expense-store">
                    <select id="expense-store" wire:model.live="storeId" class="form-input">
                        <option value="">Autre lieu…</option>
                        @foreach ($this->stores as $store) <option value="{{ $store->id }}">{{ $store->name }}</option> @endforeach
                    </select>
                </x-field>
                @if ($storeId === '')
                    <x-field label="Lieu" for="expense-place" error="place" optional>
                        <input id="expense-place" type="text" wire:model.blur="place" placeholder="{{ $eatingOut ? 'Chez Mario' : 'Marché, boulangerie…' }}" class="form-input">
                    </x-field>
                @endif
            </div>

            @if ($eatingOut)
                <div class="grid grid-cols-2 gap-3">
                    <x-field label="Personnes" for="expense-persons" error="persons" optional>
                        <input id="expense-persons" type="number" min="1" max="99" wire:model="persons" class="form-input">
                    </x-field>
                    @if (! $mealId && ! $expenseId)
                        <div class="flex flex-col justify-end">
                            <label class="flex items-center gap-2 text-sm text-stone-700">
                                <input type="checkbox" wire:model.live="addToPlanning" class="form-checkbox"> Ajouter au planning
                            </label>
                            @if ($addToPlanning)
                                <select wire:model="slotId" class="form-input mt-1 py-1.5 text-sm" aria-label="Créneau">
                                    @foreach ($slots as $slot) <option value="{{ $slot->id }}">{{ $slot->name }}</option> @endforeach
                                </select>
                            @endif
                        </div>
                    @endif
                </div>
            @endif

            @if ($isGroceries)
                <x-field label="Liste de courses" for="expense-list" optional
                         help="Rattachée à un ticket, la liste ne compte plus ses prix cochés : le ticket fait foi.">
                    <select id="expense-list" wire:model="listId" class="form-input">
                        <option value="">Aucune</option>
                        @foreach ($this->lists as $list) <option value="{{ $list->id }}">{{ $list->name }}</option> @endforeach
                    </select>
                </x-field>
            @endif

            @if ($tracksPayer)
                <x-field label="Payé par" for="expense-payer">
                    <select id="expense-payer" wire:model="paidBy" class="form-input">
                        <option value="">—</option>
                        @foreach ($users as $user) <option value="{{ $user->id }}">{{ $user->name }}</option> @endforeach
                    </select>
                </x-field>
            @endif

            {{-- Ticket mixte (23.3) --}}
            <div class="rounded-lg bg-stone-50 p-3 ring-1 ring-stone-200">
                <button type="button" wire:click="toggleSplit" class="text-sm font-medium text-brand-700">
                    {{ $split ? 'Ne pas répartir' : 'Répartir entre plusieurs postes (ticket mixte)' }}
                </button>
                @if ($split)
                    <ul class="mt-2 space-y-2">
                        @foreach ($splits as $i => $row)
                            <li wire:key="split-{{ $i }}" class="flex items-center gap-2">
                                <select wire:model="splits.{{ $i }}.category_id" class="form-input min-w-0 flex-1 py-1.5 text-sm" aria-label="Poste">
                                    @foreach ($this->categories as $category) <option value="{{ $category->id }}">{{ $category->name }}</option> @endforeach
                                </select>
                                <input type="text" inputmode="decimal" wire:model="splits.{{ $i }}.amount" placeholder="0,00" class="form-input w-24 py-1.5 text-sm" aria-label="Montant">
                                <button type="button" wire:click="removeSplit({{ $i }})" class="btn btn-ghost px-1.5" title="Retirer"><x-icon name="close" class="size-4" /><span class="sr-only">Retirer</span></button>
                            </li>
                        @endforeach
                    </ul>
                    <button type="button" wire:click="addSplit" class="mt-2 text-xs font-medium text-stone-600 underline">Ajouter un poste</button>
                    @error('splits') <p class="form-error">{{ $message }}</p> @enderror
                @endif
            </div>

            <x-field label="Note" for="expense-note" error="note" optional>
                <input id="expense-note" type="text" wire:model="note" class="form-input">
            </x-field>

            @if ($duplicateWarning)
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">{{ $duplicateWarning }}</p>
            @endif
        </form>

        <x-slot:footer>
            @if ($expenseId)
                <button type="button" wire:click="delete" wire:confirm="Supprimer cette dépense ?" class="btn btn-ghost mr-auto text-red-700">Supprimer</button>
            @endif
            <button type="button" wire:click="close" class="btn btn-secondary">Annuler</button>
            <button type="submit" form="expense-form" class="btn btn-primary">{{ $duplicateWarning ? 'Enregistrer quand même' : 'Enregistrer' }}</button>
        </x-slot:footer>
    </x-modal>
</div>
