<div>
    <x-page-header :title="'Planning — '.$household->name" :subtitle="$writable ? 'Ouvert en lecture et en écriture : vous pouvez y ajouter ou retirer un repas.' : 'Ouvert en lecture.'" />
    <x-linked-nav />

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <button type="button" wire:click="previousWeek" class="btn btn-secondary px-2.5" title="Semaine précédente"><x-icon name="chevron-left" class="size-4" /><span class="sr-only">Semaine précédente</span></button>
        <button type="button" wire:click="thisWeek" class="btn btn-secondary px-3">Cette semaine</button>
        <button type="button" wire:click="nextWeek" class="btn btn-secondary px-2.5" title="Semaine suivante"><x-icon name="chevron-right" class="size-4" /><span class="sr-only">Semaine suivante</span></button>
        <span class="ml-1 font-medium text-stone-700">Semaine du {{ $weekStart->locale('fr')->isoFormat('D MMMM') }}</span>
        <div class="ml-auto" x-data="{ open: false, copied: false }">
            <button type="button" class="btn btn-ghost text-sm" x-on:click="open = ! open"><x-icon name="calendar" class="size-4" /> Dans mon agenda</button>
            <div x-show="open" x-cloak class="mt-2 w-full max-w-md rounded-lg bg-stone-50 p-3 text-sm sm:absolute sm:right-8 sm:z-20 sm:w-96 sm:shadow-lg sm:ring-1 sm:ring-stone-200">
                <p class="mb-2 text-stone-600">Adresse d'abonnement pour l'agenda du téléphone (personnelle, à garder pour vous) :</p>
                <div class="flex gap-2">
                    <input type="text" readonly value="{{ $calendarUrl }}" class="form-input min-w-0 flex-1 font-mono text-xs" x-on:focus="$el.select()" aria-label="Adresse de l'agenda">
                    <button type="button" class="btn btn-secondary" x-on:click="navigator.clipboard?.writeText(@js($calendarUrl)); copied = true"><span x-text="copied ? 'Copiée' : 'Copier'">Copier</span></button>
                </div>
            </div>
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" wire:loading.class="opacity-70" wire:target="previousWeek,nextWeek,thisWeek">
        @foreach ($days as $day)
            <section wire:key="day-{{ $day->toDateString() }}" @class(['card p-3', 'ring-2 ring-brand-300' => $day->isToday()])>
                <h2 class="font-display mb-2 font-semibold text-stone-900">{{ ucfirst($day->locale('fr')->isoFormat('dddd D')) }}</h2>
                <div class="space-y-2">
                    @foreach ($mealSlots as $slot)
                        @php $cell = $meals->get($day->toDateString().'|'.$slot->id, collect()); @endphp
                        <div wire:key="cell-{{ $day->toDateString() }}-{{ $slot->id }}">
                            <p class="flex items-center justify-between text-xs font-semibold tracking-wide text-stone-500 uppercase">
                                {{ $slot->name }}
                                @if ($writable)
                                    <button type="button" wire:click="openAdd('{{ $day->toDateString() }}', {{ $slot->id }})" class="rounded p-0.5 text-stone-500 hover:bg-stone-100 hover:text-brand-700" title="Ajouter un repas">
                                        <x-icon name="plus" class="size-4" /><span class="sr-only">Ajouter un repas le {{ $day->locale('fr')->isoFormat('dddd D') }}, {{ $slot->name }}</span>
                                    </button>
                                @endif
                            </p>
                            @forelse ($cell as $meal)
                                <p wire:key="m-{{ $meal->id }}" class="flex items-start gap-1 text-sm text-stone-800">
                                    <span class="min-w-0 flex-1">{{ $meal->label() }}</span>
                                    @if ($writable)
                                        <button type="button" wire:click="remove({{ $meal->id }})" wire:confirm="Retirer « {{ $meal->label() }} » de leur planning ?" class="shrink-0 text-stone-400 hover:text-red-700" title="Retirer"><x-icon name="close" class="size-4" /><span class="sr-only">Retirer</span></button>
                                    @endif
                                </p>
                            @empty
                                <p class="text-sm text-stone-500">—</p>
                            @endforelse

                            @if ($addDate === $day->toDateString() && $addSlotId === $slot->id)
                                <form wire:submit="add" class="mt-2 space-y-2 rounded-lg bg-stone-50 p-2">
                                    <select wire:model="addRecipeId" class="form-input text-sm" aria-label="Recette de leur carnet">
                                        <option value="">— Texte libre —</option>
                                        @foreach ($recipes as $recipe)
                                            <option value="{{ $recipe->id }}">{{ $recipe->title }}</option>
                                        @endforeach
                                    </select>
                                    <input type="text" wire:model="addText" maxlength="200" class="form-input text-sm" placeholder="Ou : « Pâtes au beurre »" aria-label="Texte libre">
                                    @error('addText') <p class="form-error">{{ $message }}</p> @enderror
                                    <div class="flex gap-2">
                                        <button type="submit" class="btn btn-primary py-1 text-sm">Ajouter</button>
                                        <button type="button" wire:click="closeAdd" class="btn btn-ghost py-1 text-sm">Annuler</button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</div>
