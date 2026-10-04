<div class="mx-auto max-w-6xl">
    <a href="{{ route('receptions.index') }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> Réceptions
    </a>

    <x-page-header :title="$name"
                   :subtitle="ucfirst($occasion->serveAt()->locale('fr')->isoFormat('dddd D MMMM YYYY')).' · '.($occasion->slot?->name ?? '').($occasion->serve_time ? ' à '.str_replace(':', ' h ', $occasion->serve_time) : '').' · '.$diners.' à table'">
        <x-slot:actions>
            <a href="{{ route('planner.week', ['semaine' => app(\App\Services\Planning\WeekPlanner::class)->weekStart($occasion->date)->toDateString()]) }}" wire:navigate class="btn btn-secondary">
                <x-icon name="calendar" class="size-4" /> Planning
            </a>
            @if ($canEdit)
                <button type="button" wire:click="editGuests" class="btn btn-secondary"><x-icon name="users" class="size-4" /> Convives</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($occasion->guests->isNotEmpty() || $occasion->extra_adults || $occasion->extra_children || $linkedGuests->where('status', 'accepted')->isNotEmpty())
        <p class="-mt-3 mb-5 text-sm text-stone-600">
            Avec {{ app(\App\Services\Planning\OccasionService::class)->guestSummary($occasion) }}.
        </p>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- ======================================================= Menu (21.1) --}}
            <section class="card p-5">
                <h2 class="font-display mb-3 text-lg font-semibold text-stone-900">Menu</h2>

                @forelse ($this->courses as $group)
                    <h3 class="mt-4 mb-1 text-xs font-semibold tracking-wide text-brand-700 uppercase first:mt-0">{{ $group['label'] }}</h3>
                    <ul class="divide-y divide-stone-100">
                        @foreach ($group['meals'] as $meal)
                            @php $dishConflicts = $this->conflicts[$meal->id] ?? []; @endphp
                            <li wire:key="dish-{{ $meal->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5">
                                <span class="min-w-0 basis-full sm:basis-0 sm:flex-1">
                                    @if ($meal->eatenRecipe())
                                        <a href="{{ route('recipes.show', ['recipe' => $meal->eatenRecipe(), 'repas' => $meal->id]) }}" wire:navigate class="font-medium text-stone-900 hover:underline">{{ $meal->eatenRecipe()->title }}</a>
                                    @else
                                        <span class="font-medium text-stone-900">{{ $meal->label() }}</span>
                                    @endif
                                    @if ($meal->isPrepared()) <x-badge color="green" class="ml-1">préparé à l'avance</x-badge> @endif
                                    @foreach ($dishConflicts as $conflict)
                                        <span @class(['block text-xs', 'text-red-700' => $conflict['level'] === 'danger', 'text-amber-700' => $conflict['level'] !== 'danger'])>⚠ {{ $conflict['guest'] }} : {{ $conflict['message'] }}</span>
                                    @endforeach
                                </span>
                                @if ($canEdit)
                                    <select wire:change="setCourse({{ $meal->id }}, $event.target.value)" class="form-input w-auto py-1 text-sm" aria-label="Place dans le menu">
                                        <option value="" @selected($meal->course === null)>—</option>
                                        @foreach (\App\Enums\Course::ordered() as $course)
                                            <option value="{{ $course->value }}" @selected($meal->course === $course)>{{ $course->label() }}</option>
                                        @endforeach
                                    </select>
                                    @if ($meal->isRecipe())
                                        <label class="flex items-center gap-1 text-sm text-stone-500">
                                            <input type="number" min="0.5" step="0.5" max="50" value="{{ $meal->servings }}" wire:change="setServings({{ $meal->id }}, $event.target.value)" class="form-input w-16 py-1 text-sm" aria-label="Portions">
                                            p.
                                        </label>
                                    @endif
                                    <button type="button" wire:click="removeDish({{ $meal->id }})" wire:confirm="Retirer ce plat du menu (et du planning) ?" class="btn btn-ghost px-2 hover:text-red-600" title="Retirer">
                                        <x-icon name="delete" class="size-4" /><span class="sr-only">Retirer</span>
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @empty
                    <p class="text-sm text-stone-500">Aucun plat pour l'instant. Ajoutez l'apéritif, l'entrée, le plat et le dessert ci-dessous : ils rejoignent la case du planning et la liste de courses.</p>
                @endforelse

                @if ($canEdit)
                    <form wire:submit="addDish" class="mt-4 flex flex-wrap items-end gap-2 border-t border-stone-200 pt-4">
                        <div class="w-32">
                            <label for="new-course" class="form-label">Place</label>
                            <select id="new-course" wire:model="newCourse" class="form-input">
                                @foreach (\App\Enums\Course::ordered() as $course) <option value="{{ $course->value }}">{{ $course->label() }}</option> @endforeach
                            </select>
                        </div>
                        <div class="min-w-48 flex-1">
                            <label for="new-recipe" class="form-label">Recette</label>
                            <select id="new-recipe" wire:model="newRecipeId" class="form-input">
                                <option value="">Choisir…</option>
                                @foreach ($this->recipes as $recipe) <option value="{{ $recipe->id }}">{{ $recipe->title }}</option> @endforeach
                            </select>
                        </div>
                        <div class="w-20">
                            <label for="new-servings" class="form-label">Portions</label>
                            <input id="new-servings" type="number" min="0.5" step="0.5" max="50" wire:model="newServings" class="form-input">
                        </div>
                        <button type="submit" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Ajouter</button>
                        @error('newRecipeId') <p class="form-error w-full">{{ $message }}</p> @enderror
                        @error('newServings') <p class="form-error w-full">{{ $message }}</p> @enderror
                    </form>
                @endif
            </section>

            {{-- ======================================================= Apporté par les proches (26.5) --}}
            @if ($brought->isNotEmpty())
                <section class="card p-5">
                    <h2 class="font-display mb-3 text-lg font-semibold text-stone-900">Apporté par les proches</h2>
                    <ul class="divide-y divide-stone-100 text-sm">
                        @foreach ($brought as $dish)
                            <li wire:key="brought-{{ $dish->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                                <span class="min-w-0 flex-1 text-stone-800">{{ $dish->course?->label() ? $dish->course->label().' : ' : '' }}{{ $dish->label() }}</span>
                                <span class="text-stone-500">{{ $dish->household?->name }} · {{ \App\Services\Planning\Appetites::label($dish->servings) }}</span>
                                @if (($this->conflicts[$dish->id] ?? []) !== [])
                                    <span class="w-full text-xs text-amber-800">{{ collect($this->conflicts[$dish->id])->pluck('message')->join(' · ') }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs text-stone-500">Leurs ingrédients sont dans leur liste de courses, et leur préparation dans leur propre rétroplanning.</p>
                </section>
            @endif

            {{-- ======================================================= Qui apporte quoi (lot 42, 42.2) --}}
            <livewire:contributions.board subject="occasion" :subject-id="$occasion->id" :key="'board-occasion-'.$occasion->id" />

            {{-- ======================================================= Rétroplanning (21.2) --}}
            <section class="card p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-display text-lg font-semibold text-stone-900">Rétroplanning</h2>
                    {{-- Le jour J, étape par étape (31.3). --}}
                    <a href="{{ route('planner.cook', ['date' => $occasion->date->toDateString(), 'slot' => $occasion->meal_slot_id]) }}" wire:navigate class="btn btn-secondary py-1.5 text-sm">
                        <x-icon name="fire" class="size-4" /> Cuisiner le repas
                    </a>
                </div>
                <p class="mb-3 text-sm text-stone-500">
                    Calculé d'après les temps des recettes, l'heure du repas et la place de chaque plat. Heures indicatives.
                    @unless ($occasion->serve_time) <strong class="font-medium text-amber-700">Indiquez l'heure du repas pour des horaires justes.</strong> @endunless
                </p>

                @forelse ($this->timeline as $bucket => $tasks)
                    <h3 class="mt-4 mb-1 text-xs font-semibold tracking-wide text-stone-500 uppercase">{{ \App\Services\Receptions\ReceptionPlanner::BUCKETS[$bucket] }}</h3>
                    <ul class="space-y-1">
                        @foreach ($tasks as $task)
                            <li wire:key="task-{{ md5($task['key']) }}">
                                <label @class(['flex cursor-pointer items-start gap-3 rounded-lg px-2 py-1.5 hover:bg-stone-50', 'opacity-60' => $task['done']])>
                                    <input type="checkbox" wire:click="toggleTask('{{ $task['key'] }}')" @checked($task['done']) class="form-checkbox mt-0.5">
                                    <span class="w-20 shrink-0 text-sm text-stone-500 tabular-nums sm:w-28">
                                        {{ in_array($bucket, ['before', 'eve'], true) ? $task['at']->locale('fr')->isoFormat('ddd D, HH[h]mm') : $task['at']->format('H\hi') }}
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span @class(['block text-stone-800', 'line-through' => $task['done']])>{{ $task['title'] }}</span>
                                        @if ($task['detail']) <span class="block text-xs text-stone-500">{{ $task['detail'] }}</span> @endif
                                    </span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                @empty
                    <p class="text-sm text-stone-500">Le rétroplanning apparaîtra dès qu'un plat sera au menu.</p>
                @endforelse
            </section>
        </div>

        <div class="space-y-6">
            {{-- ======================================================= Foyers reliés invités (26.5, 26.6) --}}
            @if ($linkable->isNotEmpty() || $linkedGuests->isNotEmpty())
                <section class="card p-5">
                    <h2 class="font-display mb-3 font-semibold text-stone-900">Foyers invités</h2>
                    <ul class="space-y-2 text-sm">
                        @foreach ($linkedGuests as $row)
                            <li wire:key="lg-{{ $row->id }}" class="rounded-lg bg-stone-50 p-2.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="min-w-0 flex-1 font-medium text-stone-900">{{ $row->household?->name }}</span>
                                    <span @class(['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1',
                                        'bg-amber-50 text-amber-800 ring-amber-200' => $row->status === 'invited',
                                        'bg-emerald-50 text-emerald-800 ring-emerald-200' => $row->status === 'accepted',
                                        'bg-stone-100 text-stone-700 ring-stone-200' => $row->status === 'declined'])>
                                        <x-icon :name="['invited' => 'clock', 'accepted' => 'check', 'declined' => 'close'][$row->status]" class="size-3.5" />
                                        {{ $row->status === 'invited' ? 'En attente' : \App\Models\MealOccasionHousehold::STATUSES[$row->status] }}{{ $row->isAccepted() && $row->people ? ' · '.$row->people : '' }}
                                    </span>
                                    @if ($canEdit)
                                        <button type="button" wire:click="cancelHousehold({{ $row->household_id }})" wire:confirm="Retirer l'invitation de « {{ $row->household?->name }} » ?" class="text-stone-400 hover:text-red-700" title="Retirer"><x-icon name="close" class="size-4" /><span class="sr-only">Retirer</span></button>
                                    @endif
                                </div>
                                @if ($row->message) <p class="mt-1 text-stone-600 italic">« {{ $row->message }} »</p> @endif
                                @if ($row->isAccepted())
                                    <ul class="mt-1.5 space-y-0.5 text-xs text-stone-600">
                                        @foreach ($linkedEaters->filter(fn ($e) => str_ends_with($e->name, '('.$row->household?->name.')')) as $eater)
                                            <li>
                                                {{ \Illuminate\Support\Str::before($eater->name, ' (') }} :
                                                @if (! $eater->shared) <span class="text-stone-500">contraintes non partagées</span>
                                                @elseif ($eater->all->isEmpty()) aucune contrainte
                                                @else {{ $eater->all->map(fn ($r) => $r->type->label().' : '.$r->subject())->join(', ') }}
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($canEdit && $linkable->whereNotIn('id', $linkedGuests->pluck('household_id'))->isNotEmpty())
                        <form wire:submit="inviteHousehold" class="mt-3 flex flex-wrap gap-2">
                            <select wire:model="inviteHouseholdId" class="form-input min-w-0 flex-1" aria-label="Foyer relié à inviter">
                                <option value="">Inviter un foyer relié…</option>
                                @foreach ($linkable->whereNotIn('id', $linkedGuests->pluck('household_id')) as $h)
                                    <option value="{{ $h->id }}">{{ $h->name }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-secondary">Inviter</button>
                            @error('inviteHouseholdId') <p class="form-error w-full">{{ $message }}</p> @enderror
                        </form>
                    @endif
                </section>
            @endif

            {{-- ======================================================= Heure --}}
            <section class="card p-5">
                <h2 class="font-display mb-3 font-semibold text-stone-900">Heure du repas</h2>
                @if ($canEdit)
                    <form wire:submit="saveTime" class="flex items-start gap-2">
                        <div class="flex-1">
                            <input type="time" wire:model="serveTime" class="form-input" aria-label="Heure du repas">
                            @error('serveTime') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn btn-secondary">Enregistrer</button>
                    </form>
                    <p class="mt-2 text-xs text-stone-500">Arrivée des invités. Les rappels de préparation (cloche, téléphone) en tiennent compte.</p>
                @else
                    <p class="text-stone-700">{{ $occasion->serve_time ? str_replace(':', ' h ', $occasion->serve_time) : 'Non précisée' }}</p>
                @endif
            </section>

            {{-- ======================================================= Carte de menu (21.3) --}}
            <section class="card p-5" x-data="{ copied: false }">
                <h2 class="font-display mb-3 font-semibold text-stone-900">Carte de menu</h2>
                @if ($canEdit)
                    <form wire:submit="saveMessage" class="space-y-2">
                        <textarea wire:model="menuMessage" rows="2" maxlength="255" placeholder="Un mot pour vos invités (facultatif)" class="form-input text-sm" aria-label="Mot sur la carte"></textarea>
                        <button type="submit" class="btn btn-secondary w-full py-1.5 text-sm">Enregistrer le mot</button>
                    </form>
                @endif
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <a href="{{ route('receptions.menu', $occasion) }}" target="_blank" class="btn btn-secondary"><x-icon name="printer" class="size-4" /> Imprimer</a>
                    <button type="button" class="btn btn-secondary"
                            x-on:click="
                                const text = @js($menuText);
                                if (navigator.share) { navigator.share({ title: @js($name), text }).catch(() => {}); }
                                else { navigator.clipboard.writeText(text).then(() => { copied = true; setTimeout(() => copied = false, 2000) }); }
                            ">
                        <x-icon name="share" class="size-4" /> <span x-text="copied ? 'Copié !' : 'Partager'">Partager</span>
                    </button>
                </div>
            </section>

            {{-- ======================================================= Souvenir (21.4) --}}
            <section class="card p-5">
                <h2 class="font-display mb-1 font-semibold text-stone-900">Souvenir</h2>
                <p class="mb-3 text-xs text-stone-500">{{ $isPast ? 'Une photo et quelques mots : ils apparaîtront sur la fiche de chaque invité.' : 'À remplir après le repas : une photo, quelques mots.' }}</p>

                @if ($url = $receptions->photoUrl($occasion, 'large'))
                    <div class="relative mb-3">
                        <img src="{{ $url }}" alt="Photo de {{ $name }}" class="w-full rounded-lg object-cover">
                        <button type="button" wire:click="removePhoto" wire:confirm="Supprimer la photo ?" class="absolute top-2 right-2 rounded-full bg-white/90 p-1.5 text-stone-600 shadow hover:text-red-600" title="Supprimer la photo">
                            <x-icon name="delete" class="size-4" /><span class="sr-only">Supprimer la photo</span>
                        </button>
                    </div>
                @endif

                <form wire:submit="saveMemory" class="space-y-2">
                    <textarea wire:model="memoryNote" rows="3" maxlength="2000" placeholder="ex. Julie a adoré le fondant, le rôti manquait de sel." class="form-input text-sm" aria-label="Note"></textarea>
                    <input type="file" wire:model="photo" accept="image/*" class="block w-full text-sm text-stone-600 file:mr-3 file:rounded-md file:border-0 file:bg-stone-100 file:px-3 file:py-1.5 file:text-sm file:font-medium" aria-label="Photo">
                    @error('photo') <p class="form-error">{{ $message }}</p> @enderror
                    <button type="submit" class="btn btn-secondary w-full py-1.5 text-sm" wire:loading.attr="disabled" wire:target="photo,saveMemory">Enregistrer le souvenir</button>
                </form>
            </section>
        </div>
    </div>

    <livewire:planner.occasion-editor />
</div>
