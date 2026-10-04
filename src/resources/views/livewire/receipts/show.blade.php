@php
    $confidenceLabels = [
        'learned' => ['Appris', 'bg-herb-50 text-herb-800 ring-herb-200', 'check'],
        'name' => ['Reconnu', 'bg-sky-50 text-sky-800 ring-sky-200', 'check'],
        'product' => ['Reconnu', 'bg-sky-50 text-sky-800 ring-sky-200', 'check'],
        'manual' => ['Choisi', 'bg-stone-100 text-stone-700 ring-stone-200', 'check'],
        'suggested' => ['À vérifier', 'bg-amber-50 text-amber-800 ring-amber-200', 'warning'],
        'none' => ['Non reconnu', 'bg-red-50 text-red-800 ring-red-200', 'warning'],
    ];
    $editable = auth()->user()->canEdit() && ! $receipt->isValidated();
    $consistency = $this->consistency;
@endphp

<div>
    <x-page-header :title="$receipt->storeLabel()"
                   :subtitle="($receipt->purchased_on ? ucfirst($receipt->purchased_on->locale('fr')->isoFormat('dddd D MMMM YYYY')) : 'Date à préciser').' · '.match ($receipt->status) { 'validated' => 'validé', 'review' => 'à relire', default => 'pas encore lu' }">
        <x-slot:actions>
            <a href="{{ route('receipts.index') }}" wire:navigate class="btn btn-secondary"><x-icon name="receipt" class="size-4" /> Tickets</a>
        </x-slot:actions>
    </x-page-header>

    @if (session('receipt-error'))
        <div class="mb-4 flex gap-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200" role="alert">
            <x-icon name="warning" class="size-5 shrink-0" />
            <p>{{ session('receipt-error') }}</p>
        </div>
    @endif

    {{-- ================================================================ Pas encore lu --}}
    @if ($receipt->status === 'draft')
        <section class="card mx-auto max-w-xl space-y-3 p-5">
            <h2 class="font-display font-semibold text-stone-900">Ce ticket n'a pas encore été lu</h2>
            @if ($receipt->error) <p class="text-sm text-stone-600">Dernière tentative : {{ $receipt->error }}</p> @endif
            <div class="flex flex-wrap gap-2">
                @if ($status['available'] && $receipt->hasPhotos() && $editable)
                    <button type="button" wire:click="retry" wire:loading.attr="disabled" class="btn btn-primary">
                        <span wire:loading.remove wire:target="retry"><x-icon name="sparkles" class="inline size-4" /> Lire le ticket</span>
                        <span wire:loading wire:target="retry">Lecture en cours…</span>
                    </button>
                @endif
                @if ($editable)
                    <button type="button" wire:click="manual" class="btn btn-secondary"><x-icon name="edit" class="size-4" /> Saisir à la main</button>
                    <button type="button" wire:click="discard" wire:confirm="Supprimer ce ticket et ses photos ?" class="btn btn-ghost text-red-700">Supprimer</button>
                @endif
            </div>
            @unless ($status['available']) <p class="text-sm text-stone-500">{{ $status['reason'] }}</p> @endunless
        </section>
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            {{-- ============================================================ En-tête du ticket --}}
            <aside class="min-w-0 space-y-4 lg:order-last">
                @if ($editable)
                    <section class="card space-y-3 p-4">
                        <x-field label="Magasin" for="r-store">
                            <select id="r-store" wire:model.live="storeId" class="form-input">
                                <option value="">Magasin inconnu</option>
                                @foreach ($this->stores as $store) <option value="{{ $store->id }}">{{ $store->name }}</option> @endforeach
                                <option value="new">Nouveau magasin…</option>
                            </select>
                        </x-field>
                        @if ($storeId === 'new')
                            <x-field label="Nom du nouveau magasin" for="r-new-store" error="newStore">
                                <input id="r-new-store" type="text" wire:model.blur="newStore" maxlength="100" class="form-input" placeholder="ex. Cactus Bereldange">
                            </x-field>
                        @endif
                        <div class="grid grid-cols-2 gap-3">
                            <x-field label="Date" for="r-date" error="purchasedOn">
                                <input id="r-date" type="date" wire:model.blur="purchasedOn" max="{{ today()->toDateString() }}" class="form-input">
                            </x-field>
                            <x-field label="Total payé (€)" for="r-total">
                                <input id="r-total" type="text" inputmode="decimal" wire:model.blur="total" class="form-input text-right tabular-nums" placeholder="0,00">
                            </x-field>
                        </div>
                        <x-field label="Liste de courses" for="r-list" help="Ses articles achetés seront cochés ; ses prix cochés ne comptent plus dans le budget (le ticket fait foi).">
                            <select id="r-list" wire:model.live="listId" class="form-input">
                                <option value="">Aucune</option>
                                @foreach ($this->lists as $list) <option value="{{ $list->id }}">{{ $list->name }}{{ $list->status->value === 'done' ? ' (terminée)' : '' }}</option> @endforeach
                            </select>
                        </x-field>
                    </section>
                @endif

                {{-- Cohérence (R27) --}}
                @if (! $receipt->isValidated())
                    <section @class(['card p-4', 'ring-2 ring-amber-300' => ! $consistency['ok']])>
                        <dl class="space-y-1 text-sm">
                            <div class="flex justify-between"><dt class="text-stone-600">Somme des lignes</dt><dd class="font-medium tabular-nums">{{ $tracker->money($consistency['sum']) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-stone-600">Total du ticket</dt><dd class="font-medium tabular-nums">{{ $consistency['total'] !== null ? $tracker->money($consistency['total']) : '—' }}</dd></div>
                        </dl>
                        @if ($consistency['ok'])
                            <p class="mt-2 flex items-center gap-1.5 text-sm font-medium text-herb-700"><x-icon name="success" class="size-4" /> Le compte est bon.</p>
                        @elseif ($consistency['total'] === null)
                            <p class="mt-2 flex items-center gap-1.5 text-sm font-medium text-amber-800"><x-icon name="warning" class="size-4" /> Indiquez le total payé.</p>
                        @else
                            <p class="mt-2 flex gap-1.5 text-sm text-amber-900"><x-icon name="warning" class="size-4 shrink-0" />
                                Écart de {{ $tracker->money(abs($consistency['gap'])) }} : le ticket semble incomplet ou mal lu. Vérifiez les lignes signalées.</p>
                            @if ($editable && $consistency['sum'] > 0)
                                <button type="button" wire:click="useSum" class="mt-1 text-sm font-medium text-brand-700 underline">Prendre la somme des lignes comme total</button>
                            @endif
                        @endif
                    </section>
                @endif

                @if ($existing && $editable)
                    <label class="card flex items-start gap-3 p-4 text-sm">
                        <input type="checkbox" wire:model="replaceExpense" class="mt-0.5 size-4 rounded border-stone-300 text-brand-600">
                        <span>Une dépense identique a été saisie à la main ({{ $existing->placeLabel() }}, {{ $tracker->money((float) $existing->amount) }}) : <strong>la remplacer</strong> par ce ticket, pour ne pas la compter deux fois.</span>
                    </label>
                @endif

                @if ($editable)
                    <section class="card space-y-2 p-4">
                        @error('validate') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                        @if ($gapWarning)
                            <p class="text-sm text-amber-900">Le total ne correspond pas aux lignes. Valider quand même ?</p>
                        @endif
                        <button type="button" wire:click="validateReceipt" wire:loading.attr="disabled" class="btn btn-primary w-full py-2.5">
                            <x-icon name="check" class="size-5" /> {{ $gapWarning ? 'Valider quand même' : 'Valider le ticket' }}
                        </button>
                        <p class="text-xs text-stone-500">Crée la dépense, relève les prix, range au stock les articles cochés « stock » et coche la liste.</p>
                        <button type="button" wire:click="discard" wire:confirm="Supprimer ce ticket et ses photos ?" class="w-full text-center text-sm text-stone-500 underline hover:text-red-700">Supprimer ce ticket</button>
                    </section>
                @endif

                {{-- Compte rendu (24.4, 24.6) --}}
                @if ($receipt->isValidated())
                    @php $outcome = (array) $receipt->outcome; @endphp
                    <section class="card space-y-2 p-4 text-sm">
                        <h2 class="font-display font-semibold text-stone-900">Ce que le ticket a rempli</h2>
                        <ul class="space-y-1 text-stone-700">
                            <li class="flex items-center gap-2"><x-icon name="euro" class="size-4 text-stone-500" />
                                Dépense de {{ $tracker->money((float) $receipt->total) }}
                                @if ($receipt->expense)
                                    — <a href="{{ route('budget.index', ['periode' => $receipt->expense->spent_on->toDateString()]) }}" wire:navigate class="text-brand-700 underline">budget</a>
                                @endif
                                @if ($outcome['replaced_expense'] ?? false) <span class="text-stone-500">(remplace la saisie manuelle)</span> @endif
                            </li>
                            <li class="flex items-center gap-2"><x-icon name="tag" class="size-4 text-stone-500" /> {{ $outcome['prices'] ?? 0 }} prix relevé{{ ($outcome['prices'] ?? 0) > 1 ? 's' : '' }}</li>
                            <li class="flex items-center gap-2"><x-icon name="pantry" class="size-4 text-stone-500" /> {{ $outcome['stocked'] ?? 0 }} article{{ ($outcome['stocked'] ?? 0) > 1 ? 's' : '' }} au stock</li>
                            @if ($receipt->shoppingList)
                                <li class="flex items-center gap-2"><x-icon name="cart" class="size-4 text-stone-500" /> {{ $outcome['checked'] ?? 0 }} coché{{ ($outcome['checked'] ?? 0) > 1 ? 's' : '' }} sur
                                    <a href="{{ route('shopping.show', $receipt->shoppingList) }}" wire:navigate class="text-brand-700 underline">{{ $receipt->shoppingList->name }}</a></li>
                            @endif
                        </ul>
                    </section>

                    @if (! empty($outcome['off_list']))
                        <section class="card p-4 text-sm">
                            <h2 class="font-display font-semibold text-stone-900">{{ count($outcome['off_list']) }} acheté{{ count($outcome['off_list']) > 1 ? 's' : '' }} hors liste</h2>
                            <p class="mt-1 text-stone-600">{{ implode(', ', array_slice($outcome['off_list'], 0, 12)) }}{{ count($outcome['off_list']) > 12 ? '…' : '' }}</p>
                        </section>
                    @endif

                    @if ($notBought->isNotEmpty())
                        <section class="card p-4 text-sm ring-2 ring-amber-200">
                            <h2 class="font-display font-semibold text-stone-900">{{ $notBought->count() }} article{{ $notBought->count() > 1 ? 's' : '' }} de la liste non acheté{{ $notBought->count() > 1 ? 's' : '' }}</h2>
                            <p class="mt-1 text-stone-600">{{ $notBought->pluck('label')->join(', ') }}</p>
                            @if (auth()->user()->canEdit())
                                <p class="mt-2 font-medium text-stone-800">Les garder pour la prochaine fois ?</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <button type="button" wire:click="resolveNotBought(true)" class="btn btn-primary">Oui, les garder</button>
                                    <button type="button" wire:click="resolveNotBought(false)" class="btn btn-secondary">Non, les retirer</button>
                                </div>
                                <p class="mt-2 text-xs text-stone-500">Gardés : ils passent dans « Quand je passe » et rejoindront la prochaine liste.</p>
                            @endif
                        </section>
                    @endif
                @endif

                {{-- Photos (24.8) --}}
                @if ($receipt->hasPhotos())
                    <section class="card p-4 text-sm">
                        <h2 class="font-display mb-2 font-semibold text-stone-900">Photos</h2>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($receipt->photo_paths as $i => $path)
                                <a href="{{ route('receipts.photo', [$receipt, $i]) }}" target="_blank" class="btn btn-ghost px-2 text-sm">
                                    <x-icon :name="str_ends_with($path, '.pdf') ? 'download' : 'photo'" class="size-4" /> {{ str_ends_with($path, '.pdf') ? 'PDF' : 'Photo '.($i + 1) }}
                                </a>
                            @endforeach
                        </div>
                        <p class="mt-2 text-xs text-stone-500">Supprimées automatiquement après {{ \App\Support\Settings::int('receipts.keep_months', 12) }} mois ; les lignes et montants restent.</p>
                    </section>
                @elseif ($receipt->photos_deleted_at)
                    <p class="px-1 text-xs text-stone-500">Photos supprimées le {{ $receipt->photos_deleted_at->locale('fr')->isoFormat('D MMMM YYYY') }} (durée de conservation).</p>
                @endif
            </aside>

            {{-- ============================================================ Lignes (24.3) --}}
            <div class="min-w-0 space-y-3 lg:col-span-2">
                <datalist id="receipt-ingredients">
                    @foreach ($this->ingredientNames as $name) <option value="{{ $name }}"></option> @endforeach
                </datalist>

                @if ($lines === [] && $editable)
                    <div class="card p-5 text-sm text-stone-600">
                        Aucune ligne. Le total suffit pour la dépense ; ajoutez les articles si vous voulez aussi relever les prix et remplir le stock.
                    </div>
                @endif

                @foreach ($lines as $i => $line)
                    @php
                        $purchase = in_array($line['kind'], ['article', 'non_food'], true);
                        [$cLabel, $cClass, $cIcon] = $confidenceLabels[$line['confidence']] ?? $confidenceLabels['none'];
                        $model = $receipt->isValidated() ? $receipt->lines->firstWhere('id', $line['id']) : null;
                    @endphp
                    <article wire:key="line-{{ $line['id'] }}" @class(['card p-3 sm:p-4', 'ring-2 ring-amber-300' => $line['doubtful'] && ! $receipt->isValidated(), 'opacity-60' => $line['kind'] === 'ignored'])>
                        <div class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                @if ($editable)
                                    <label class="sr-only" for="l-label-{{ $i }}">Libellé</label>
                                    <input id="l-label-{{ $i }}" type="text" wire:model.blur="lines.{{ $i }}.label" class="w-full rounded border-0 bg-transparent p-0 font-mono text-sm font-medium text-stone-900 focus:ring-2 focus:ring-brand-300">
                                @else
                                    <p class="font-mono text-sm font-medium text-stone-900">{{ $line['label'] }}</p>
                                @endif
                                @if ($line['suggested'] !== '')
                                    <p class="truncate text-xs text-stone-500">Lu : {{ $line['suggested'] }}</p>
                                @endif
                                @if ($line['doubtful'] && ! $receipt->isValidated())
                                    <p class="mt-0.5 flex items-center gap-1 text-xs font-medium text-amber-800"><x-icon name="warning" class="size-3.5" /> Montant à vérifier</p>
                                @endif
                            </div>
                            <div class="w-24 shrink-0 text-right">
                                @if ($editable)
                                    <label class="sr-only" for="l-amount-{{ $i }}">Montant</label>
                                    <input id="l-amount-{{ $i }}" type="text" inputmode="decimal" wire:model.blur="lines.{{ $i }}.amount" class="form-input py-1 text-right text-sm font-semibold tabular-nums">
                                @else
                                    <p class="font-semibold text-stone-900 tabular-nums">{{ $tracker->money((float) str_replace(',', '.', $line['amount'])) }}</p>
                                @endif
                                @if ($line['discount'] !== '')
                                    <p class="text-xs text-herb-700 tabular-nums">remise {{ $line['discount'] }} €</p>
                                @endif
                            </div>
                        </div>

                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            @if ($editable)
                                <select wire:model.live="lines.{{ $i }}.kind" class="form-input w-auto py-1 text-sm" aria-label="Genre de ligne">
                                    @foreach (\App\Models\ReceiptLine::KINDS as $key => $label) <option value="{{ $key }}">{{ $label }}</option> @endforeach
                                </select>
                            @else
                                <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-700">{{ \App\Models\ReceiptLine::KINDS[$line['kind']] ?? $line['kind'] }}</span>
                            @endif

                            @if ($line['kind'] === 'article')
                                @if ($editable)
                                    <input type="text" list="receipt-ingredients" wire:model.blur="lines.{{ $i }}.ingredient" placeholder="Ingrédient…" aria-label="Ingrédient"
                                           @class(['form-input min-w-0 flex-1 basis-40 py-1 text-sm', 'form-input-error' => $errors->has('lines.'.$i.'.ingredient')])>
                                @elseif ($line['ingredient'] !== '')
                                    <span class="font-medium text-stone-800">{{ $line['ingredient'] }}</span>
                                @endif
                                @unless ($receipt->isValidated())
                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 {{ $cClass }}"><x-icon :name="$cIcon" class="size-3" /> {{ $cLabel }}</span>
                                @endunless
                            @endif

                            @if ($editable && $purchase)
                                <select wire:model.live="lines.{{ $i }}.category_id" class="form-input w-auto py-1 text-sm" aria-label="Poste de budget">
                                    @foreach ($this->categories as $category) <option value="{{ $category->id }}">{{ $category->name }}</option> @endforeach
                                </select>
                            @endif
                        </div>
                        @error('lines.'.$i.'.ingredient') <p class="mt-1 text-xs text-red-700">{{ $message }}</p> @enderror

                        {{-- Quantité et stock --}}
                        @if ($line['kind'] === 'article' && $line['ingredient_id'])
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-stone-600">
                                @if ($editable)
                                    @if ($line['weight'] !== '')
                                        <label class="flex items-center gap-1">Poids <input type="text" inputmode="decimal" wire:model.blur="lines.{{ $i }}.weight" class="form-input w-20 py-1 text-right text-sm"> g</label>
                                    @else
                                        <label class="flex items-center gap-1"><input type="text" inputmode="decimal" wire:model.blur="lines.{{ $i }}.count" class="form-input w-14 py-1 text-right text-sm" placeholder="1" aria-label="Nombre"> ×</label>
                                        <label class="flex items-center gap-1">
                                            <input type="text" inputmode="decimal" wire:model.blur="lines.{{ $i }}.pack_quantity" class="form-input w-16 py-1 text-right text-sm" placeholder="?" aria-label="Contenu d'un paquet">
                                            <select wire:model.live="lines.{{ $i }}.pack_unit_id" class="form-input w-auto py-1 text-sm" aria-label="Unité du contenu">
                                                <option value="">—</option>
                                                @foreach ($this->units as $unit) <option value="{{ $unit->id }}">{{ $unit->code === 'piece' ? 'pièce(s)' : $unit->code }}</option> @endforeach
                                            </select>
                                        </label>
                                    @endif
                                    <label class="ml-auto flex items-center gap-2 font-medium text-stone-700">
                                        <input type="checkbox" wire:model.live="lines.{{ $i }}.to_stock" class="size-4 rounded border-stone-300 text-brand-600"> Au stock
                                    </label>
                                @elseif ($model)
                                    <span class="flex flex-wrap gap-1.5 text-xs">
                                        @if ($model->stock_item_id) <span class="rounded-full bg-herb-50 px-2 py-0.5 font-medium text-herb-800 ring-1 ring-herb-200">Au stock</span> @endif
                                        @if ($model->ingredient_price_id) <span class="rounded-full bg-sky-50 px-2 py-0.5 font-medium text-sky-800 ring-1 ring-sky-200">Prix relevé</span> @endif
                                        @if ($model->shopping_list_item_id) <span class="rounded-full bg-stone-100 px-2 py-0.5 font-medium text-stone-700 ring-1 ring-stone-200">Coché sur la liste</span> @endif
                                    </span>
                                @endif
                            </div>
                        @endif

                        @if ($editable)
                            <div class="mt-2 text-right">
                                <button type="button" wire:click="removeLine({{ $i }})" class="text-xs text-stone-500 underline hover:text-red-700">Retirer la ligne</button>
                            </div>
                        @endif
                    </article>
                @endforeach

                @if ($editable)
                    <button type="button" wire:click="addLine" class="btn btn-secondary w-full"><x-icon name="plus" class="size-4" /> Ajouter une ligne</button>
                    <div class="space-y-1 lg:hidden">
                        @error('validate') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                        @if ($gapWarning) <p class="text-sm text-amber-900">Le total ne correspond pas aux lignes. Valider quand même ?</p> @endif
                    </div>
                    <button type="button" wire:click="validateReceipt" class="btn btn-primary w-full py-2.5 lg:hidden">
                        <x-icon name="check" class="size-5" /> {{ $gapWarning ? 'Valider quand même' : 'Valider le ticket' }}
                    </button>
                @endif
            </div>
        </div>
    @endif
</div>
