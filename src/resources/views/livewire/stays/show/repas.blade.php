{{-- Séjour — planning (34.1) : à part de celui de la maison. --}}
@php
    $viewer = (int) \App\Support\CurrentHousehold::id();
    $coorganizers = app(\App\Services\Stays\StayCoorganizers::class);
@endphp
<div class="space-y-3" data-stay-meals>
    <p class="text-sm text-stone-500">
        Les portions se calculent d'après les présents de chaque jour et leur appétit ; on peut les changer plat par plat.
        Ces repas n'apparaissent pas dans le planning de la maison.
        {{-- Lot 41 (41.2) --}}
        <a href="{{ route('offline.stay', $stay) }}" class="font-medium text-brand-700 hover:underline">Garder les recettes pour cuisiner sans réseau</a>
    </p>

    @foreach ($this->grid as $row)
        <section class="card p-4" wire:key="stay-day-{{ $row['date']->toDateString() }}">
            <h2 class="mb-2 flex flex-wrap items-baseline gap-x-2 font-semibold text-stone-900">
                <span class="font-display text-lg">{{ ucfirst($row['date']->locale('fr')->isoFormat('dddd D MMMM')) }}</span>
                <span class="text-sm font-normal text-stone-500">{{ $row['people'] }} personne{{ $row['people'] > 1 ? 's' : '' }} · {{ \App\Services\Planning\Appetites::label($row['portions']) }}</span>
            </h2>
            <div class="divide-y divide-stone-100">
                @foreach ($row['cells'] as $cell)
                    <div class="flex flex-wrap items-start gap-x-3 gap-y-1 py-2" wire:key="stay-cell-{{ $row['date']->toDateString() }}-{{ $cell['slot']->id }}">
                        <p class="w-24 shrink-0 pt-1.5 text-xs font-semibold tracking-wide text-stone-500 uppercase">{{ $cell['slot']->name }}</p>
                        <div class="min-w-0 flex-1 space-y-1">
                            @foreach ($cell['meals'] as $entry)
                                @php
                                    $meal = $entry['meal'];
                                    $manage = $canEdit && $coorganizers->canManage($meal->household_id, $stay);
                                    $by = $coorganizers->ownerId($meal->household_id, $stay);
                                @endphp
                                <div class="flex flex-wrap items-center gap-2" wire:key="stay-meal-{{ $meal->id }}">
                                    <span class="min-w-0 flex-1 basis-40">
                                        {{-- Lot 42 : la recette ne s'ouvre que chez son foyer ; ailleurs, son titre. --}}
                                        @if ($meal->recipe && (int) $meal->recipe->household_id === $viewer)
                                            <a href="{{ route('recipes.show', ['recipe' => $meal->recipe, 'portions' => $entry['servings']]) }}" wire:navigate class="font-medium text-stone-900 hover:text-brand-700">{{ $meal->label() }}</a>
                                        @elseif ($meal->recipe)
                                            <span class="font-medium text-stone-900">{{ $meal->label() }}</span>
                                        @else
                                            <span class="font-medium text-stone-700 italic">{{ $meal->label() }}</span>
                                        @endif
                                        @if (count($householdNames) > 1 && $by !== $viewer)
                                            <span class="text-xs text-stone-500">· prévu par « {{ $householdNames[$by] ?? '' }} »</span>
                                        @endif
                                        @foreach ($entry['conflicts'] as $conflict)
                                            <span @class(['block text-xs', 'text-red-700' => $conflict['level'] === 'danger', 'text-amber-800' => $conflict['level'] !== 'danger'])>⚠ {{ $conflict['message'] }}</span>
                                        @endforeach
                                    </span>
                                    @if ($meal->recipe)
                                        @if ($manage)
                                            <label class="flex items-center gap-1 text-sm text-stone-500" title="{{ $entry['auto'] ? 'Calculé d\'après les présents' : 'Fixé à la main' }}">
                                                <input type="number" min="0.5" step="0.5" max="50" value="{{ $meal->servings }}" placeholder="{{ \App\Services\Planning\Appetites::format($entry['servings']) }}"
                                                       wire:change="setMealServings({{ $meal->id }}, $event.target.value)" class="form-input w-20 py-1 text-sm" aria-label="Portions de {{ $meal->label() }}">
                                                p.
                                            </label>
                                        @else
                                            <span class="text-sm text-stone-500">{{ \App\Services\Planning\Appetites::label($entry['servings']) }}</span>
                                        @endif
                                    @endif
                                    @if ($manage)
                                        <button type="button" wire:click="removeMeal({{ $meal->id }})" class="btn btn-ghost min-h-10 px-2 hover:text-red-600" title="Retirer">
                                            <x-icon name="close" class="size-4" /><span class="sr-only">Retirer {{ $meal->label() }}</span>
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                            @if ($canEdit)
                                <button type="button" wire:click="openCell('{{ $row['date']->toDateString() }}', {{ $cell['slot']->id }})" class="inline-flex min-h-10 items-center gap-1 text-sm text-stone-600 hover:text-brand-700">
                                    <x-icon name="plus" class="size-4" /> {{ $cell['meals'] === [] ? 'Prévoir un repas' : 'Ajouter un plat' }}
                                </button>
                            @elseif ($cell['meals'] === [])
                                <p class="pt-1.5 text-sm text-stone-400">—</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    <x-modal :show="$this->cell !== ''" :title="$this->cell !== '' ? ucfirst(\Illuminate\Support\Carbon::parse(explode('|', $this->cell)[0])->locale('fr')->isoFormat('dddd D MMMM')).' · '.mb_strtolower((string) $this->stayMealSlots->firstWhere('id', (int) explode('|', $this->cell)[1])?->name) : ''" close="closeCell">
        <div class="space-y-4">
            <x-field label="Une recette de notre carnet" for="stay-recipe-search">
                <input id="stay-recipe-search" type="search" wire:model.live.debounce.250ms="recipeSearch" placeholder="Chercher…" class="form-input" autocomplete="off">
            </x-field>
            <ul class="max-h-64 divide-y divide-stone-100 overflow-y-auto">
                @forelse ($this->recipeResults as $recipe)
                    <li wire:key="stay-pick-{{ $recipe->id }}">
                        <button type="button" wire:click="pickRecipe({{ $recipe->id }})" class="flex min-h-11 w-full items-center gap-2 px-1 py-2 text-left hover:bg-stone-50">
                            @if ($recipe->is_favorite) <x-icon name="heart-solid" class="size-4 text-brand-600" /> @endif
                            <span class="flex-1 font-medium text-stone-800">{{ $recipe->title }}</span>
                        </button>
                    </li>
                @empty
                    <li class="py-2 text-sm text-stone-500">Aucune recette trouvée.</li>
                @endforelse
            </ul>
            <form wire:submit="addFreeText" class="flex items-end gap-2 border-t border-stone-100 pt-3">
                <x-field label="Ou simplement" for="stay-free" error="freeText" class="min-w-0 flex-1">
                    <input id="stay-free" type="text" wire:model="freeText" maxlength="150" placeholder="Restaurant, barbecue, pique-nique…" class="form-input">
                </x-field>
                <button type="submit" class="btn btn-secondary">Ajouter</button>
            </form>
            @error('cell') <p class="form-error">{{ $message }}</p> @enderror
        </div>
    </x-modal>
</div>
