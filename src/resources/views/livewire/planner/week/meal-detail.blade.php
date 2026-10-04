{{-- Planning : fenêtre de détail d'un repas (lot 36). --}}
{{-- ============================================================ Détail d'un repas --}}
@php $selected = $this->selectedMeal; @endphp
<x-modal :show="(bool) $selected" :title="$selected?->label() ?? ''" close="closeMeal">
    @if ($selected)
        <div class="space-y-5">
            <p class="flex flex-wrap items-center gap-2 text-sm text-stone-600">
                <x-icon name="calendar" class="size-4" />
                {{ ucfirst($selected->date->locale('fr')->isoFormat('dddd D MMMM')) }} · {{ $selected->slot->name }}
                <x-badge :color="match ($selected->type) { \App\Enums\MealType::Recipe => 'orange', \App\Enums\MealType::Leftover => 'sky', default => 'stone' }">
                    {{ $selected->type->label() }}
                </x-badge>
                @if ($selected->course) <x-badge color="violet">{{ $selected->course->label() }}</x-badge> @endif
            </p>

            {{-- Stock pris par des repas plus proches (lot 21, R24) --}}
            @foreach ($this->stockConflicts[$selected->id] ?? [] as $conflict)
                <p class="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">
                    <x-icon name="pantry" class="mt-0.5 size-4 shrink-0" />
                    <span><strong>{{ $conflict['ingredient'] }}</strong> : il en manquera {{ $conflict['missing'] }}, le stock est déjà prévu pour {{ implode(', ', $conflict['taken_by']) }}. La liste de courses en tient compte.</span>
                </p>
            @endforeach

            @if ($selected->prepared_at && ! $selected->cooked_at)
                <p class="flex items-center gap-2 rounded-lg bg-orange-50 px-3 py-2 text-sm text-orange-900 ring-1 ring-orange-100">
                    <x-icon name="fire" class="size-4 shrink-0" />
                    Cuisiné à l'avance le {{ $selected->prepared_at->locale('fr')->isoFormat('D MMMM') }}{{ $selected->preparedDish?->location ? ' · '.mb_strtolower($selected->preparedDish->location->name) : '' }} : il n'y a plus qu'à réchauffer.
                </p>
            @endif

            @php
                $selectedCell = $selected->date->toDateString().'|'.$selected->meal_slot_id;
                $selectedOccasion = app(\App\Services\Planning\OccasionService::class)->find($selected->date, $selected->meal_slot_id);
                $selectedDiners = app(\App\Services\Planning\OccasionService::class)->diners($selectedOccasion);
            @endphp
            <button type="button" wire:click="openOccasion('{{ $selected->date->toDateString() }}', {{ $selected->meal_slot_id }})"
                    class="flex w-full items-center gap-2 rounded-lg bg-violet-50 px-3 py-2 text-left text-sm text-violet-900 ring-1 ring-violet-100 hover:bg-violet-100">
                <x-icon name="users" class="size-4 shrink-0" />
                <span class="flex-1">
                    {{-- Lot 32 (32.2) : « 4 personnes · 3,5 portions ». --}}
                    <strong>{{ app(\App\Services\Planning\OccasionService::class)->summary($selectedOccasion) }}</strong>
                    @if ($selectedOccasion)
                        · {{ collect([$selectedOccasion->title, app(\App\Services\Planning\OccasionService::class)->guestSummary($selectedOccasion)])->filter()->join(' — ') }}
                    @else
                        · foyer
                    @endif
                </span>
                <span class="text-xs font-medium">Modifier</span>
            </button>

            @if ($conflictItems = $this->mealConflicts[$selected->id]['items'] ?? [])
                <ul class="-mt-3 space-y-0.5 text-sm">
                    @foreach ($conflictItems as $conflict)
                        <li @class(['text-red-700' => $conflict['level'] === 'danger', 'text-amber-700' => $conflict['level'] !== 'danger'])>⚠ {{ $conflict['message'] }}</li>
                    @endforeach
                </ul>
            @endif

            @if ($selected->eatenRecipe())
                <a href="{{ route('recipes.show', ['recipe' => $selected->eatenRecipe(), 'repas' => $selected->id]) }}" wire:navigate class="flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
                    <x-icon name="recipes" class="size-4" /> Voir la recette
                </a>
            @endif

            {{-- Lot 37 (37.4) : « Autre idée » — trois plats qui respectent les règles, la saison, le stock et les convives. --}}
            @if ($selected->isRecipe() && ! $selected->cooked_at && ! $selected->prepared_at && auth()->user()->canEdit())
                <div class="rounded-xl bg-brand-50/60 p-3 ring-1 ring-brand-100" data-meal-ideas>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm text-stone-700">{{ $mealIdeas === [] ? 'Pas envie de ce plat-là ?' : 'Remplacer par :' }}</p>
                        <button type="button" wire:click="otherIdea" wire:loading.attr="disabled" wire:target="otherIdea" class="btn btn-secondary min-h-10 px-3 text-sm">
                            <x-icon name="shuffle" class="size-4" /> {{ $mealIdeas === [] ? 'Autre idée' : 'Encore une autre' }}
                        </button>
                    </div>
                    @if ($mealIdeas !== [])
                        <ul class="mt-2 space-y-1" wire:loading.class="opacity-60" wire:target="otherIdea">
                            @foreach ($mealIdeas as $idea)
                                <li wire:key="meal-idea-{{ $idea['recipe_id'] }}">
                                    <button type="button" wire:click="replaceWithIdea({{ $idea['recipe_id'] }})"
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
                                        <x-icon name="shuffle" class="size-4 shrink-0 text-brand-600" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            {{-- Cuisiner un repas complet (31.3) : dès deux plats dans la case. --}}
            @php $cellDishes = ($this->meals->get($selected->date->toDateString().'|'.$selected->meal_slot_id) ?? collect())->filter(fn ($m) => ! $m->isFree() && $m->eatenRecipe()); @endphp
            @if ($cellDishes->count() >= 2)
                <a href="{{ route('planner.cook', ['date' => $selected->date->toDateString(), 'slot' => $selected->meal_slot_id]) }}" wire:navigate class="flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline">
                    <x-icon name="fire" class="size-4" /> Cuisiner le repas complet ({{ $cellDishes->count() }} plats)
                </a>
            @endif

            @if ($selected->isLeftover() && $selected->leftoverOf)
                <p class="text-sm text-stone-600">
                    Restes du {{ $selected->leftoverOf->date->locale('fr')->isoFormat('dddd D MMMM') }} ({{ mb_strtolower($selected->leftoverOf->slot->name) }}),
                    {{ \App\Services\Planning\Appetites::label($selected->servings) }}.
                </p>

                {{-- Gamelle du midi (32.3) --}}
                <div class="flex flex-wrap items-end gap-2">
                    <x-field label="Emporté en gamelle ?" for="edit-lunchbox" class="min-w-48 flex-1">
                        <select id="edit-lunchbox" wire:change="setLunchbox({{ $selected->id }}, $event.target.value)" class="form-input">
                            <option value="" @selected(! $selected->isLunchbox())>Non, mangé à table</option>
                            @foreach ($this->lunchboxPeople as $person)
                                <option value="{{ $person->id }}" @selected($selected->isLunchbox() && $selected->for_person_id === $person->id)>{{ \App\Models\PlannedMeal::lunchboxLabelFor($person->name) }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    @if ($selected->isLunchbox())
                        <a href="{{ route('planner.lunchboxes', ['du' => $selected->date->toDateString(), 'au' => $selected->date->toDateString()]) }}" class="btn btn-secondary" target="_blank" rel="noopener">
                            <x-icon name="printer" class="size-4" /> Étiquette
                        </a>
                    @endif
                </div>
            @endif

            <button type="button" wire:click="toggleCooked({{ $selected->id }})"
                    @class([
                        'flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-medium ring-1 transition',
                        'bg-herb-50 text-herb-700 ring-herb-100' => $selected->cooked_at,
                        'bg-white text-stone-700 ring-stone-200 hover:bg-stone-50' => ! $selected->cooked_at,
                    ])>
                <x-icon :name="$selected->cooked_at ? 'success' : 'check'" class="size-5" />
                {{ $selected->cooked_at ? 'Mangé ✓ — cliquer pour annuler' : 'Marquer comme mangé' }}
            </button>

            @if (! $selected->cooked_at && ! $selected->isFree() && $selected->date->lte(today()))
                <button type="button" wire:click="toggleSkipped({{ $selected->id }})" class="-mt-3 text-sm text-stone-500 underline hover:text-stone-800">
                    {{ $selected->isSkipped() ? 'Noté « pas mangé » — annuler' : 'Pas mangé (rien n\'est retiré du stock)' }}
                </button>
            @endif

            {{-- Réactions (18.4) --}}
            @if ($selected->cooked_at && $selected->eatenRecipe())
                @php
                    $mealReactions = $this->reactions->get($selected->id, collect());
                    $mine = $mealReactions->firstWhere('user_id', auth()->id());
                @endphp
                <div class="rounded-lg bg-stone-50 p-3">
                    <p class="mb-2 text-sm font-medium text-stone-700">Alors, c'était bien ?</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" wire:click="react({{ $selected->id }}, 1)"
                                @class(['btn', 'btn-primary' => $mine?->isLike(), 'btn-secondary' => ! $mine?->isLike()])>
                            <x-icon name="heart-solid" class="size-4" /> On a aimé
                        </button>
                        <button type="button" wire:click="react({{ $selected->id }}, -1)"
                                @class(['btn', 'btn-danger' => $mine && ! $mine->isLike(), 'btn-secondary' => ! $mine || $mine->isLike()])>
                            <x-icon name="minus" class="size-4" /> Bof
                        </button>

                        @foreach ($mealReactions->where('user_id', '!=', auth()->id()) as $reaction)
                            <span wire:key="react-{{ $reaction->id }}"
                                  @class(['rounded-full px-2 py-1 text-xs font-medium', 'bg-herb-100 text-herb-800' => $reaction->isLike(), 'bg-red-100 text-red-800' => ! $reaction->isLike()])>
                                {{ $reaction->user?->name }} : {{ $reaction->isLike() ? 'a aimé' : 'bof' }}
                            </span>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs text-stone-500">Le remplissage automatique s'en souvient.</p>
                </div>
            @endif

            {{-- « Notre version » (31.2) : proposée une fois le repas mangé. --}}
            @if ($selected->cooked_at && $selected->eatenRecipe() && ! $selected->eatenRecipe()->isForeign() && auth()->user()->canEdit())
                <livewire:recipes.photos :recipe="$selected->eatenRecipe()" :meal-id="$selected->id" :compact="true" wire:key="our-version-{{ $selected->id }}" />
            @endif

            @unless ($selected->isFree())
                <form wire:submit="saveMeal" id="meal-form" class="grid gap-4 sm:grid-cols-[8rem_1fr]">
                    <x-field label="Portions" for="edit-servings" error="editServings">
                        <input id="edit-servings" type="number" min="0.5" step="0.5" max="50" wire:model="editServings" class="form-input" @disabled($selected->isLeftover())>
                    </x-field>
                    <x-field label="Commentaire" for="edit-comment" error="editComment" optional>
                        <input id="edit-comment" type="text" wire:model="editComment" placeholder="ex. inviter Julie" class="form-input">
                    </x-field>
                    <x-field label="Qui cuisine ?" for="edit-cook" error="editCook" optional class="sm:col-span-2">
                        <select id="edit-cook" wire:model="editCook" class="form-input">
                            <option value="">Personne de désigné</option>
                            <option value="together">Ensemble</option>
                            @foreach ($this->householdMembers as $member)
                                <option value="{{ $member->id }}">{{ $member->name }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </form>
            @else
                <form wire:submit="saveMeal" id="meal-form">
                    <x-field label="Commentaire" for="edit-comment" error="editComment" optional>
                        <input id="edit-comment" type="text" wire:model="editComment" class="form-input">
                    </x-field>
                </form>

                {{-- Repas pris dehors : sa dépense (lot 22, 23.4) --}}
                @if ($selected->date->lte(today()) && auth()->user()->canEdit())
                    @php $mealExpense = \App\Models\Expense::query()->where('planned_meal_id', $selected->id)->first(); @endphp
                    <button type="button" wire:click="mealExpense({{ $selected->id }})"
                            class="flex w-full items-center gap-3 rounded-lg bg-white px-3 py-2.5 text-left text-sm font-medium text-stone-700 ring-1 ring-stone-200 hover:bg-stone-50">
                        <x-icon name="euro" class="size-5 text-brand-600" />
                        @if ($mealExpense)
                            Dépense : {{ app(\App\Services\Budget\BudgetTracker::class)->money((float) $mealExpense->amount) }} — modifier
                        @else
                            Enregistrer la dépense (restaurant, livraison…)
                        @endif
                    </button>
                @endif
            @endunless

            @if ($selected->isRecipe())
                @php $remaining = app(\App\Services\Planning\WeekPlanner::class)->remainingLeftovers($selected); @endphp
                <div class="rounded-lg bg-stone-50 p-3 text-sm text-stone-600">
                    @if ($selected->leftovers->isNotEmpty())
                        <p class="mb-1 font-medium text-stone-700">Restes planifiés :</p>
                        <ul class="mb-2 list-inside list-disc">
                            @foreach ($selected->leftovers as $leftover)
                                <li>{{ $leftover->date->locale('fr')->isoFormat('dddd D') }} · {{ mb_strtolower($leftover->slot->name) }} ({{ \App\Services\Planning\Appetites::label($leftover->servings) }})</li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($remaining > 0)
                        <button type="button" wire:click="placeLeftoversOf({{ $selected->id }})" class="font-semibold text-brand-700 hover:underline">
                            Placer les restes ({{ \App\Services\Planning\Appetites::label($remaining) }})
                        </button>
                    @elseif ($selected->leftovers->isEmpty())
                        <p>Pas de restes : augmentez les portions (au-delà de {{ \App\Services\Planning\Appetites::format($selectedDiners) }}) pour en prévoir.</p>
                    @endif
                </div>
            @endif

            @unless ($selected->isLeftover())
                <details class="text-sm">
                    <summary class="cursor-pointer font-medium text-stone-700">Dupliquer vers un autre jour…</summary>
                    <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                        @foreach ($days as $day)
                            @foreach ($this->activeSlots as $slot)
                                <button type="button" wire:key="dup-{{ $day->toDateString() }}-{{ $slot->id }}"
                                        wire:click="duplicateMeal('{{ $day->toDateString() }}', {{ $slot->id }})"
                                        class="rounded-md bg-stone-100 px-2 py-1.5 text-xs text-stone-700 hover:bg-brand-50 hover:text-brand-700">
                                    {{ ucfirst($day->locale('fr')->isoFormat('ddd D')) }} · {{ $slot->name }}
                                </button>
                            @endforeach
                        @endforeach
                    </div>
                </details>
            @endunless
        </div>

        <x-slot:footer>
            <button type="button" wire:click="deleteMeal({{ $selected->id }})"
                    wire:confirm="{{ $selected->leftovers->isNotEmpty() ? 'Retirer ce repas et ses restes planifiés ?' : 'Retirer ce repas du planning ?' }}"
                    class="btn btn-ghost mr-auto text-red-600 hover:bg-red-50">
                <x-icon name="delete" class="size-4" /> Retirer
            </button>
            <button type="button" wire:click="closeMeal" class="btn btn-secondary">Fermer</button>
            <button type="submit" form="meal-form" class="btn btn-primary">Enregistrer</button>
        </x-slot:footer>
    @endif
</x-modal>
