<div>
    <x-page-header title="Paramètres" subtitle="Les listes de référence utilisées par les recettes et la liste de courses." />
    <x-settings-nav />

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <div class="relative min-w-56 flex-1">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Rechercher un ingrédient…"
                   class="form-input pl-10" aria-label="Rechercher un ingrédient">
        </div>

        <select wire:model.live="aisleFilter" class="form-input w-auto" aria-label="Filtrer par rayon">
            <option value="">Tous les rayons</option>
            @foreach ($this->aisles as $aisle)
                <option value="{{ $aisle->id }}">{{ $aisle->name }}</option>
            @endforeach
        </select>

        <label class="flex items-center gap-2 text-sm text-stone-600">
            <input type="checkbox" wire:model.live="staplesOnly" class="form-checkbox">
            Produits de base
        </label>

        <a href="{{ route('settings.ingredient-duplicates') }}" wire:navigate class="btn btn-secondary ml-auto">
            <x-icon name="merge" class="size-4" /> Doublons
        </a>
        <button type="button" wire:click="create" class="btn btn-primary">
            <x-icon name="plus" class="size-4" /> Ajouter
        </button>
    </div>

    <div class="card overflow-hidden">
        @if ($ingredients->isEmpty())
            @if ($search !== '' || $aisleFilter || $staplesOnly)
                <x-empty-state icon="search" title="Aucun ingrédient ne correspond">
                    <button type="button" wire:click="clearFilters" class="text-brand-700 underline">Effacer les filtres</button>
                    @if ($search !== '')
                        ou <button type="button" wire:click="create" class="text-brand-700 underline">créer « {{ $search }} »</button>
                    @endif
                </x-empty-state>
            @else
                <x-empty-state icon="list" title="Aucun ingrédient pour le moment">
                    Lancez <code class="rounded bg-stone-100 px-1">php artisan db:seed</code> pour charger ~150 ingrédients courants.
                </x-empty-state>
            @endif
        @else
            <ul class="divide-y divide-stone-100" wire:loading.class="opacity-60" wire:target="search,aisleFilter,staplesOnly,gotoPage,nextPage,previousPage">
                @foreach ($ingredients as $ingredient)
                    <li wire:key="ingredient-{{ $ingredient->id }}" class="flex items-center gap-3 px-4 py-2.5 hover:bg-stone-50">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-stone-900">
                                {{ $ingredient->name }}
                                @if ($ingredient->name_plural)
                                    <span class="font-normal text-stone-500">/ {{ $ingredient->name_plural }}</span>
                                @endif
                            </p>
                            <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-stone-500">
                                <x-badge :color="$ingredient->aisle->color">{{ $ingredient->aisle->name }}</x-badge>
                                @if ($ingredient->defaultUnit)
                                    <span>{{ $ingredient->defaultUnit->label }}</span>
                                @endif
                                @if ($ingredient->recipe_lines_count)
                                    <a href="{{ route('recipes.index', ['q' => $ingredient->name]) }}" wire:navigate class="hover:text-brand-700">
                                        {{ $ingredient->recipe_lines_count }} recette{{ $ingredient->recipe_lines_count > 1 ? 's' : '' }}
                                    </a>
                                @endif
                                @if ($ingredient->piece_weight_g)
                                    <span class="inline-flex items-center gap-1" title="Poids moyen d'une pièce">
                                        <x-icon name="scale" class="size-3.5" />
                                        ≈ {{ app(\App\Services\QuantityFormatter::class)->number((float) $ingredient->piece_weight_g) }} g
                                    </span>
                                @endif
                                @if ($ingredient->reference_price)
                                    <span class="inline-flex items-center gap-1" title="{{ $ingredient->reference_price_locked ? 'Prix fixé à la main' : 'Dernier prix relevé en magasin' }}">
                                        <x-icon name="euro" class="size-3.5" />
                                        {{ app(\App\Services\Pricing\PriceBook::class)->referenceLabel($ingredient) }}
                                    </span>
                                @endif
                                
                            </div>
                        </div>

                        <button type="button" wire:click="toggleStaple({{ $ingredient->id }})"
                                @class([
                                    'hidden items-center gap-1 rounded-full px-2 py-1 text-xs font-medium sm:inline-flex',
                                    'bg-amber-100 text-amber-800' => $ingredient->is_staple,
                                    'text-stone-500 hover:bg-stone-100 hover:text-stone-600' => ! $ingredient->is_staple,
                                ])
                                title="{{ $ingredient->is_staple ? 'Produit de base : exclu de la liste de courses' : 'Marquer comme produit de base' }}">
                            <x-icon name="pantry" class="size-4" />
                            {{ $ingredient->is_staple ? 'Placard' : '' }}
                        </button>

                        <button type="button" wire:click="edit({{ $ingredient->id }})" class="btn btn-ghost px-2" title="Modifier">
                            <x-icon name="edit" class="size-4" /><span class="sr-only">Modifier {{ $ingredient->name }}</span>
                        </button>
                        <button type="button" wire:click="delete({{ $ingredient->id }})"
                                wire:confirm="Supprimer l'ingrédient « {{ $ingredient->name }} » ?"
                                class="btn btn-ghost px-2 hover:text-red-600" title="Supprimer">
                            <x-icon name="delete" class="size-4" /><span class="sr-only">Supprimer {{ $ingredient->name }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-2 text-sm text-stone-500">
        <span>{{ $ingredients->total() }} sur {{ $total }} ingrédient{{ $total > 1 ? 's' : '' }}</span>
        {{ $ingredients->links('pagination.simple') }}
    </div>

    <x-modal :show="$showForm" :title="$form->ingredient ? 'Modifier l\'ingrédient' : 'Nouvel ingrédient'">
        <form id="ingredient-form" wire:submit="save" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Nom" for="ing-name" error="form.name">
                    <input id="ing-name" type="text" wire:model.live.debounce.400ms="form.name" autofocus
                           @class(['form-input', 'form-input-error' => $errors->has('form.name')])>
                </x-field>
                <x-field label="Pluriel" for="ing-plural" error="form.name_plural" optional>
                    <input id="ing-plural" type="text" wire:model="form.name_plural" placeholder="ex. tomates" class="form-input">
                </x-field>
            </div>

            @if ($form->ingredient)
                <div class="rounded-lg bg-stone-50 px-3 py-2 ring-1 ring-stone-200">
                    <p class="form-label mb-1.5">Autres noms <span class="font-normal text-stone-500">— reconnus dans la recherche, l'ajout rapide et les recettes</span></p>
                    <div class="flex flex-wrap items-center gap-1.5">
                        @foreach ($form->ingredient->aliases()->get() as $alias)
                            <span wire:key="alias-{{ $alias->id }}" class="flex items-center gap-1 rounded-full bg-white px-2.5 py-0.5 text-sm text-stone-700 ring-1 ring-stone-200">
                                {{ $alias->name }}
                                <button type="button" wire:click="removeAlias({{ $alias->id }})" class="text-stone-400 hover:text-red-600" title="Retirer « {{ $alias->name }} »"><x-icon name="close" class="size-3.5" /></button>
                            </span>
                        @endforeach
                        <input type="text" wire:model="newAlias" wire:keydown.enter.prevent="addAlias" placeholder="Ajouter un nom…" class="form-input w-40 py-1 text-sm" aria-label="Ajouter un autre nom">
                        <button type="button" wire:click="addAlias" class="btn btn-ghost px-2 py-1 text-sm">Ajouter</button>
                        <a href="{{ route('settings.ingredient-duplicates', ['source' => $form->ingredient->id]) }}" wire:navigate class="ml-auto text-xs font-medium text-brand-700 hover:underline">Fusionner avec un autre…</a>
                    </div>
                    @error('newAlias') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @endif

            @if ($this->similar->isNotEmpty() && ! $errors->has('form.name'))
                <div class="flex gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    <x-icon name="warning" class="mt-0.5 size-4 shrink-0" />
                    <p>Ingrédient proche déjà enregistré :
                        {{ $this->similar->pluck('name')->map(fn ($n) => "« {$n} »")->join(', ', ' et ') }}.
                    </p>
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Rayon" for="ing-aisle" error="form.aisle_id">
                    <select id="ing-aisle" wire:model="form.aisle_id" class="form-input">
                        @foreach ($this->aisles as $aisle)
                            <option value="{{ $aisle->id }}">{{ $aisle->name }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field label="Unité par défaut" for="ing-unit" error="form.default_unit_id" optional>
                    <select id="ing-unit" wire:model="form.default_unit_id" class="form-input">
                        <option value="">—</option>
                        @foreach ($this->units as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->label }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <x-field label="Poids moyen d'une pièce (g)" for="ing-weight" error="form.piece_weight_g" optional
                     help="Permet d'additionner « 2 oignons » et « 200 g d'oignon » dans la liste de courses.">
                <input id="ing-weight" type="text" inputmode="decimal" wire:model="form.piece_weight_g" placeholder="ex. 150" class="form-input sm:w-40">
            </x-field>

            {{-- Prix de référence (lot 17, R17) : sert au coût des recettes et des listes. --}}
            <div>
                <span class="form-label">Prix de référence</span>
                <div class="flex flex-wrap items-start gap-2">
                    <div class="w-32">
                        <input type="text" inputmode="decimal" wire:model="form.reference_price" placeholder="ex. 4,98" aria-label="Prix"
                               @class(['form-input', 'form-input-error' => $errors->has('form.reference_price')])>
                    </div>
                    <span class="pt-2 text-sm text-stone-500">€ par</span>
                    <div class="w-36">
                        <select wire:model="form.reference_price_unit_id" class="form-input" aria-label="Unité du prix">
                            <option value="">pièce</option>
                            @foreach ($this->units as $unit)
                                <option value="{{ $unit->id }}">{{ $unit->label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                @error('form.reference_price') <p class="form-error">{{ $message }}</p> @enderror
                <p class="form-help">
                    Facultatif. Un prix saisi ici est fixe : les prix relevés en magasin ne l'écraseront plus.
                    @if ($this->form->ingredient?->reference_price && ! $this->form->ingredient->reference_price_locked)
                        <span class="text-stone-600">Actuellement d'après le dernier passage en caisse&nbsp;: {{ app(\App\Services\Pricing\PriceBook::class)->referenceLabel($this->form->ingredient) }}.</span>
                    @endif
                </p>
            </div>

            <label class="flex items-start gap-3 rounded-lg border border-stone-200 p-3">
                <input type="checkbox" wire:model="form.is_staple" class="form-checkbox mt-0.5">
                <span class="text-sm">
                    <span class="font-medium text-stone-800">Produit de base</span>
                    <span class="block text-stone-500">Sel, huile, farine… Toujours au placard : proposé dans « À vérifier » plutôt qu'ajouté à la liste de courses.</span>
                </span>
            </label>

            <fieldset class="space-y-3 rounded-lg border border-stone-200 p-3">
                <legend class="px-1 text-sm font-medium text-stone-800">Stock et conservation</legend>
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-field label="Suivi" for="ing-stock-mode" error="form.stock_mode">
                        <select id="ing-stock-mode" wire:model.live="form.stock_mode" class="form-input">
                            @foreach (\App\Enums\StockMode::cases() as $mode) <option value="{{ $mode->value }}">{{ $mode->label() }}</option> @endforeach
                        </select>
                    </x-field>
                    @if ($form->stock_mode !== 'none')
                        <x-field label="Emplacement habituel" for="ing-location" error="form.storage_location_id" optional>
                            <select id="ing-location" wire:model="form.storage_location_id" class="form-input">
                                <option value="">—</option>
                                @foreach ($this->storageLocations as $location) <option value="{{ $location->id }}">{{ $location->name }}</option> @endforeach
                            </select>
                        </x-field>
                    @endif
                </div>
                @if ($form->stock_mode !== 'none')
                    <div class="grid gap-3 sm:grid-cols-3">
                        <x-field label="Se conserve (jours)" for="ing-shelf" error="form.shelf_life_days" optional>
                            <input id="ing-shelf" type="number" min="0" wire:model="form.shelf_life_days" class="form-input">
                        </x-field>
                        <x-field label="Type de date" for="ing-shelf-type" error="form.shelf_life_type">
                            <select id="ing-shelf-type" wire:model="form.shelf_life_type" class="form-input">
                                @foreach (\App\Enums\ExpiryType::cases() as $type) <option value="{{ $type->value }}">{{ $type->shortLabel() }}</option> @endforeach
                            </select>
                        </x-field>
                        <x-field label="Après ouverture (jours)" for="ing-opened" error="form.days_after_opening" optional>
                            <input id="ing-opened" type="number" min="0" wire:model="form.days_after_opening" class="form-input">
                        </x-field>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <x-field label="Congélateur (mois)" for="ing-freezer" error="form.freezer_months" optional help="Vide = non congelable.">
                            <input id="ing-freezer" type="number" min="1" wire:model="form.freezer_months" class="form-input">
                        </x-field>
                        <x-field label="Stock minimum" for="ing-min" error="form.min_stock_quantity" optional help="En dessous, l'ingrédient est ajouté à la liste de courses (« Stock bas »).">
                            <input id="ing-min" type="text" inputmode="decimal" wire:model="form.min_stock_quantity" class="form-input">
                        </x-field>
                        <x-field label="Unité du minimum" for="ing-min-unit" error="form.min_stock_unit_id" optional>
                            <select id="ing-min-unit" wire:model="form.min_stock_unit_id" class="form-input">
                                <option value="">—</option>
                                @foreach ($this->units as $unit) <option value="{{ $unit->id }}">{{ $unit->label }}</option> @endforeach
                            </select>
                        </x-field>
                    </div>
                @endif
            </fieldset>
        </form>

        <x-slot:footer>
            <button type="button" wire:click="closeForm" class="btn btn-secondary">Annuler</button>
            <button type="submit" form="ingredient-form" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                Enregistrer
            </button>
        </x-slot:footer>
    </x-modal>
</div>
