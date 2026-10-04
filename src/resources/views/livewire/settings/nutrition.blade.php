<div>
    <x-page-header title="Nutrition" subtitle="Valeurs indicatives, calculées depuis la table Ciqual de l'Anses." />
    <x-settings-nav />

    {{-- ============================================================ État de la table --}}
    <div class="card mb-6 p-5">
        @if ($foodCount === 0)
            <h2 class="font-display flex items-center gap-2 font-semibold text-stone-900">
                <x-icon name="warning" class="size-5 text-amber-600" /> Table Ciqual non importée
            </h2>
            <p class="mt-2 text-sm text-stone-600">
                Bouffe ne livre aucune donnée nutritionnelle : les valeurs viennent du fichier officiel de l'Anses,
                à télécharger une seule fois.
            </p>
            <ol class="mt-3 list-inside list-decimal space-y-1 text-sm text-stone-600">
                <li>Téléchargez la table sur <span class="font-mono text-xs">ciqual.anses.fr</span> (ou data.gouv.fr).</li>
                <li>Ouvrez le tableur et enregistrez-le au format <strong>CSV</strong>.</li>
                <li>Dans une invite de commande, dans le dossier <code class="rounded bg-stone-100 px-1">src</code> :</li>
            </ol>
            <pre class="mt-2 overflow-x-auto rounded-lg bg-stone-900 p-3 text-xs text-stone-100">php artisan bouffe:ciqual C:\chemin\vers\ciqual.csv</pre>
            <p class="mt-2 text-sm text-stone-500">
                L'import rattache ensuite automatiquement vos ingrédients aux aliments de la table ; vous pourrez
                vérifier et corriger ici.
            </p>
        @else
            <div class="flex flex-wrap items-center gap-x-8 gap-y-3">
                <div>
                    <p class="text-2xl font-bold text-stone-900">{{ number_format($foodCount, 0, ',', "\u{202F}") }}</p>
                    <p class="text-sm text-stone-500">aliments dans la table</p>
                </div>
                <div>
                    <p class="text-2xl font-bold text-stone-900">{{ $attached }} <span class="text-base font-normal text-stone-500">/ {{ $total }}</span></p>
                    <p class="text-sm text-stone-500">ingrédients rattachés</p>
                </div>

                <button type="button" wire:click="matchAll" class="btn btn-secondary sm:ml-auto">
                    <x-icon name="sparkles" class="size-4" wire:loading.class="animate-spin" wire:target="matchAll" /> Rattacher automatiquement
                </button>
            </div>

            <p class="mt-4 flex gap-2 rounded-lg bg-stone-50 p-3 text-sm text-stone-600">
                <x-icon name="info" class="size-5 shrink-0 text-stone-400" />
                <span>
                    Les valeurs affichées sur les recettes sont <strong>indicatives</strong> : elles dépendent des
                    quantités saisies et de la correspondance ci-dessous. En dessous de 70 % du poids de la recette
                    rattaché, rien n'est affiché — mieux vaut pas de chiffre qu'un chiffre faux.
                </span>
            </p>
        @endif
    </div>

    @if ($foodCount > 0)
        {{-- ======================================================== Filtres --}}
        <div class="mb-4 flex flex-wrap gap-2">
            <div class="relative min-w-56 flex-1">
                <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Chercher un ingrédient…" class="form-input pl-10" aria-label="Chercher un ingrédient">
            </div>
            <select wire:model.live="filter" class="form-input w-auto" aria-label="Filtre">
                <option value="manquants">Sans aliment</option>
                <option value="associes">Rattachés</option>
                <option value="tous">Tous</option>
            </select>
        </div>

        {{-- ======================================================== Correspondances --}}
        <div class="card overflow-hidden">
            @if ($this->ingredients->isEmpty())
                <x-empty-state icon="nutrition" title="Rien à afficher">
                    @if ($filter === 'manquants') Tous les ingrédients affichés sont rattachés à un aliment. @else Changez les filtres. @endif
                </x-empty-state>
            @else
                <ul class="divide-y divide-stone-100">
                    @foreach ($this->ingredients as $ingredient)
                        @php $food = $this->foods->get($ingredient->ciqual_code); @endphp
                        <li wire:key="nutrition-{{ $ingredient->id }}" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2.5 text-sm">
                            <span class="min-w-40 flex-1">
                                <span class="block font-medium text-stone-800">{{ $ingredient->name }}</span>
                                @if ($food)
                                    <span class="block text-xs text-stone-500">{{ $food->name }}</span>
                                @else
                                    <span class="block text-xs text-amber-700">Aucun aliment rattaché : cette recette ne sera pas chiffrée.</span>
                                @endif
                            </span>

                            @if ($food?->energy_kcal)
                                <span class="hidden text-xs text-stone-500 tabular-nums sm:block">{{ number_format($food->energy_kcal, 0, ',', ' ') }} kcal / 100 g</span>
                            @endif

                            @if ($ingredient->density)
                                <span class="hidden text-xs text-stone-500 md:block" title="Densité : conversion des volumes en grammes">{{ $ingredient->density }} g/ml</span>
                            @endif

                            <span class="flex items-center gap-1">
                                <button type="button" wire:click="edit({{ $ingredient->id }})" class="btn btn-ghost px-2" title="Choisir l'aliment">
                                    <x-icon name="edit" class="size-4" /><span class="sr-only">Modifier {{ $ingredient->name }}</span>
                                </button>
                                @if ($ingredient->ciqual_code)
                                    <button type="button" wire:click="detach({{ $ingredient->id }})" class="btn btn-ghost px-2 hover:text-red-600" title="Détacher">
                                        <x-icon name="close" class="size-4" /><span class="sr-only">Détacher {{ $ingredient->name }}</span>
                                    </button>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- ============================================================ Fenêtre de rattachement --}}
    <x-modal :show="$editing !== null" :title="$editing ? 'Aliment pour « '.$editing->name.' »' : ''" close="closeEdit">
        @if ($editing)
            <div class="space-y-4">
                <x-field label="Chercher dans la table" for="food-search">
                    <input id="food-search" type="search" wire:model.live.debounce.300ms="foodSearch" class="form-input" placeholder="ex. carotte crue">
                </x-field>

                @if ($this->candidates->isEmpty())
                    <p class="text-sm text-stone-500">Aucun aliment ne correspond. Essayez un mot plus court (« carotte » plutôt que « carottes nouvelles »).</p>
                @else
                    <ul class="max-h-72 divide-y divide-stone-100 overflow-y-auto rounded-lg ring-1 ring-stone-200">
                        @foreach ($this->candidates as $food)
                            <li wire:key="food-{{ $food->ciqual_code }}">
                                <button type="button" wire:click="attach('{{ $food->ciqual_code }}')"
                                        @class(['flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-stone-50', 'bg-herb-50' => $editing->ciqual_code === $food->ciqual_code])>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-stone-800">{{ $food->name }}</span>
                                        <span class="block text-xs text-stone-500">{{ $food->food_group }}</span>
                                    </span>
                                    @if ($food->energy_kcal)
                                        <span class="shrink-0 text-xs text-stone-500 tabular-nums">{{ number_format($food->energy_kcal, 0, ',', ' ') }} kcal</span>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <form wire:submit="saveDensity" class="border-t border-stone-100 pt-4">
                    <x-field label="Densité (g/ml)" for="density" error="density" optional
                             help="Pour convertir un volume en grammes : 1 par défaut, 0,9 pour l'huile, 1,03 pour le lait.">
                        <div class="flex gap-2">
                            <input id="density" type="text" inputmode="decimal" wire:model="density" placeholder="1"
                                   @class(['form-input w-32', 'form-input-error' => $errors->has('density')])>
                            <button type="submit" class="btn btn-secondary">Enregistrer</button>
                        </div>
                    </x-field>
                </form>
            </div>
        @endif

        <x-slot:footer>
            <button type="button" wire:click="closeEdit" class="btn btn-secondary">Fermer</button>
        </x-slot:footer>
    </x-modal>
</div>
