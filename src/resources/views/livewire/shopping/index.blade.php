<div>
    <x-page-header title="Listes de courses" subtitle="Générées depuis le planning, cochées en magasin.">
        <x-slot:actions>
            <button type="button" wire:click="openGenerate" class="btn btn-primary">
                <x-icon name="plus" class="size-4" /> Nouvelle liste
            </button>
        </x-slot:actions>
    </x-page-header>

    {{-- ============================================================ En cours --}}
    @if ($activeLists->isEmpty())
        <div class="card">
            <x-empty-state icon="cart" title="Aucune liste en cours">
                Bouffe la calcule d'après les repas planifiés, en tenant compte du stock.
                <x-slot:actions>
                    <button type="button" wire:click="openGenerate" class="btn btn-primary"><x-icon name="sparkles" class="size-4" /> Générer la liste de courses</button>
                </x-slot:actions>
            </x-empty-state>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($activeLists as $list)
                <a href="{{ route('shopping.show', $list) }}" wire:navigate wire:key="list-{{ $list->id }}"
                   class="card group flex flex-col gap-3 p-5 transition hover:ring-brand-300">
                    <div class="flex items-start gap-3">
                        <div class="rounded-lg bg-brand-50 p-2.5 text-brand-600 transition group-hover:bg-brand-600 group-hover:text-white">
                            <x-icon name="cart" class="size-6" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="font-display font-semibold text-stone-900">{{ $list->name }}</h2>
                            <p class="text-sm text-stone-500 first-letter:uppercase">{{ $list->periodLabel() }}</p>
                        </div>
                        <x-icon name="chevron-right" class="size-5 text-stone-400" />
                    </div>
                    <x-shopping.progress :checked="$list->checked_count" :total="$list->total_count" />
                </a>
            @endforeach
        </div>
    @endif

    {{-- ==================================================== Quand je passe (15.8) --}}
    <section class="card mt-8 p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-display flex items-center gap-2 font-semibold text-stone-900">
                <x-icon name="note" class="size-5 text-brand-600" /> Quand je passe
            </h2>
            <p class="text-sm text-stone-500">Hors semaine : ajouté tout seul à la prochaine liste créée.</p>
        </div>

        @if ($this->standing->isNotEmpty())
            <ul class="mt-4 flex flex-wrap gap-2">
                @foreach ($this->standing as $item)
                    <li wire:key="standing-{{ $item->id }}"
                        class="flex items-center gap-2 rounded-full bg-stone-100 py-1 pr-1 pl-3 text-sm text-stone-800">
                        {{ $item->label }}
                        <button type="button" wire:click="removeStanding({{ $item->id }})" class="rounded-full p-1 text-stone-500 hover:bg-stone-200 hover:text-stone-700" title="Retirer">
                            <x-icon name="close" class="size-3.5" /><span class="sr-only">Retirer {{ $item->label }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif

        <form wire:submit="addStanding" class="mt-4 flex max-w-md gap-2">
            <input type="text" wire:model="standingLabel" placeholder="Piles AAA, ampoule, cadeau…" aria-label="Article à prendre quand je passe"
                   @class(['form-input', 'form-input-error' => $errors->has('standingLabel')]) autocomplete="off">
            <button type="submit" class="btn btn-secondary shrink-0"><x-icon name="plus" class="size-4" /> Noter</button>
        </form>
        @error('standingLabel') <p class="form-error">{{ $message }}</p> @enderror
    </section>

    {{-- ============================================================ Historique --}}
    @if ($doneLists->isNotEmpty())
        <h2 class="font-display mt-8 mb-3 text-lg font-semibold text-stone-900">Listes terminées</h2>
        <ul class="card divide-y divide-stone-100">
            @foreach ($doneLists as $list)
                <li wire:key="done-{{ $list->id }}" class="flex items-center gap-3 px-4 py-3">
                    <x-icon name="success" class="size-5 text-herb-600" />
                    <a href="{{ route('shopping.show', $list) }}" wire:navigate class="min-w-0 flex-1 hover:text-brand-700">
                        <span class="font-medium text-stone-800">{{ $list->name }}</span>
                        <span class="block text-xs text-stone-500 first-letter:uppercase">{{ $list->periodLabel() }} · {{ $list->checked_count }}/{{ $list->total_count }} articles</span>
                    </a>
                    <button type="button" wire:click="delete({{ $list->id }})" wire:confirm="Supprimer la liste « {{ $list->name }} » ?"
                            class="btn btn-ghost px-2 hover:text-red-600" title="Supprimer">
                        <x-icon name="delete" class="size-4" /><span class="sr-only">Supprimer</span>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif

    {{-- ============================================================ Génération --}}
    <x-modal :show="$showGenerate" title="Nouvelle liste de courses" close="closeGenerate" max-width="max-w-2xl">
        <form id="generate-form" wire:submit="generate" class="space-y-5">
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="preset('this-week')" class="rounded-full bg-stone-100 px-3 py-1 text-sm text-stone-700 hover:bg-stone-200">Reste de la semaine</button>
                <button type="button" wire:click="preset('next-week')" class="rounded-full bg-stone-100 px-3 py-1 text-sm text-stone-700 hover:bg-stone-200">Semaine prochaine</button>
                <button type="button" wire:click="preset('next-7-days')" class="rounded-full bg-stone-100 px-3 py-1 text-sm text-stone-700 hover:bg-stone-200">7 prochains jours</button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Du" for="gen-from" error="from">
                    <input id="gen-from" type="date" wire:model.live="from" class="form-input">
                </x-field>
                <x-field label="Au" for="gen-to" error="to">
                    <input id="gen-to" type="date" wire:model.live="to" class="form-input">
                </x-field>
            </div>

            <label class="flex items-center gap-2 text-sm text-stone-600">
                <input type="checkbox" wire:model.live="includePast" class="form-checkbox">
                Inclure les repas déjà passés
            </label>

            <label class="flex items-center gap-2 text-sm text-stone-600">
                <input type="checkbox" wire:model="deductStock" class="form-checkbox">
                Déduire ce qui est déjà en stock
            </label>

            <div>
                <p class="form-label">Repas pris en compte</p>
                @if ($this->periodMeals->isEmpty())
                    <p class="rounded-lg bg-stone-50 px-3 py-4 text-center text-sm text-stone-500">
                        Aucune recette planifiée sur cette période.
                        <a href="{{ route('planner.week') }}" wire:navigate class="text-brand-700 underline">Ouvrir le planning</a>
                    </p>
                @else
                    <ul class="max-h-64 divide-y divide-stone-100 overflow-y-auto rounded-lg ring-1 ring-stone-200">
                        @foreach ($this->periodMeals as $meal)
                            @php
                                $isPast = $meal->date->toDateString() < $today;
                                $included = ! in_array($meal->id, $excludedMealIds, true) && (! $isPast || $includePast);
                            @endphp
                            <li wire:key="gen-meal-{{ $meal->id }}">
                                <label @class(['flex items-center gap-3 px-3 py-2 text-sm', 'opacity-50' => $isPast && ! $includePast, 'cursor-pointer hover:bg-stone-50' => ! $isPast || $includePast])>
                                    <input type="checkbox" class="form-checkbox" @checked($included) @disabled($isPast && ! $includePast)
                                           wire:click="toggleMeal({{ $meal->id }})">
                                    <span class="w-28 shrink-0 text-stone-500 first-letter:uppercase">{{ $meal->date->locale('fr')->isoFormat('ddd D') }} · {{ mb_strtolower($meal->slot->name) }}</span>
                                    <span class="flex-1 font-medium text-stone-800">{{ $meal->recipe->title }}</span>
                                    <span class="text-xs text-stone-500">{{ \App\Services\Planning\Appetites::format($meal->servings) }} p.</span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <p class="mt-2 text-sm text-stone-600" wire:loading.class="opacity-50">
                    <strong>{{ $this->preview['meals'] }}</strong> repas → <strong>{{ $this->preview['ingredients'] }}</strong> ingrédients.
                    Les restes et repas libres ne sont pas comptés.
                </p>
            </div>

            <x-field label="Nom de la liste" for="gen-name" error="name" optional>
                <input id="gen-name" type="text" wire:model="name" placeholder="{{ $this->defaultName() }}" class="form-input">
            </x-field>
        </form>

        <x-slot:footer>
            <button type="button" wire:click="closeGenerate" class="btn btn-secondary">Annuler</button>
            <button type="submit" form="generate-form" class="btn btn-primary" wire:loading.attr="disabled" wire:target="generate">
                <x-icon name="cart" class="size-4" /> Générer la liste
            </button>
        </x-slot:footer>
    </x-modal>
</div>
