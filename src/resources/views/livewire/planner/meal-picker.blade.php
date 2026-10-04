<div>
    @php
        $title = $show && $this->slot
            ? 'Ajouter · '.ucfirst(\Illuminate\Support\Carbon::parse($date)->locale('fr')->isoFormat('dddd D MMMM')).' · '.$this->slot->name
            : 'Ajouter un repas';
    @endphp

    <x-modal :show="$show" :title="$title" close="close" max-width="max-w-2xl">
        {{-- Onglets --}}
        <div class="-mt-1 mb-4 flex gap-1 rounded-xl bg-stone-100 p-1" role="tablist">
            @foreach (['recipe' => 'Recette', 'stock' => 'Avec mon stock', 'wish' => 'Envies', 'leftover' => 'Restes', 'free' => 'Repas libre'] as $key => $label)
                <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        @class([
                            'flex-1 rounded-lg px-2 py-1.5 text-sm font-medium whitespace-nowrap transition sm:px-3',
                            'bg-white text-stone-900 shadow-sm' => $tab === $key,
                            'text-stone-600 hover:text-stone-900' => $tab !== $key,
                        ])>
                    @if ($key === 'stock')
                        <span class="sm:hidden">Stock</span><span class="sr-only sm:not-sr-only">{{ $label }}</span>
                    @else
                        {{ $label }}
                    @endif
                    @if ($key === 'leftover' && $this->leftovers->isNotEmpty())
                        <span class="ml-1 rounded-full bg-brand-600 px-1.5 text-xs text-white">{{ $this->leftovers->count() }}</span>
                    @endif
                    @if ($key === 'wish' && $this->wishes->isNotEmpty())
                        <span class="ml-1 rounded-full bg-brand-600 px-1.5 text-xs text-white">{{ $this->wishes->count() }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        @if ($show && $this->occasion)
            @php $pickerGuests = $this->guests(); @endphp
            <div class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg bg-violet-50 px-3 py-2 text-sm text-violet-900">
                <x-icon name="users" class="size-4 shrink-0" />
                <span class="min-w-0 flex-1">
                    <strong>{{ app(\App\Services\Planning\OccasionService::class)->summary($this->occasion) }}</strong>
                    @if ($this->occasion->title) · {{ $this->occasion->title }} @endif
                    @if ($pickerGuests->isNotEmpty()) · {{ $pickerGuests->pluck('name')->join(', ') }} @endif
                </span>
                <button type="button" wire:click="changeOccasion" class="text-xs font-medium underline">Modifier</button>
            </div>
        @endif

        @error('picker') <p class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</p> @enderror

        {{-- ======================================================== Recette --}}
        @if ($tab === 'recipe')
            <div class="space-y-3">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="relative min-w-48 flex-1">
                        <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" />
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Titre ou ingrédient…"
                               class="form-input pl-10" aria-label="Rechercher une recette" autofocus>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-stone-600">
                        <x-icon name="users" class="size-4" />
                        <input type="number" min="0.5" step="0.5" max="50" wire:model="servings" class="form-input w-20" aria-label="Portions">
                        portions
                    </label>
                </div>
                @error('servings') <p class="form-error">{{ $message }}</p> @enderror

                {{-- Lot 37 (37.4) : trois idées qui respectent les règles, la saison, le stock et les convives. --}}
                @if (trim($search) === '' && $tagIds === [] && $ideas !== [])
                    <div class="rounded-xl bg-brand-50/60 p-3 ring-1 ring-brand-100" data-cell-ideas>
                        <div class="mb-1 flex items-center justify-between gap-2">
                            <p class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-brand-800 uppercase">
                                <x-icon name="sparkles" class="size-4" /> Idées pour cette case
                            </p>
                            <button type="button" wire:click="otherIdeas" wire:loading.attr="disabled" wire:target="otherIdeas" class="btn btn-ghost min-h-10 px-2 text-sm text-brand-800">
                                <x-icon name="shuffle" class="size-4" /> Autre idée
                            </button>
                        </div>
                        <ul class="space-y-1" wire:loading.class="opacity-60" wire:target="otherIdeas">
                            @foreach ($ideas as $idea)
                                <li wire:key="idea-{{ $idea['recipe_id'] }}">
                                    <button type="button" wire:click="pickRecipe({{ $idea['recipe_id'] }})"
                                            class="flex min-h-11 w-full items-center gap-3 rounded-lg bg-white px-3 py-2 text-left ring-1 ring-brand-100 hover:bg-brand-50">
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-medium text-stone-900">{{ $idea['title'] }}</span>
                                            @if ($idea['reasons'] !== [] || $idea['warnings'] !== [])
                                                <span class="block truncate text-xs">
                                                    <span class="text-stone-500">{{ implode(' · ', $idea['reasons']) }}</span>
                                                    @if ($idea['warnings'] !== []) <span class="text-amber-700">{{ $idea['reasons'] !== [] ? '· ' : '' }}{{ implode(' · ', $idea['warnings']) }}</span> @endif
                                                </span>
                                            @endif
                                        </span>
                                        <x-icon name="plus" class="size-5 shrink-0 text-brand-600" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif


                @if ($this->tags->isNotEmpty())
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($this->tags as $tag)
                            @php $selected = in_array($tag->id, $tagIds, true); @endphp
                            <button type="button" wire:click="toggleTag({{ $tag->id }})" wire:key="picker-tag-{{ $tag->id }}"
                                    @class([
                                        'rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset transition',
                                        \App\Support\Palette::badge($tag->color) => $selected,
                                        'bg-white text-stone-600 ring-stone-200 hover:text-stone-900' => ! $selected,
                                    ])>
                                {{ $tag->name }}
                            </button>
                        @endforeach
                    </div>
                @endif

                @if ($show && $this->guests()->isNotEmpty())
                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-stone-600">
                        <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="compatibleOnly" class="form-checkbox"> Compatibles avec les convives</label>
                        <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="neverServedFirst" class="form-checkbox"> Jamais servies à ces invités d'abord</label>
                    </div>
                @endif

                <p class="text-xs font-semibold tracking-wide text-stone-500 uppercase">
                    {{ trim($search) === '' ? 'Suggestions : pas mangées depuis longtemps' : 'Résultats' }}
                </p>

                <ul class="-mx-2 max-h-[45vh] divide-y divide-stone-100 overflow-y-auto" wire:loading.class="opacity-60" wire:target="search,toggleTag,compatibleOnly,neverServedFirst">
                    @forelse ($this->recipes as $recipe)
                        @php $info = $this->guestInfo[$recipe->id] ?? null; @endphp
                        <li wire:key="pick-{{ $recipe->id }}">
                            <button type="button" wire:click="pickRecipe({{ $recipe->id }})"
                                    class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left hover:bg-brand-50">
                                @if ($recipe->photo_path)
                                    <img src="{{ $recipe->photoUrl('thumb') }}" alt="" class="size-12 shrink-0 rounded-lg object-cover" loading="lazy">
                                @else
                                    <x-dish-illustration :recipe="$recipe" class="size-12 rounded-lg" />
                                @endif
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-medium text-stone-900">{{ $recipe->title }}</p>
                                    <p class="flex flex-wrap gap-x-3 text-xs text-stone-500">
                                        @if ($recipe->total_minutes)
                                            <span>{{ \App\Support\Duration::format($recipe->total_minutes) }}</span>
                                        @endif
                                        <span>
                                            @if ($recipe->last_planned_on)
                                                Dernière fois {{ \Illuminate\Support\Carbon::parse($recipe->last_planned_on)->locale('fr')->diffForHumans(['parts' => 1]) }}
                                            @else
                                                Jamais planifiée
                                            @endif
                                        </span>
                                    </p>
                                    @if ($missing = $this->missingEquipment[$recipe->id] ?? null)
                                        <p class="text-xs font-medium text-amber-700">⚠ Demande : {{ $missing }} (pas dans notre cuisine)</p>
                                    @endif
                                    @if ($info)
                                        @foreach ($info['conflicts'] as $conflict)
                                            <p @class(['text-xs font-medium', 'text-red-700' => $conflict['level'] === 'danger', 'text-amber-700' => $conflict['level'] !== 'danger'])>⚠ {{ $conflict['message'] }}</p>
                                        @endforeach
                                        @foreach ($info['served'] as $served)
                                            <p class="text-xs text-violet-700">Déjà servi à {{ $served['guest'] }} le {{ $served['date']->locale('fr')->isoFormat('D MMMM YYYY') }}</p>
                                        @endforeach
                                    @endif
                                </div>
                                @if ($info && $info['level'] === 'danger')
                                    <x-icon name="warning" class="size-5 text-red-600" />
                                @else
                                    <x-icon name="plus" class="size-5 text-brand-600" />
                                @endif
                            </button>
                        </li>
                    @empty
                        <li class="px-2 py-6 text-center text-sm text-stone-500">
                            Aucune recette.
                            <a href="{{ route('recipes.create') }}" wire:navigate class="text-brand-700 underline">Créer une recette</a>
                        </li>
                    @endforelse
                </ul>
            </div>
        @endif

        {{-- ======================================================== Avec mon stock --}}
        @if ($tab === 'stock')
            <div class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <label class="flex items-center gap-2 text-sm text-stone-600">
                        <x-icon name="users" class="size-4" />
                        <input type="number" min="0.5" step="0.5" max="50" wire:model.live.debounce.500ms="servings" class="form-input w-20" aria-label="Portions">
                        portions
                    </label>
                    <a href="{{ route('suggestions', ['date' => $date, 'creneau' => $slotId, 'portions' => $servings]) }}" wire:navigate class="text-sm font-medium text-brand-700 hover:underline">
                        Plus d'options (doit utiliser, temps…)
                    </a>
                </div>

                <p class="text-xs font-semibold tracking-wide text-stone-500 uppercase">Faisables en premier, puis à 1 ou 2 ingrédients près</p>

                <ul class="-mx-2 max-h-[45vh] divide-y divide-stone-100 overflow-y-auto" wire:loading.class="opacity-60" wire:target="servings,tab">
                    @forelse ($this->stockSuggestions as $row)
                        @php $recipe = $row['recipe']; @endphp
                        <li wire:key="pick-stock-{{ $recipe->id }}">
                            <button type="button" wire:click="pickRecipe({{ $recipe->id }})" class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left hover:bg-brand-50">
                                <span @class(['flex size-12 shrink-0 flex-col items-center justify-center rounded-lg text-xs font-semibold tabular-nums', 'bg-herb-50 text-herb-700' => $row['missing'] === [], 'bg-amber-50 text-amber-800' => $row['missing'] !== []])>
                                    {{ $row['available'] }}/{{ $row['counted'] }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium text-stone-900">{{ $recipe->title }}</span>
                                    <span class="flex flex-wrap gap-x-3 text-xs text-stone-500">
                                        @if ($row['missing'] === []) <span class="text-herb-700">Tout est en stock</span>
                                        @else <span class="text-amber-800">Manque : {{ $row['missing_text'] }}</span> @endif
                                        @if ($row['urgent'] > 0) <span class="text-green-700">♻ {{ $row['urgent'] }} à consommer vite</span> @endif
                                        @if ($recipe->total_minutes) <span>{{ \App\Support\Duration::format($recipe->total_minutes) }}</span> @endif
                                    </span>
                                </span>
                                <x-icon name="plus" class="size-5 text-brand-600" />
                            </button>
                        </li>
                    @empty
                        <li class="px-2 py-6 text-center text-sm text-stone-500">
                            Aucune recette faisable avec le stock actuel.
                            <a href="{{ route('stock.index') }}" wire:navigate class="text-brand-700 underline">Voir le stock</a>
                        </li>
                    @endforelse
                </ul>
            </div>
        @endif

        {{-- ======================================================== Restes --}}
        @if ($tab === 'leftover')
            @if ($this->leftovers->isEmpty())
                <x-empty-state icon="archive" title="Aucun reste disponible">
                    Planifiez une recette avec plus de portions que de convives pour pouvoir placer ses restes.
                </x-empty-state>
            @else
                {{-- Gamelle du midi (32.3) : les restes emportés par une personne, sa portion seulement. --}}
                <x-field label="Pour qui ?" for="lunchbox-for" class="mb-2" help="Une gamelle compte la portion de la personne ; elle apparaît dans « À préparer ce soir » la veille.">
                    <select id="lunchbox-for" wire:model.live="lunchboxFor" class="form-input">
                        <option value="">Toute la table</option>
                        @foreach ($this->lunchboxPeople as $person)
                            <option value="{{ $person->id }}">{{ \App\Models\PlannedMeal::lunchboxLabelFor($person->name) }}</option>
                        @endforeach
                    </select>
                </x-field>

                <ul class="-mx-2 divide-y divide-stone-100">
                    @foreach ($this->leftovers as $row)
                        <li wire:key="leftover-{{ $row['meal']->id }}">
                            <button type="button" wire:click="pickLeftover({{ $row['meal']->id }})"
                                    class="flex w-full items-center gap-3 rounded-lg px-2 py-2.5 text-left hover:bg-brand-50">
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-stone-900">{{ $row['meal']->recipe?->title }}</p>
                                    <p class="text-xs text-stone-500">
                                        {{ ucfirst($row['meal']->date->locale('fr')->isoFormat('dddd D MMMM')) }} · {{ $row['meal']->slot->name }}
                                        · {{ \App\Services\Planning\Appetites::label($row['remaining']) }} restante{{ $row['remaining'] >= 2 ? 's' : '' }}
                                    </p>
                                </div>
                                <x-icon name="plus" class="size-5 text-brand-600" />
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif

        {{-- ======================================================== Envies (14.5) --}}
        @if ($tab === 'wish')
            @if ($this->wishes->isEmpty())
                <x-empty-state icon="star" title="Aucune envie en attente">
                    Notez sur le planning ce dont vous avez envie : « raclette », « tester le curry de Julie ».
                </x-empty-state>
            @else
                <ul class="divide-y divide-stone-100">
                    @foreach ($this->wishes as $wish)
                        <li wire:key="wish-{{ $wish->id }}" class="flex items-center gap-3 py-3">
                            <x-icon name="star" class="size-5 shrink-0 text-amber-500" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium text-stone-900">{{ $wish->label() }}</p>
                                <p class="text-xs text-stone-500">
                                    {{ $wish->isRecipe() ? 'Recette du carnet' : 'Repas libre' }}
                                    @if ($wish->user) · noté par {{ $wish->user->name }} @endif
                                </p>
                            </div>
                            <button type="button" wire:click="pickWish({{ $wish->id }})" class="btn btn-primary">Placer ici</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif

        {{-- ======================================================== Repas libre --}}
        @if ($tab === 'free')
            <form wire:submit="pickFree" class="space-y-4">
                <x-field label="Repas" for="free-text" error="freeText" help="Aucun ingrédient ne sera ajouté à la liste de courses.">
                    <div class="flex gap-2">
                        <input id="free-text" type="text" wire:model="freeText" placeholder="ex. Raclette chez Julie" class="form-input" autofocus>
                        <button type="submit" class="btn btn-primary">Ajouter</button>
                    </div>
                </x-field>

                <div class="flex flex-wrap gap-2">
                    @foreach (\App\Livewire\Planner\MealPicker::FREE_SHORTCUTS as $shortcut)
                        <button type="button" wire:click="pickFree('{{ $shortcut }}')" class="rounded-full bg-stone-100 px-3 py-1 text-sm text-stone-700 hover:bg-stone-200">
                            {{ $shortcut }}
                        </button>
                    @endforeach
                </div>
            </form>
        @endif
    </x-modal>
</div>
