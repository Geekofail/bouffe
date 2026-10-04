<div>
    @php
        $titles = ['menu' => 'Ajouter', 'stock' => 'Ajouter au stock', 'use' => 'J\'ai utilisé…', 'shopping' => 'Ajouter aux courses', 'meal' => 'Ajouter un repas'];
    @endphp
    <x-modal :show="$show" :title="$titles[$mode] ?? 'Ajouter'" close="close" max-width="max-w-md">
        @if ($mode === 'menu')
            <div class="grid grid-cols-2 gap-2">
                @foreach ([
                    ['stock', 'pantry', 'Au stock', '6 œufs, restes…'],
                    ['use', 'minus', 'J\'ai utilisé…', 'Hors repas : goûter, dépannage'],
                    ['shopping', 'cart', 'Aux courses', 'Liste en cours'],
                    ['meal', 'calendar', 'Un repas', 'Aujourd\'hui, demain'],
                ] as [$key, $icon, $label, $help])
                    <button type="button" wire:click="choose('{{ $key }}')"
                            class="flex flex-col items-start gap-1 rounded-xl bg-stone-50 p-3 text-left ring-1 ring-stone-200 transition hover:bg-brand-50 hover:ring-brand-200">
                        <x-icon :name="$icon" class="size-6 text-brand-600" />
                        <span class="font-semibold text-stone-900">{{ $label }}</span>
                        <span class="text-xs text-stone-500">{{ $help }}</span>
                    </button>
                @endforeach
                <a href="{{ route('recipes.create') }}" wire:navigate x-on:click="$wire.close()"
                   class="flex flex-col items-start gap-1 rounded-xl bg-stone-50 p-3 text-left ring-1 ring-stone-200 transition hover:bg-brand-50 hover:ring-brand-200">
                    <x-icon name="recipes" class="size-6 text-brand-600" />
                    <span class="font-semibold text-stone-900">Une recette</span>
                    <span class="text-xs text-stone-500">Nouvelle fiche</span>
                </a>
                <a href="{{ route('recipes.import') }}" wire:navigate x-on:click="$wire.close()"
                   class="flex flex-col items-start gap-1 rounded-xl bg-stone-50 p-3 text-left ring-1 ring-stone-200 transition hover:bg-brand-50 hover:ring-brand-200">
                    <x-icon name="download" class="size-6 text-brand-600" />
                    <span class="font-semibold text-stone-900">Importer</span>
                    <span class="text-xs text-stone-500">Adresse, texte ou fichier</span>
                </a>
                <button type="button" wire:click="expense"
                        class="flex flex-col items-start gap-1 rounded-xl bg-stone-50 p-3 text-left ring-1 ring-stone-200 transition hover:bg-brand-50 hover:ring-brand-200">
                    <x-icon name="euro" class="size-6 text-brand-600" />
                    <span class="font-semibold text-stone-900">Une dépense</span>
                    <span class="text-xs text-stone-500">Montant, lieu, restaurant…</span>
                </button>
                <a href="{{ route('receipts.create') }}" wire:navigate x-on:click="$wire.close()"
                   class="flex flex-col items-start gap-1 rounded-xl bg-stone-50 p-3 text-left ring-1 ring-stone-200 transition hover:bg-brand-50 hover:ring-brand-200">
                    <x-icon name="receipt" class="size-6 text-brand-600" />
                    <span class="font-semibold text-stone-900">Un ticket</span>
                    <span class="text-xs text-stone-500">Photo du ticket de caisse</span>
                </a>
            </div>
        @elseif ($mode === 'stock')
            <form wire:submit="addToStock" class="space-y-3">
                <div class="flex gap-2">
                    <input type="text" wire:model="text" x-init="$nextTick(() => $el.focus())" placeholder="6 œufs, 500 g de haché, restes de lasagnes…"
                           @class(['form-input', 'form-input-error' => $errors->has('text')]) aria-label="Produit à ajouter au stock" autocomplete="off">
                    <button type="submit" class="btn btn-primary shrink-0">Ajouter</button>
                </div>
                @error('text') <p class="form-error">{{ $message }}</p> @enderror

                @if ($unknownName)
                    <div class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-100">
                        <p class="mb-2">« {{ $unknownName }} » n'est pas un ingrédient connu.</p>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="addToStock('ingredient')" class="btn btn-secondary py-1 text-xs">Créer l'ingrédient</button>
                            <button type="button" wire:click="addToStock('prepared')" class="btn btn-secondary py-1 text-xs">Plat préparé / restes</button>
                        </div>
                    </div>
                @endif
                <p class="text-xs text-stone-500">Emplacement et date proposés d'après l'ingrédient ; à corriger depuis la page Stock si besoin.</p>
            </form>
        @elseif ($mode === 'use')
            <form wire:submit="useFromStock" class="space-y-3">
                <div class="flex gap-2">
                    <input type="text" wire:model="text" x-init="$nextTick(() => $el.focus())" placeholder="2 œufs, 20 cl de lait…"
                           @class(['form-input', 'form-input-error' => $errors->has('text')]) aria-label="Ce que vous avez utilisé" autocomplete="off">
                    <button type="submit" class="btn btn-primary shrink-0">Retirer</button>
                </div>
                @error('text') <p class="form-error">{{ $message }}</p> @enderror

                @if ($used !== [])
                    <ul class="space-y-1 rounded-lg bg-stone-50 p-3 text-sm ring-1 ring-stone-200">
                        @foreach ($used as $row)
                            <li class="flex items-start gap-2">
                                <x-icon :name="in_array($row['status'], ['ok', 'partial'], true) ? 'check' : 'info'" @class(['mt-0.5 size-4 shrink-0', 'text-herb-600' => $row['status'] === 'ok', 'text-amber-600' => $row['status'] !== 'ok']) />
                                <span><strong>{{ $row['name'] }}</strong> : {{ $row['text'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <p class="text-xs text-stone-500">Retiré de l'article qui périme le premier. Pour un repas du planning, cochez plutôt « mangé ».</p>
            </form>
        @elseif ($mode === 'shopping')
            <form wire:submit="addToShopping" class="space-y-3">
                <div class="flex gap-2">
                    <input type="text" wire:model="text" x-init="$nextTick(() => $el.focus())" placeholder="Lessive, 2 baguettes…"
                           @class(['form-input', 'form-input-error' => $errors->has('text')]) aria-label="Article à ajouter aux courses" autocomplete="off">
                    <button type="submit" class="btn btn-primary shrink-0">Ajouter</button>
                </div>
                @error('text') <p class="form-error">{{ $message }}</p> @enderror
                <p class="text-xs text-stone-500">Ajouté à la liste en cours (une liste pour 7 jours est créée s'il n'y en a pas).</p>
            </form>
        @else
            <div class="space-y-4">
                @foreach ($days as $day)
                    <div>
                        <p class="mb-2 text-sm font-semibold text-stone-800">{{ $loop->first ? 'Aujourd\'hui' : 'Demain' }} <span class="font-normal text-stone-500">· {{ $day->locale('fr')->isoFormat('dddd D MMMM') }}</span></p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($mealSlots as $slot)
                                <a href="{{ route('planner.week', ['ajouter' => $day->toDateString(), 'creneau' => $slot->id]) }}" wire:navigate x-on:click="$wire.close()"
                                   class="btn btn-secondary py-1.5">{{ $slot->name }}</a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($mode !== 'menu')
            <x-slot:footer>
                <button type="button" wire:click="choose('menu')" class="btn btn-ghost mr-auto"><x-icon name="chevron-left" class="size-4" /> Autre ajout</button>
                <button type="button" wire:click="close" class="btn btn-secondary">Fermer</button>
            </x-slot:footer>
        @endif
    </x-modal>
</div>
