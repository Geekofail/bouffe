<div wire:poll.10s.visible class="mx-auto max-w-3xl">
    @if ($shoppingList->stay)
        <a href="{{ route('stays.show', ['stay' => $shoppingList->stay, 'onglet' => 'courses']) }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800 print:hidden">
            <x-icon name="chevron-left" class="size-4" /> Séjour « {{ $shoppingList->stay->name }} »
        </a>
    @else
        <a href="{{ route('shopping.index') }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800 print:hidden">
            <x-icon name="chevron-left" class="size-4" /> Listes de courses
        </a>
    @endif

    {{-- ============================================================ En-tête --}}
    <div class="mb-4 flex flex-wrap items-start gap-3">
        <div class="min-w-0 flex-1">
            <h1 class="page-title">{{ $shoppingList->name }}</h1>
            <p class="mt-1 text-sm text-stone-500 first-letter:uppercase">
                {{ $shoppingList->periodLabel() }}
                @if ($shoppingList->isDone()) · <span class="font-medium text-herb-700">Terminée</span> @endif
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-1 print:hidden" x-data>
            <button type="button" wire:click="regenerate" class="btn btn-secondary" title="{{ $shoppingList->stay_id ? 'Recalculer depuis les repas du séjour' : 'Recalculer depuis le planning' }}">
                <x-icon name="sparkles" class="size-4" wire:loading.class="animate-spin" wire:target="regenerate" />
                <span class="sr-only sm:not-sr-only">Mettre à jour</span>
            </button>
            {{-- Mode magasin (15.4) : page autonome qui fonctionne sans réseau. Pas de wire:navigate, elle est hors Livewire. --}}
            <a href="{{ route('shopping.store', $shoppingList) }}" class="btn btn-primary" title="Grands caractères, fonctionne sans réseau">
                <x-icon name="cart" class="size-4" />
                <span class="sr-only sm:not-sr-only">Mode magasin</span>
            </a>
            <button type="button" wire:click="$set('showText', true)" class="btn btn-ghost px-2.5" title="Copier en texte">
                <x-icon name="duplicate" class="size-4" /><span class="sr-only">Copier en texte</span>
            </button>
            <button type="button" x-on:click="window.print()" class="btn btn-ghost px-2.5" title="Imprimer">
                <x-icon name="printer" class="size-4" /><span class="sr-only">Imprimer</span>
            </button>
            <div x-data="{ open: false }" class="relative">
                <button type="button" x-on:click="open = ! open" class="btn btn-ghost px-2.5" title="Plus d'actions">
                    <x-icon name="dots" class="size-4" /><span class="sr-only">Plus d'actions</span>
                </button>
                <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition.opacity
                     class="absolute right-0 z-20 mt-1 w-56 rounded-xl bg-white p-1 shadow-lg ring-1 ring-stone-200">
                    <button type="button" wire:click="toggleStatus" x-on:click="open = false" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm hover:bg-stone-50">
                        <x-icon name="success" class="size-4" /> {{ $shoppingList->isDone() ? 'Rouvrir la liste' : 'Marquer comme terminée' }}
                    </button>
                    @if ($toPutAway > 0)
                        <a href="{{ route('shopping.put-away', $shoppingList) }}" wire:navigate class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm hover:bg-stone-50">
                            <x-icon name="pantry" class="size-4" /> Ranger dans le stock ({{ $toPutAway }})
                        </a>
                    @endif
                    @if ($hasLinks || $shoppingList->shared_with_links)
                        <button type="button" wire:click="toggleShared" x-on:click="open = false" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm hover:bg-stone-50">
                            <x-icon name="heart" class="size-4" /> {{ $shoppingList->shared_with_links ? 'Refermer aux proches' : 'Ouvrir aux proches (liste groupée)' }}
                        </button>
                    @endif
                    <button type="button" wire:click="uncheckAll" x-on:click="open = false" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm hover:bg-stone-50">
                        <x-icon name="unarchive" class="size-4" /> Tout décocher
                    </button>
                    <button type="button" wire:click="deleteList" wire:confirm="Supprimer définitivement cette liste ?" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50">
                        <x-icon name="delete" class="size-4" /> Supprimer la liste
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Liste groupée (26.8) : qui doit quoi --}}
    @if ($shoppingList->shared_with_links || $balances->isNotEmpty())
        <div class="mb-4 rounded-xl bg-violet-50 p-3 text-sm text-violet-900 ring-1 ring-violet-200 print:hidden">
            <p class="flex items-center gap-2 font-medium"><x-icon name="heart" class="size-4" /> {{ $shoppingList->shared_with_links ? 'Liste groupée : vos proches y ajoutent leurs articles.' : 'Articles pour vos proches' }}</p>
            @if ($balances->isNotEmpty())
                <ul class="mt-1.5 space-y-0.5">
                    @foreach ($balances as $row)
                        <li>{{ $row['household']->name }} : {{ $row['count'] }} article(s), <strong>{{ number_format($row['amount'], 2, ',', ' ') }} €</strong> à rembourser{{ $row['missing'] ? ' — '.$row['missing'].' prix à saisir (bouton « Modifier » de l\'article, champ « Prix payé »)' : '' }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    <div class="sticky top-14 z-10 -mx-4 mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-stone-200 bg-stone-50/95 px-4 py-2 backdrop-blur sm:mx-0 sm:rounded-xl sm:border sm:bg-white print:hidden">
        <x-shopping.progress :checked="$checked" :total="$total" class="min-w-40 flex-1" />

        {{-- Coût estimé (R17) : un minimum tant qu'il manque des prix, et c'est dit. --}}
        @if ($cost->isKnown())
            <span class="flex items-center gap-1.5 text-sm whitespace-nowrap text-stone-600"
                  title="{{ $cost->isComplete() ? 'Coût estimé de la liste' : 'Minimum : certains ingrédients n\'ont pas encore de prix' }}">
                <x-icon name="euro" class="size-4 text-stone-400" />
                <span class="font-medium text-stone-900">{{ $cost->label($prices) }}</span>
                @if ($cost->missingLabel())
                    <span class="hidden text-xs text-stone-500 sm:inline">{{ $cost->missingLabel() }}</span>
                @endif
            </span>
        @endif

        {{-- Magasin : c'est lui qui donne l'ordre des rayons (15.3). --}}
        @if ($this->stores->isNotEmpty())
            <label class="flex items-center gap-1.5 text-sm whitespace-nowrap text-stone-600">
                <x-icon name="store" class="size-4 text-stone-400" /><span class="sr-only">Magasin</span>
                <select wire:change="setStore($event.target.value || null)" class="form-select py-1 text-sm">
                    <option value="" @selected($shoppingList->store_id === null)>Ordre général</option>
                    @foreach ($this->stores as $store)
                        <option value="{{ $store->id }}" @selected($shoppingList->store_id === $store->id)>{{ $store->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <label class="flex items-center gap-2 text-sm whitespace-nowrap text-stone-600">
            <input type="checkbox" wire:model.live="hideChecked" class="form-checkbox"> Masquer les cochés
        </label>
    </div>

    {{-- Aide contextuelle (lot 30, 30.3) --}}
    <x-hint key="shopping">
        Deux magasins ? Touchez un article et choisissez « Seulement dans » un magasin : il passe dans un groupe à part.
        Quand vos prix relevés montrent qu'un article est moins cher ailleurs, Bouffe propose de le déplacer.
    </x-hint>

    {{-- ============================================================ Moins cher ailleurs (lot 27, C1) --}}
    @if ($this->splitSuggestions->isNotEmpty())
        <div class="mb-4 rounded-xl bg-green-50 p-4 text-sm text-green-900 ring-1 ring-green-100 print:hidden">
            <div class="flex items-start gap-2">
                <x-icon name="tag" class="mt-0.5 size-5 shrink-0" />
                <p class="flex-1 font-medium">Moins cher ailleurs, d'après vos derniers prix relevés</p>
                <button type="button" wire:click="$set('hideSplit', true)" class="btn btn-ghost -my-1 -mr-2 px-1.5 text-green-800" title="Masquer">
                    <x-icon name="close" class="size-4" /><span class="sr-only">Masquer</span>
                </button>
            </div>
            <ul class="mt-2 space-y-3">
                @foreach ($this->splitSuggestions as $group)
                    <li wire:key="split-{{ $group['store']->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 pl-7">
                        <div class="min-w-0 flex-1 basis-60">
                            <p>
                                <strong>Chez {{ $group['store']->name }}</strong> :
                                {{ $group['items']->map(fn ($row) => $row['item']->label.' (−'.number_format($row['gap'] * 100, 0, ',', '')."\u{00A0}%)")->join(', ') }}
                            </p>
                            <p class="text-xs text-green-800">
                                @if ($group['saving'] > 0) Environ {{ $prices->money($group['saving']) }} d'économie @else Économie non chiffrable @endif
                                @if ($group['unknown'] > 0) · {{ $group['unknown'] }} article{{ $group['unknown'] > 1 ? 's' : '' }} sans quantité comparable @endif
                            </p>
                        </div>
                        <button type="button" wire:click="splitTo({{ $group['store']->id }})" class="btn btn-secondary py-1.5">Les acheter chez {{ $group['store']->name }}</button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ============================================================ Ranger les courses --}}
    @if ($toPutAway > 0 && ($shoppingList->isDone() || ($total > 0 && $checked === $total)))
        <div class="mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-herb-50 px-4 py-3 text-sm text-herb-900 ring-1 ring-herb-100 print:hidden">
            <x-icon name="pantry" class="size-5 shrink-0" />
            <p class="flex-1">De retour des courses ? <strong>{{ $toPutAway }} article{{ $toPutAway > 1 ? 's' : '' }}</strong> à ranger dans le stock.</p>
            <a href="{{ route('shopping.put-away', $shoppingList) }}" wire:navigate class="btn btn-primary py-1.5">Ranger</a>
        </div>
    @endif

    {{-- ============================================================ Différences après mise à jour --}}
    @if ($showChanges)
        <div class="mb-4 rounded-xl bg-sky-50 p-4 text-sm text-sky-900 ring-1 ring-sky-100 print:hidden">
            <div class="flex items-start gap-2">
                <x-icon name="info" class="size-5 shrink-0" />
                <div class="flex-1">
                    @if ($changes === [])
                        <p>La liste est à jour avec le planning.</p>
                    @else
                        <p class="mb-1 font-medium">Changements depuis le planning :</p>
                        <ul class="list-inside list-disc space-y-0.5">
                            @foreach ($changes as $change) <li>{{ $change }}</li> @endforeach
                        </ul>
                    @endif
                </div>
                <button type="button" wire:click="$set('showChanges', false)" class="text-sky-700" title="Fermer"><x-icon name="close" class="size-4" /></button>
            </div>
        </div>
    @endif

    {{-- ============================================================ Ajout rapide --}}
    <form wire:submit="addItem" class="mb-6 flex gap-2 print:hidden">
        <datalist id="shopping-names">
            @foreach ($this->ingredientNames as $name) <option value="{{ $name }}"></option> @endforeach
        </datalist>
        <input type="text" wire:model="newItem" list="shopping-names" placeholder="Ajouter un article (lessive, 2 baguettes…)"
               @class(['form-input', 'form-input-error' => $errors->has('newItem')]) aria-label="Ajouter un article" autocomplete="off">
        <button type="submit" class="btn btn-primary shrink-0"><x-icon name="plus" class="size-4" /><span class="sr-only sm:not-sr-only">Ajouter</span></button>
    </form>
    @error('newItem') <p class="form-error -mt-4 mb-4">{{ $message }}</p> @enderror

    {{-- Articles fréquents (15.6) : ce qu'on achète souvent et qui manque ici. Rien n'est ajouté tout seul. --}}
    @if ($this->frequent->isNotEmpty())
        <div class="-mt-3 mb-6 flex flex-wrap items-center gap-2 print:hidden">
            <span class="text-xs text-stone-500">Souvent acheté :</span>
            @foreach ($this->frequent as $suggestion)
                <button type="button" wire:key="frequent-{{ $suggestion['ingredient']->id }}" wire:click="addFrequent({{ $suggestion['ingredient']->id }})"
                        class="flex items-center gap-1 rounded-full bg-white px-2.5 py-1 text-xs font-medium text-stone-700 ring-1 ring-stone-200 transition hover:ring-brand-300"
                        title="Acheté {{ $suggestion['count'] }} fois ces deux derniers mois">
                    <x-icon name="plus" class="size-3" /> {{ $suggestion['ingredient']->name }}
                    <span class="text-stone-500 tabular-nums">{{ $suggestion['count'] }}×</span>
                </button>
            @endforeach
        </div>
    @endif

    {{-- ============================================================ Rayons --}}
    @if ($total === 0)
        <div class="card">
            <x-empty-state icon="cart" title="La liste est vide">
                Aucune recette sur la période. Planifiez des repas puis cliquez sur « Mettre à jour », ou ajoutez des articles à la main.
            </x-empty-state>
        </div>
    @endif

    <div class="space-y-4 print:space-y-2">
        @foreach ($grouped['aisles'] as $group)
            @php
                $items = $hideChecked ? $group['items']->where('is_checked', false) : $group['items'];
                $remaining = $group['items']->where('is_checked', false)->count();
            @endphp
            @continue($items->isEmpty())

            <section wire:key="aisle-{{ $group['aisle']?->id ?? 0 }}" class="card overflow-hidden print:break-inside-avoid print:rounded-none print:shadow-none print:ring-0">
                <h2 class="flex items-center gap-2 border-b border-stone-100 px-4 py-2 text-sm font-semibold text-stone-700 print:border-stone-400 print:px-0">
                    <span class="size-2.5 rounded-full print:hidden {{ \App\Support\Palette::dot($group['aisle']?->color) }}"></span>
                    {{ $group['aisle']?->name ?? 'Autres' }}
                    <span class="ml-auto text-xs font-normal text-stone-500 print:hidden">{{ $remaining === 0 ? 'Terminé ✓' : $remaining.' restant'.($remaining > 1 ? 's' : '') }}</span>
                </h2>
                <ul class="divide-y divide-stone-50 px-2 py-1 print:px-0">
                    @foreach ($items as $item)
                        <x-shopping.item :item="$item" :presenter="$presenter" />
                    @endforeach
                </ul>
            </section>
        @endforeach

        {{-- Ailleurs (15.3) : réservé à un autre magasin, ou rayon absent d'ici. Montré, pas caché.
             Lot 27 : un groupe par magasin (« Chez Lidl »), avec de quoi tout reprendre ici. --}}
        @php
            $elsewhere = $hideChecked ? $grouped['elsewhere']->where('is_checked', false) : $grouped['elsewhere'];
            $elsewhereGroups = $elsewhere->groupBy(fn ($i) => $i->store_id && $i->store_id !== $shoppingList->store_id ? $i->store_id : 0)
                ->sortKeys()->reverse();
            $storeNames = $this->stores->pluck('name', 'id');
        @endphp
        @foreach ($elsewhereGroups as $storeId => $elsewhereItems)
            <section wire:key="elsewhere-{{ $storeId }}" class="card overflow-hidden bg-stone-50/60 print:break-inside-avoid">
                <h2 class="flex flex-wrap items-center gap-2 border-b border-stone-100 px-4 py-2 text-sm font-semibold text-stone-700 print:px-0">
                    <x-icon name="store" class="size-4" />
                    @if ($storeId && $storeNames->has($storeId))
                        Chez {{ $storeNames[$storeId] }}
                        @if (auth()->user()->canEdit() && $elsewhereItems->where('is_checked', false)->isNotEmpty())
                            <button type="button" wire:click="bringBack({{ $storeId }})" class="ml-auto text-xs font-medium text-brand-700 hover:underline print:hidden">Tout reprendre ici</button>
                        @endif
                    @else
                        Ailleurs
                        <span class="ml-auto text-xs font-normal text-stone-500 print:hidden">pas dans ce magasin</span>
                    @endif
                </h2>
                <ul class="divide-y divide-stone-50 px-2 py-1 print:px-0">
                    @foreach ($elsewhereItems as $item)
                        <x-shopping.item :item="$item" :presenter="$presenter" />
                    @endforeach
                </ul>
            </section>
        @endforeach

        {{-- Produits de base --}}
        @php $staples = $hideChecked ? $grouped['staples']->where('is_checked', false) : $grouped['staples']; @endphp
        @if ($staples->isNotEmpty())
            <section class="card overflow-hidden bg-amber-50/40 ring-amber-200 print:break-inside-avoid print:bg-white print:shadow-none print:ring-0">
                <h2 class="flex items-center gap-2 border-b border-amber-100 px-4 py-2 text-sm font-semibold text-amber-900 print:px-0">
                    <x-icon name="pantry" class="size-4" /> À vérifier dans le placard
                    <span class="ml-auto text-xs font-normal text-amber-700 print:hidden">cochez ce que vous avez déjà</span>
                </h2>
                <ul class="divide-y divide-amber-50 px-2 py-1 print:px-0">
                    @foreach ($staples as $item)
                        <x-shopping.item :item="$item" :presenter="$presenter" />
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Déjà en stock (R8) --}}
        @if ($grouped['covered']->isNotEmpty())
            <details class="rounded-xl bg-herb-50 px-4 py-2 text-sm ring-1 ring-herb-100 print:hidden" @if ($total === 0) open @endif>
                <summary class="cursor-pointer font-medium text-herb-800">Déjà en stock ({{ $grouped['covered']->count() }})</summary>
                <ul class="mt-2 divide-y divide-herb-100">
                    @foreach ($grouped['covered'] as $item)
                        <li wire:key="covered-{{ $item->id }}" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-1.5">
                            <span class="min-w-0">
                                <span class="font-medium text-stone-800">{{ $item->label }}</span>
                                @if ($item->stock_note) <span class="block text-xs text-stone-500">{{ $item->stock_note }}</span> @endif
                            </span>
                            <button type="button" wire:click="buyAnyway({{ $item->id }})" class="text-xs font-medium whitespace-nowrap text-brand-700 hover:underline">Acheter quand même</button>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif

        {{-- Retirés --}}
        @if ($grouped['removed']->isNotEmpty())
            <details class="rounded-xl bg-stone-100/70 px-4 py-2 text-sm print:hidden">
                <summary class="cursor-pointer font-medium text-stone-600">Articles retirés ({{ $grouped['removed']->count() }})</summary>
                <ul class="mt-2 divide-y divide-stone-200">
                    @foreach ($grouped['removed'] as $item)
                        <li wire:key="removed-{{ $item->id }}" class="flex items-center justify-between gap-3 py-1.5">
                            <span class="text-stone-500 line-through">{{ $presenter->text($item) }}</span>
                            <button type="button" wire:click="restore({{ $item->id }})" class="text-brand-700 hover:underline">Remettre</button>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>

    {{-- ============================================================ Détail d'un article --}}
    @php $editing = $this->editingItem; @endphp
    <x-modal :show="(bool) $editing" :title="$editing ? $presenter->text($editing) : ''" close="closeEdit">
        @if ($editing)
            <form id="item-form" wire:submit="saveEdit" class="space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <x-field label="Quantité" for="item-qty" error="editQuantity" :help="$editing->origin->isFromPlanning() ? 'Vide = quantité calculée' : null">
                        <input id="item-qty" type="text" inputmode="decimal" wire:model="editQuantity" class="form-input">
                    </x-field>
                    <x-field label="Unité" for="item-unit" error="editUnitId">
                        <select id="item-unit" wire:model="editUnitId" class="form-input">
                            <option value="">—</option>
                            @foreach ($this->units as $unit) <option value="{{ $unit->id }}">{{ $unit->label }}</option> @endforeach
                        </select>
                    </x-field>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Rayon" for="item-aisle" error="editAisleId">
                        <select id="item-aisle" wire:model="editAisleId" class="form-input">
                            <option value="">Autres</option>
                            @foreach ($this->aisles as $aisle) <option value="{{ $aisle->id }}">{{ $aisle->name }}</option> @endforeach
                        </select>
                    </x-field>

                    {{-- « Seulement au marché » (15.3) : l'article n'est à prendre que dans ce magasin. --}}
                    @if ($this->stores->isNotEmpty())
                        <x-field label="Seulement dans" for="item-store" error="editStoreId" optional>
                            <select id="item-store" wire:model="editStoreId" class="form-input">
                                <option value="">N'importe quel magasin</option>
                                @foreach ($this->stores as $store) <option value="{{ $store->id }}">{{ $store->name }}</option> @endforeach
                            </select>
                        </x-field>
                    @endif
                </div>

                {{-- Prix payé (15.7) : facultatif, mais il nourrit le prix de référence et le budget (R17). --}}
                <x-field label="Prix payé (€)" for="item-price" error="editPrice" optional
                         help="Sert au budget du mois et au coût estimé des recettes qui utilisent cet ingrédient.">
                    <input id="item-price" type="text" inputmode="decimal" wire:model="editPrice" placeholder="ex. 2,49"
                           @class(['form-input', 'form-input-error' => $errors->has('editPrice')])>
                </x-field>

                @if ($editing->ingredient?->reference_price)
                    <p class="text-xs text-stone-500">
                        Prix de référence actuel : <strong>{{ app(\App\Services\Pricing\PriceBook::class)->referenceLabel($editing->ingredient) }}</strong>
                        @if ($editing->ingredient->reference_price_on) (relevé le {{ $editing->ingredient->reference_price_on->locale('fr')->isoFormat('D MMM YYYY') }}) @endif
                    </p>
                @endif

                @if ($editing->sources->isNotEmpty())
                    <div>
                        <p class="form-label">Provenance</p>
                        <ul class="divide-y divide-stone-100 rounded-lg bg-stone-50 px-3 text-sm">
                            @foreach ($editing->sources as $source)
                                <li class="flex justify-between gap-3 py-1.5">
                                    <span>
                                        <span class="font-medium text-stone-800">{{ $source->recipe_title }}</span>
                                        <span class="text-stone-500">· {{ $source->meal_date->locale('fr')->isoFormat('ddd D') }}{{ $source->slot_name ? ' '.mb_strtolower($source->slot_name) : '' }}{{ $source->servings ? ', '.\App\Services\Planning\Appetites::label($source->servings) : '' }}</span>
                                        @if ($source->is_optional) <span class="text-xs text-stone-500">(facultatif)</span> @endif
                                    </span>
                                    <span class="whitespace-nowrap text-stone-600">{{ $presenter->sourceQuantity($source->quantity === null ? null : (float) $source->quantity, $source->unit_id) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($editing->stock_note)
                    <p class="rounded-lg bg-herb-50 px-3 py-2 text-sm text-herb-900">{{ $editing->stock_note }}</p>
                @endif
                @if ($editing->buy_anyway)
                    <p class="text-sm text-stone-600">Acheté quand même, sans tenir compte du stock.
                        <button type="button" wire:click="buyAnyway({{ $editing->id }}, false)" class="font-medium text-brand-700 hover:underline">Déduire le stock</button>
                    </p>
                @endif

                @if ($editing->is_checked && $editing->checker)
                    <p class="text-xs text-stone-500">Coché par {{ $editing->checker->name }} {{ $editing->checked_at?->locale('fr')->diffForHumans() }}.</p>
                @endif
            </form>

            <x-slot:footer>
                @if ($editing->origin->isFromPlanning())
                    <button type="button" wire:click="remove({{ $editing->id }})" class="btn btn-ghost mr-auto text-red-600 hover:bg-red-50" title="J'en ai déjà">
                        <x-icon name="minus" class="size-4" /> Retirer
                    </button>
                @else
                    <button type="button" wire:click="deleteItem({{ $editing->id }})" class="btn btn-ghost mr-auto text-red-600 hover:bg-red-50">
                        <x-icon name="delete" class="size-4" /> Supprimer
                    </button>
                @endif
                <button type="button" wire:click="closeEdit" class="btn btn-secondary">Fermer</button>
                <button type="submit" form="item-form" class="btn btn-primary">Enregistrer</button>
            </x-slot:footer>
        @endif
    </x-modal>

    {{-- ============================================================ Copie texte --}}
    <x-modal :show="$showText" title="Copier la liste" close="closeText">
        <div x-data="{ copied: false,
                       copy() {
                           const area = this.$refs.text;
                           const done = () => { this.copied = true; setTimeout(() => this.copied = false, 2500); };
                           const fallback = () => {
                               area.focus(); area.select();
                               try { if (document.execCommand('copy')) done(); } catch (e) {}
                           };
                           if (navigator.clipboard && window.isSecureContext) {
                               navigator.clipboard.writeText(area.value).then(done).catch(fallback);
                           } else {
                               fallback();
                           }
                       } }" class="space-y-3">
            <p class="text-sm text-stone-600">Les articles déjà cochés ne sont pas inclus. Collez le texte dans un message ou une note.</p>
            <textarea x-ref="text" readonly rows="14" class="form-input font-mono text-xs">{{ $showText ? $this->text : '' }}</textarea>
            <button type="button" x-on:click="copy()" class="btn btn-primary w-full">
                <span x-show="! copied"><x-icon name="duplicate" class="inline size-4" /> Copier dans le presse-papiers</span>
                <span x-show="copied" x-cloak>✓ Copié !</span>
            </button>
        </div>
    </x-modal>
</div>
