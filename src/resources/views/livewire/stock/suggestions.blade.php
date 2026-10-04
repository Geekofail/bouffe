<div>
    @if ($context)
        <a href="{{ route('planner.week', ['semaine' => app(\App\Services\Planning\WeekPlanner::class)->weekStart($date)->toDateString()]) }}" wire:navigate
           class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
            <x-icon name="chevron-left" class="size-4" /> Planning
        </a>
    @endif

    <x-page-header title="Que cuisiner ?"
                   :subtitle="$context ? 'Pour '.$context.' — recettes faisables avec ce qu\'il y a en stock.' : 'Les recettes faisables avec ce qu\'il y a en stock, en priorité celles qui utilisent ce qui périme bientôt.'">
        <x-slot:actions>
            <a href="{{ route('stock.index') }}" wire:navigate class="btn btn-secondary"><x-icon name="pantry" class="size-4" /> Stock</a>
        </x-slot:actions>
    </x-page-header>

    {{-- ============================================================ Paramètres --}}
    <section class="card mb-5 space-y-4 p-4">
        <div class="flex flex-wrap items-center gap-x-5 gap-y-3">
            <label class="flex items-center gap-2 text-sm text-stone-700">
                <x-icon name="users" class="size-4 text-stone-500" />
                <input type="number" min="1" max="50" wire:model.live.debounce.500ms="servings" class="form-input w-20 py-1.5" aria-label="Portions">
                portions
            </label>
            <label class="flex items-center gap-2 text-sm text-stone-700">
                <x-icon name="clock" class="size-4 text-stone-500" />
                <select wire:model.live="maxMinutes" class="form-input w-auto py-1.5" aria-label="Temps total maximum">
                    <option value="">Tous les temps</option>
                    @foreach ([20 => '20 min max', 30 => '30 min max', 45 => '45 min max', 60 => '1 h max', 90 => '1 h 30 max'] as $minutes => $label)
                        <option value="{{ $minutes }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex items-center gap-2 text-sm text-stone-700" title="Par défaut, le stock prévu pour les repas des 7 prochains jours n'est pas proposé.">
                <input type="checkbox" wire:model.live="ignorePlanning" class="form-checkbox">
                Ignorer le planning
            </label>
            @if ($tagIds !== [] || $maxMinutes !== '' || $mustUse !== [] || $ignorePlanning)
                <button type="button" wire:click="resetFilters" class="text-sm text-stone-500 underline hover:text-stone-800">Effacer les filtres</button>
            @endif
        </div>

        @if ($this->tags->isNotEmpty())
            <div class="flex flex-wrap gap-1.5" aria-label="Catégories">
                @foreach ($this->tags as $tag)
                    @php $selected = in_array($tag->id, $tagIds, true); @endphp
                    <button type="button" wire:click="toggleTag({{ $tag->id }})" wire:key="tag-{{ $tag->id }}"
                            @class(['rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset transition', \App\Support\Palette::badge($tag->color) => $selected, 'bg-white text-stone-600 ring-stone-200 hover:text-stone-900' => ! $selected])>
                        {{ $tag->name }}
                    </button>
                @endforeach
            </div>
        @endif

        @if ($this->stockIngredients->isNotEmpty())
            <div>
                <p class="mb-1.5 text-xs font-semibold tracking-wide text-stone-500 uppercase">Doit utiliser</p>
                <div class="flex flex-wrap gap-1.5">
                    @php $chips = $showAllIngredients ? $this->stockIngredients : $this->stockIngredients->take(14)->concat($this->stockIngredients->skip(14)->whereIn('id', $mustUse)); @endphp
                    @foreach ($chips as $row)
                        @php $selected = in_array($row['id'], $mustUse, true); @endphp
                        <button type="button" wire:click="toggleMustUse({{ $row['id'] }})" wire:key="use-{{ $row['id'] }}"
                                @class(['flex items-center gap-1 rounded-full px-2.5 py-1 text-sm ring-1 transition', 'bg-brand-600 text-white ring-brand-600' => $selected, 'bg-white text-stone-700 ring-stone-200 hover:ring-brand-300' => ! $selected])>
                            {{ $row['name'] }}
                            @if ($row['days'] !== null && $row['days'] <= \App\Services\Stock\RecipeSuggester::SOON_DAYS)
                                <span @class(['text-xs', 'text-white/80' => $selected, 'text-orange-700' => ! $selected])>{{ $row['days'] <= 0 ? 'auj.' : 'J-'.$row['days'] }}</span>
                            @endif
                        </button>
                    @endforeach
                    @if (! $showAllIngredients && $this->stockIngredients->count() > 14)
                        <button type="button" wire:click="$set('showAllIngredients', true)" class="px-2 text-sm text-brand-700 hover:underline">+ {{ $this->stockIngredients->count() - 14 }} autres</button>
                    @endif
                </div>
            </div>
        @endif
    </section>

    {{-- ============================================================ Résultats --}}
    @php
        $results = $this->results;
        $total = $results['feasible']->count() + $results['almost']->count();
    @endphp

    <div wire:loading.class="opacity-60" wire:target="servings,maxMinutes,ignorePlanning,toggleTag,toggleMustUse,resetFilters">
        @if ($results['reserved'] > 0 && ! $ignorePlanning)
            <p class="mb-3 text-xs text-stone-500">
                Le stock prévu pour {{ $results['reserved'] }} repas planifié{{ $results['reserved'] > 1 ? 's' : '' }} dans les 7 prochains jours n'est pas compté.
            </p>
        @endif

        @foreach (['feasible' => ['Faisable tout de suite', 'Tout est en stock (ou dans le placard pour les produits de base).'], 'almost' => ['Presque', 'Il manque 1 ou 2 ingrédients.']] as $group => [$heading, $help])
            <section class="mb-6" wire:key="group-{{ $group }}">
                <h2 class="font-display mb-1 flex items-center gap-2 text-lg font-semibold text-stone-900">
                    {{ $heading }} <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-600 tabular-nums">{{ $results[$group]->count() }}</span>
                </h2>
                <p class="mb-3 text-sm text-stone-500">{{ $help }}</p>

                @if ($results[$group]->isEmpty())
                    <p class="card px-4 py-5 text-center text-sm text-stone-500">
                        @if ($group === 'feasible')
                            Aucune recette entièrement faisable{{ $mustUse !== [] ? ' avec ces ingrédients' : '' }}.
                        @else
                            Aucune recette à 1 ou 2 ingrédients près.
                        @endif
                    </p>
                @else
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($results[$group] as $row)
                            @include('livewire.stock.partials.suggestion-card', ['row' => $row])
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach

        @if ($results['others']->isNotEmpty())
            @if ($showOthers)
                <section class="mb-6">
                    <h2 class="font-display mb-3 flex items-center gap-2 text-lg font-semibold text-stone-900">Autres recettes <span class="rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-600">{{ $results['others']->count() }}</span></h2>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($results['others'] as $row)
                            @include('livewire.stock.partials.suggestion-card', ['row' => $row])
                        @endforeach
                    </div>
                </section>
            @else
                <button type="button" wire:click="$set('showOthers', true)" class="text-sm font-medium text-brand-700 hover:underline">
                    Voir les {{ $results['others']->count() }} autres recettes (3 ingrédients manquants ou plus)
                </button>
            @endif
        @elseif ($total === 0)
            <div class="card">
                <x-empty-state icon="recipes" title="Aucune recette ne correspond">
                    Changez les filtres, ou ajoutez des produits au stock.
                </x-empty-state>
            </div>
        @endif
    </div>

    {{-- ============================================================ Que faire avec… (33.2) --}}
    <livewire:stock.what-to-make wire:key="what-to-make" />

    {{-- ============================================================ Planifier --}}
    <x-modal :show="$planRecipe !== null" :title="'Planifier « '.($planRecipe?->title).' »'" close="closePlan">
        <form id="plan-form" wire:submit="plan" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Date" for="plan-date" error="planDate">
                    <input id="plan-date" type="date" wire:model="planDate" class="form-input">
                </x-field>
                <x-field label="Créneau" for="plan-slot" error="planSlotId">
                    <select id="plan-slot" wire:model="planSlotId" class="form-input">
                        @foreach ($this->activeSlots as $slot) <option value="{{ $slot->id }}">{{ $slot->name }}</option> @endforeach
                    </select>
                </x-field>
            </div>
            <x-field label="Portions" for="plan-servings" error="planServings">
                <input id="plan-servings" type="number" min="0.5" step="0.5" max="50" wire:model="planServings" class="form-input sm:w-32">
            </x-field>
        </form>
        <x-slot:footer>
            <button type="button" wire:click="closePlan" class="btn btn-secondary">Annuler</button>
            <button type="submit" form="plan-form" class="btn btn-primary">Planifier</button>
        </x-slot:footer>
    </x-modal>
</div>
