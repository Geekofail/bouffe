<div>
    <x-page-header title="Paramètres" subtitle="Les listes de référence utilisées par les recettes et la liste de courses." />
    <x-settings-nav />

    <div class="grid gap-6 lg:grid-cols-3">
        <form wire:submit="save" class="card min-w-0 space-y-6 p-5 lg:col-span-2">
            <div>
                <h2 class="font-display font-semibold text-stone-900">Alertes de péremption</h2>
                <p class="text-sm text-stone-500">À partir de quand un produit est signalé « à consommer bientôt » (badge orange).</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="DLC et dates estimées (jours avant)" for="dlc-days" error="dlcSoonDays"
                         :help="'Aujourd\'hui, un yaourt qui périme '.$dlcExample.' est signalé.'">
                    <input id="dlc-days" type="number" min="1" max="14" wire:model.live.debounce.400ms="dlcSoonDays" class="form-input sm:w-32">
                </x-field>
                <x-field label="DDM (jours avant)" for="ddm-days" error="ddmSoonDays"
                         :help="'Des biscuits « de préférence avant » le '.$ddmExample.' sont signalés.'">
                    <input id="ddm-days" type="number" min="1" max="60" wire:model.live.debounce.400ms="ddmSoonDays" class="form-input sm:w-32">
                </x-field>
            </div>

            <fieldset class="space-y-3">
                <legend class="form-label">Où afficher les alertes</legend>
                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" wire:model="navBadge" class="form-checkbox mt-0.5">
                    <span><span class="font-medium text-stone-800">Badge sur le menu Stock</span><span class="block text-stone-500">Nombre de produits à surveiller, rouge s'il y a une date limite dépassée, aujourd'hui ou demain.</span></span>
                </label>
                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" wire:model="planningBanner" class="form-checkbox mt-0.5">
                    <span><span class="font-medium text-stone-800">Bandeau dans le planning</span><span class="block text-stone-500">« 3 produits à consommer d'ici dimanche », sur la semaine en cours.</span></span>
                </label>
                <p class="text-xs text-stone-500">La carte « À consommer rapidement » de l'accueil est toujours affichée dès qu'il y a du stock.</p>
            </fieldset>

            <div class="space-y-3 border-t border-stone-100 pt-5" role="radiogroup" aria-labelledby="deduction-title">
                <h2 id="deduction-title" class="font-semibold text-stone-900">Repas mangé</h2>
                <p class="text-sm text-stone-500">Quand un repas est coché « mangé », les ingrédients utilisés peuvent être retirés du stock (les plus anciens d'abord) et les restes rangés au réfrigérateur.</p>
                @foreach (['ask' => ['Demander', 'Une fenêtre propose les quantités à retirer, modifiables.'], 'auto' => ['Automatique', 'Retiré sans question, avec « Annuler » pendant quelques secondes ; décocher « mangé » remet le stock.'], 'never' => ['Jamais', 'Le stock se gère uniquement à la main.']] as $value => [$label, $help])
                    <label class="flex items-start gap-3 text-sm">
                        <input type="radio" wire:model="deductionMode" value="{{ $value }}" name="deduction-mode" class="mt-0.5 accent-brand-600">
                        <span><span class="font-medium text-stone-800">{{ $label }}</span><span class="block text-stone-500">{{ $help }}</span></span>
                    </label>
                @endforeach
            </div>

            <div class="space-y-2 border-t border-stone-200 pt-4">
                <h3 class="font-medium text-stone-900">Repas passés non cochés</h3>
                <p class="text-sm text-stone-500">Un repas jamais marqué « mangé » ne retire rien du stock. Bouffe les rappelle le lendemain matin (cloche et accueil).</p>
                <label class="flex flex-wrap items-center gap-2 text-sm text-stone-700">
                    <select wire:model="autoCloseDays" class="form-input w-full max-w-full py-1.5 sm:w-auto" aria-label="Clôture des repas passés">
                        <option value="0">Toujours demander</option>
                        @foreach (range(1, 7) as $days)
                            <option value="{{ $days }}">Mangé d'office après {{ $days }} jour{{ $days > 1 ? 's' : '' }} sans réponse</option>
                        @endforeach
                    </select>
                </label>
                @error('autoCloseDays') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-start gap-3 border-t border-stone-200 pt-4 text-sm">
                <input type="checkbox" wire:model="cookModeLive" class="form-checkbox mt-0.5">
                <span>
                    <span class="font-medium text-stone-800">Retirer au fil du mode cuisine</span>
                    <span class="block text-stone-500">Cocher un ingrédient dans le mode cuisine (ouvert depuis le planning) le retire aussitôt du stock ; « Terminé » ne retire que le reste.</span>
                </span>
            </label>

            <div class="flex justify-end"><button type="submit" class="btn btn-primary">Enregistrer</button></div>
        </form>

        <aside class="card h-fit space-y-3 p-4 text-sm text-stone-600">
            <h2 class="font-display font-semibold text-stone-900">Les niveaux</h2>
            <ul class="space-y-2">
                <li class="flex items-start gap-2"><x-badge color="red" class="shrink-0 whitespace-nowrap">Dépassée</x-badge> DLC passée : à jeter.</li>
                <li class="flex items-start gap-2"><x-badge color="red" class="shrink-0 whitespace-nowrap">Demain</x-badge> DLC aujourd'hui ou demain.</li>
                <li class="flex items-start gap-2"><x-badge color="orange" class="shrink-0 whitespace-nowrap">J-3</x-badge> Bientôt, selon les délais ci-contre.</li>
                <li class="flex items-start gap-2"><x-badge color="yellow" class="shrink-0 whitespace-nowrap">À vérifier</x-badge> DDM ou date estimée passée : souvent encore bon.</li>
            </ul>
            <p class="text-xs text-stone-500">Un produit ouvert suit sa durée après ouverture ; un produit congelé, sa durée au congélateur.</p>
        </aside>

        {{-- Consommations régulières (lot 21, 22.4) --}}
        <section class="card space-y-3 p-5 lg:col-span-2">
            <div>
                <h2 class="font-display font-semibold text-stone-900">Consommations régulières</h2>
                <p class="text-sm text-stone-500">Ce qui part sans passer par un repas (le lait du café, les yaourts du goûter) : retiré du stock tout seul, chaque jour concerné.</p>
            </div>

            @if ($rules->isNotEmpty())
                <ul class="divide-y divide-stone-100 text-sm">
                    @foreach ($rules as $rule)
                        <li wire:key="rule-{{ $rule->id }}" class="flex items-center gap-3 py-2">
                            <span class="flex-1 text-stone-800">
                                <strong>{{ app(\App\Services\QuantityFormatter::class)->format((float) $rule->quantity, $rule->unit) }}</strong>
                                de {{ mb_strtolower($rule->ingredient?->name ?? '?') }}
                                {{ $rule->every_days === 1 ? 'par jour' : 'tous les '.$rule->every_days.' jours' }}
                            </span>
                            <button type="button" wire:click="removeRule({{ $rule->id }})" class="btn btn-ghost px-2 hover:text-red-600" title="Supprimer">
                                <x-icon name="delete" class="size-4" /><span class="sr-only">Supprimer</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form wire:submit="addRule" class="flex flex-wrap items-start gap-2">
                <div class="min-w-48 flex-1">
                    <input type="text" wire:model="ruleText" placeholder="ex. 1 l de lait" class="form-input" aria-label="Consommation">
                    @error('ruleText') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <label class="flex items-center gap-2 text-sm text-stone-600">
                    tous les
                    <input type="number" min="1" max="60" wire:model="ruleEvery" class="form-input w-20" aria-label="Tous les N jours">
                    jour(s)
                </label>
                <button type="submit" class="btn btn-secondary"><x-icon name="plus" class="size-4" /> Ajouter</button>
            </form>
        </section>

    </div>
</div>
