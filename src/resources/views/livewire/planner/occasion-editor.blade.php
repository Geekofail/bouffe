<div>
    @php
        $heading = $show && $this->slot
            ? 'Convives · '.ucfirst(\Illuminate\Support\Carbon::parse($date)->locale('fr')->isoFormat('ddd D MMMM')).' · '.$this->slot->name
            : 'Convives';
        $diners = $this->diners;
    @endphp

    <x-modal :show="$show" :title="$heading" close="close" max-width="max-w-2xl">
        <form id="occasion-form" wire:submit="save" class="space-y-5">
            {{-- Total --}}
            <div class="flex items-center gap-3 rounded-xl bg-brand-50 px-4 py-3 text-brand-900">
                <x-icon name="users" class="size-6 shrink-0" />
                <p class="flex-1 text-sm">
                    <span class="text-2xl font-bold tabular-nums">{{ $diners['people'] }}</span>
                    {{ $diners['people'] > 1 ? 'personnes' : 'personne' }}
                    · <span class="font-semibold tabular-nums">{{ \App\Services\Planning\Appetites::label($diners['portions']) }}</span>
                </p>
                <p class="hidden text-xs text-brand-700 sm:block">portions d'après l'appétit de chacun, proposées pour chaque plat</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Occasion" for="occ-title" error="title" optional>
                    <input id="occ-title" type="text" wire:model="title" maxlength="150" placeholder="ex. Anniversaire de Julie" class="form-input">
                </x-field>
                <x-field label="Note" for="occ-notes" error="notes" optional>
                    <input id="occ-notes" type="text" wire:model="notes" maxlength="255" placeholder="ex. Paul apporte le dessert" class="form-input">
                </x-field>
            </div>

            {{-- Foyer --}}
            <fieldset>
                <legend class="form-label">À la maison</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach ($this->presence as $row)
                        @if ($row['canteen'])
                            {{-- Lot 39 : à la cantine ce midi, pas compté à la maison. --}}
                            <span wire:key="present-{{ $row['key'] }}" class="flex items-center gap-2 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-900 ring-1 ring-sky-200">
                                <x-icon name="school" class="size-4" /> {{ $row['name'] }} · à la cantine
                            </span>
                        @else
                            <label wire:key="present-{{ $row['key'] }}" class="flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm ring-1 ring-stone-200 has-checked:bg-herb-50 has-checked:ring-herb-300">
                                @if ($row['person_id'])
                                    <input type="checkbox" wire:model.live="presentPersonIds" value="{{ $row['person_id'] }}" class="form-checkbox">
                                @else
                                    <input type="checkbox" wire:model.live="presentUserIds" value="{{ $row['user_id'] }}" class="form-checkbox">
                                @endif
                                @if ($row['hex']) <span class="size-2.5 rounded-full" style="background-color: {{ $row['hex'] }}" aria-hidden="true"></span> @endif
                                {{ $row['name'] }}
                            </label>
                        @endif
                    @endforeach
                </div>
            </fieldset>

            {{-- Invités --}}
            <fieldset class="space-y-2">
                <legend class="form-label">Invités</legend>

                @if ($this->selectedGuests->isNotEmpty())
                    <ul class="flex flex-wrap gap-2">
                        @foreach ($this->selectedGuests as $guest)
                            <li wire:key="sel-guest-{{ $guest->id }}" class="flex items-center gap-1.5 rounded-full bg-stone-100 py-1 pr-1 pl-3 text-sm text-stone-800">
                                {{ $guest->name }}
                                @if ($guest->is_child) <span class="text-xs text-stone-500">(enfant)</span> @endif
                                @if ($guest->restrictions->contains(fn ($r) => $r->type === \App\Enums\RestrictionType::Allergy))
                                    <span class="size-2 rounded-full bg-red-500" title="Allergie"></span>
                                @endif
                                <button type="button" wire:click="removeGuest({{ $guest->id }})" class="rounded-full p-0.5 text-stone-500 hover:bg-stone-200 hover:text-stone-700" title="Retirer {{ $guest->name }}">
                                    <x-icon name="close" class="size-4" />
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="relative">
                    <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" />
                    <input type="search" wire:model.live.debounce.250ms="guestSearch" wire:keydown.enter.prevent="createGuest"
                           placeholder="Chercher ou ajouter un invité…" class="form-input pl-10" aria-label="Chercher un invité" autocomplete="off">
                </div>
                @error('guestSearch') <p class="form-error">{{ $message }}</p> @enderror

                <div class="flex flex-wrap gap-1.5">
                    @foreach ($this->guestResults as $guest)
                        <button type="button" wire:key="res-guest-{{ $guest->id }}" wire:click="addGuest({{ $guest->id }})"
                                class="flex items-center gap-1 rounded-full px-2.5 py-1 text-sm text-stone-700 ring-1 ring-stone-200 hover:bg-brand-50 hover:ring-brand-300">
                            <x-icon name="plus" class="size-3.5" /> {{ $guest->name }}
                            @if ($guest->group_name) <span class="text-xs text-stone-500">· {{ $guest->group_name }}</span> @endif
                        </button>
                    @endforeach
                    @foreach ($this->groups as $group => $count)
                        <button type="button" wire:key="group-{{ $group }}" wire:click="addGroup(@js($group))"
                                class="flex items-center gap-1 rounded-full bg-violet-50 px-2.5 py-1 text-sm text-violet-800 ring-1 ring-violet-200 hover:bg-violet-100">
                            <x-icon name="users" class="size-3.5" /> Tout « {{ $group }} » ({{ $count }})
                        </button>
                    @endforeach
                    @if (trim($guestSearch) !== '' && ! $this->guestResults->contains(fn ($g) => mb_strtolower($g->name) === mb_strtolower(trim($guestSearch))))
                        <button type="button" wire:click="createGuest" class="flex items-center gap-1 rounded-full bg-brand-600 px-2.5 py-1 text-sm text-white hover:bg-brand-700">
                            <x-icon name="plus" class="size-3.5" /> Créer « {{ trim($guestSearch) }} »
                        </button>
                    @endif
                </div>

                <div class="flex flex-wrap gap-4 pt-1">
                    @foreach (['extraAdults' => 'Adultes sans nom', 'extraChildren' => 'Enfants sans nom'] as $field => $label)
                        <div class="flex items-center gap-2 text-sm text-stone-700">
                            <span>{{ $label }}</span>
                            <div class="flex items-center rounded-lg ring-1 ring-stone-200">
                                <button type="button" wire:click="adjust('{{ $field }}', -1)" class="px-2.5 py-1.5 text-stone-500 hover:text-stone-900 disabled:opacity-30" @disabled($this->{$field} === 0) aria-label="Moins"><x-icon name="minus" class="size-4" /></button>
                                <span class="w-6 text-center font-semibold tabular-nums">{{ $this->{$field} }}</span>
                                <button type="button" wire:click="adjust('{{ $field }}', 1)" class="px-2.5 py-1.5 text-stone-500 hover:text-stone-900" aria-label="Plus"><x-icon name="plus" class="size-4" /></button>
                            </div>
                        </div>
                    @endforeach
                </div>
                @error('extraAdults') <p class="form-error">{{ $message }}</p> @enderror
            </fieldset>

            {{-- Contraintes --}}
            @php $restricted = $this->selectedGuests->filter(fn ($g) => $g->restrictions->isNotEmpty()); @endphp
            @if ($restricted->isNotEmpty())
                <div class="rounded-xl bg-stone-50 p-3 text-sm">
                    <p class="mb-2 font-medium text-stone-800">Contraintes alimentaires</p>
                    <ul class="space-y-1">
                        @foreach ($restricted as $guest)
                            <li wire:key="restr-{{ $guest->id }}" class="flex flex-wrap items-center gap-1.5">
                                <span class="font-medium text-stone-700">{{ $guest->name }} :</span>
                                @foreach ($guest->restrictions as $restriction)
                                    <x-badge :color="$restriction->type->color()">{{ $restriction->type->shortLabel() }} · {{ $restriction->subject() }}</x-badge>
                                @endforeach
                            </li>
                        @endforeach
                    </ul>
                    @if ($restricted->contains(fn ($g) => $g->restrictions->contains(fn ($r) => $r->type === \App\Enums\RestrictionType::Allergy)))
                        <p class="mt-2 text-xs text-stone-500">Les alertes ne portent que sur les ingrédients saisis dans les recettes : vérifiez les produits transformés (sauces, bouillons, pesto…).</p>
                    @endif
                </div>
            @endif

            {{-- Plats prévus --}}
            @if ($this->cellMeals->isNotEmpty())
                <div class="text-sm">
                    <p class="form-label">Au menu</p>
                    <ul class="divide-y divide-stone-100 rounded-lg ring-1 ring-stone-200">
                        @foreach ($this->cellMeals as $row)
                            <li wire:key="occ-meal-{{ $row['meal']->id }}" class="px-3 py-2">
                                <p class="flex items-center justify-between gap-3">
                                    <span class="font-medium text-stone-800">{{ $row['meal']->label() }}</span>
                                    @unless ($row['meal']->isFree())
                                        <span class="flex items-center gap-1 text-xs text-stone-500 tabular-nums"><x-icon name="users" class="size-3" />{{ $row['meal']->servings }} portions</span>
                                    @endunless
                                </p>
                                @foreach ($row['conflicts'] as $conflict)
                                    <p @class(['mt-0.5 text-xs', 'text-red-700' => $conflict['level'] === 'danger', 'text-amber-700' => $conflict['level'] !== 'danger'])>⚠ {{ $conflict['message'] }}</p>
                                @endforeach
                            </li>
                        @endforeach
                    </ul>
                    @php $recipeMealIds = $this->cellMeals->filter(fn ($r) => $r['meal']->isRecipe())->pluck('meal.id'); @endphp
                    @if ($recipeMealIds->isNotEmpty())
                        <a href="{{ route('shopping.index', ['generer' => $date, 'repas' => $recipeMealIds->join(',')]) }}" wire:navigate
                           class="mt-2 inline-flex items-center gap-1.5 font-medium text-brand-700 hover:underline">
                            <x-icon name="cart" class="size-4" /> Liste de courses pour ce repas
                        </a>
                    @endif
                    @if ($occasionId = \App\Models\MealOccasion::query()->whereDate('date', $date)->where('meal_slot_id', $slotId)->value('id'))
                        <a href="{{ route('receptions.show', $occasionId) }}" wire:navigate
                           class="mt-2 ml-4 inline-flex items-center gap-1.5 font-medium text-violet-700 hover:underline">
                            <x-icon name="cake" class="size-4" /> Menu et rétroplanning
                        </a>
                    @endif
                </div>
            @endif
        </form>

        <x-slot:footer>
            <button type="button" wire:click="clear" wire:confirm="Revenir au foyer complet, sans invité ?" class="btn btn-ghost mr-auto text-stone-500">Réinitialiser</button>
            <button type="button" wire:click="close" class="btn btn-secondary">Annuler</button>
            <button type="submit" form="occasion-form" class="btn btn-primary">Enregistrer</button>
        </x-slot:footer>
    </x-modal>
</div>
