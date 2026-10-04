{{-- Lot 38 (38.4) : « Mains libres » — l'étape lue à voix haute, un toucher n'importe où pour la suivante. --}}
<div class="text-lg" x-data="bouffeCookMode()" x-on:click="tap($event)"
     x-on:keydown.right.window="$wire.next()" x-on:keydown.left.window="$wire.previous()">

    {{-- ============================================================ En-tête --}}
    <div class="mb-4 flex items-center gap-3">
        <a href="{{ route('recipes.show', ['recipe' => $recipe, 'portions' => $servings, 'repas' => $mealId ?: null]) }}" wire:navigate
           class="btn btn-ghost px-2 text-base" title="Quitter le mode cuisine">
            <x-icon name="close" class="size-6" /><span class="sr-only">Quitter</span>
        </a>
        <div class="min-w-0 flex-1">
            <p class="truncate text-base font-semibold text-stone-900">{{ $recipe->title }}</p>
            <p class="text-sm text-stone-500">
                {{ \App\Services\Planning\Appetites::label($servings) }}
                @if ($this->meal) · {{ $this->meal->date->locale('fr')->isoFormat('ddd D MMM') }} · {{ mb_strtolower($this->meal->slot->name) }} @endif
            </p>
        </div>
        <div class="flex items-center gap-1 rounded-full bg-stone-100 p-1">
            <button type="button" wire:click="changeServings(-1)" class="rounded-full bg-white p-1.5 shadow-sm disabled:opacity-40" @disabled($servings <= 0.5) title="Moins de portions"><x-icon name="minus" class="size-4" /></button>
            <span class="min-w-8 text-center text-sm font-semibold tabular-nums">{{ \App\Services\Planning\Appetites::format($servings) }}</span>
            <button type="button" wire:click="changeServings(1)" class="rounded-full bg-white p-1.5 shadow-sm" title="Plus de portions"><x-icon name="plus" class="size-4" /></button>
        </div>
    </div>

    {{-- Progression --}}
    <div class="mb-4 flex items-center gap-1" aria-label="Progression">
        @foreach (range(0, $this->lastStep()) as $i)
            <button type="button" wire:click="goTo({{ $i }})"
                    @class(['h-1.5 flex-1 rounded-full transition', 'bg-brand-600' => $i <= $step, 'bg-stone-200' => $i > $step])
                    title="{{ $i === 0 ? 'Mise en place' : ($i === $this->lastStep() ? 'Fin' : 'Étape '.$i) }}"></button>
        @endforeach
    </div>

    {{-- ============================================================ Minuteurs --}}
    {{-- Lot 41 (41.1) : les minuteurs de toute la maison, arrêtables d'ici. --}}
    <x-timers :source="'recette:'.$recipe->id" class="mb-4 space-y-2" />

    @if ($step === 0)
        {{-- ============================================================ Mise en place --}}
        @php
            $readLines = $this->lines->map(fn ($l) => trim($l['quantity'].' '.$l['name']))->take(15)->join(', ');
        @endphp
        <section class="card p-5" wire:key="step-0">
            <span hidden data-speak="{{ 'Mise en place. Il vous faut : '.$readLines.'.' }}" x-init="stepShown($el.dataset.speak)"></span>
            <h1 class="mb-1 text-2xl font-bold text-stone-900">Mise en place</h1>
            <p class="mb-4 text-base text-stone-500">Sortez et préparez les ingrédients : cochez au fur et à mesure.</p>

            @if ($this->notes->isNotEmpty())
                <div class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-base text-amber-900 ring-1 ring-amber-100">
                    <p class="mb-1 font-semibold">La dernière fois</p>
                    @foreach ($this->notes as $note)
                        <p wire:key="cook-note-{{ $note->id }}">« {{ $note->note }} » <span class="text-sm text-amber-700">— {{ $note->user?->name }}, {{ $note->created_at->locale('fr')->isoFormat('D MMM') }}</span></p>
                    @endforeach
                </div>
            @endif

            @php $groups = $this->lines->groupBy('group'); @endphp
            @forelse ($groups as $group => $lines)
                @if ($group !== '')
                    <h2 class="mt-4 mb-1 text-sm font-semibold tracking-wide text-brand-700 uppercase">{{ $group }}</h2>
                @endif
                <ul class="divide-y divide-stone-100">
                    @foreach ($lines as $line)
                        <li wire:key="cook-line-{{ $line['id'] }}">
                            <label class="flex cursor-pointer items-start gap-3 py-3">
                                <input type="checkbox" wire:click="toggleLine('{{ $line['id'] }}')" @checked(in_array((string) $line['id'], array_map('strval', $checked), true)) class="form-checkbox mt-1 size-5">
                                <span @class(['flex-1', 'text-stone-500 line-through' => in_array((string) $line['id'], array_map('strval', $checked), true)])>
                                    <span class="font-semibold tabular-nums">{{ $line['quantity'] }}</span>
                                    {{ $line['name'] }}@if ($line['preparation'])<span class="text-stone-500">, {{ $line['preparation'] }}</span>@endif
                                    @if ($line['optional']) <span class="text-sm text-stone-500">(facultatif)</span> @endif
                                    @if ($line['steps'] !== []) <span class="text-sm text-stone-500">· étape{{ count($line['steps']) > 1 ? 's' : '' }} {{ implode(', ', $line['steps']) }}</span> @endif
                                </span>
                            </label>
                            {{-- Lot 40 (40.1, R43) : « pas de crème ? » — proposé, jamais appliqué à la recette. Ce qui est en stock d'abord, le reste replié. --}}
                            @if ($line['ingredient_id'] && ($subs = $this->substitutes->get((int) $line['ingredient_id'])))
                                @php
                                    $missingName = mb_strtolower($line['ingredient_name']);
                                    $question = 'Pas '.(preg_match('/^[aeiouyhàâéèêîôœ]/u', $missingName) ? 'd\'' : 'de ').$missingName.' ?';
                                    $inStock = collect($subs)->where('in_stock', true);
                                    $others = collect($subs)->where('in_stock', false);
                                @endphp
                                <div class="-mt-1 mb-2 ml-8 space-y-1 text-sm" data-substitutes>
                                    @if ($inStock->isNotEmpty())
                                        <p class="text-stone-500">{{ $question }}</p>
                                        @foreach ($inStock as $sub)
                                            @include('livewire.recipes.cook-substitute', ['sub' => $sub, 'line' => $line])
                                        @endforeach
                                    @endif
                                    @if ($others->isNotEmpty())
                                        <details>
                                            <summary class="cursor-pointer text-stone-500">{{ $inStock->isNotEmpty() ? 'Autres remplacements' : $question }}</summary>
                                            <div class="mt-1 space-y-1">
                                                @foreach ($others as $sub)
                                                    @include('livewire.recipes.cook-substitute', ['sub' => $sub, 'line' => $line])
                                                @endforeach
                                            </div>
                                        </details>
                                    @endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @empty
                <p class="text-base text-stone-500">Aucun ingrédient renseigné.</p>
            @endforelse
        </section>
    @elseif ($step <= $this->steps->count())
        {{-- ============================================================ Étape --}}
        @php $current = $this->steps[$step - 1]; @endphp
        <section class="card p-5" wire:key="step-{{ $step }}">
            <div class="mb-3 flex items-center gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-600 text-lg font-bold text-white">{{ $current['number'] }}</span>
                <h1 class="text-xl font-semibold text-stone-900">
                    Étape {{ $current['number'] }} <span class="font-normal text-stone-500">/ {{ $this->steps->count() }}</span>
                    @if ($current['group'] ?? null)
                        <span class="block text-sm font-medium tracking-wide text-stone-500 uppercase">{{ $current['group'] }}</span>
                    @endif
                </h1>
                <label class="ml-auto flex items-center gap-2 text-sm text-stone-500">
                    <input type="checkbox" wire:click="toggleStep({{ $step }})" @checked(in_array($step, $done, true)) class="form-checkbox size-5"> faite
                </label>
            </div>

            {{-- Lot 39 (39.4) : recette « facile avec un enfant », étape pour un adulte. --}}
            @if ($recipe->kid_friendly)
                <div class="mb-3 flex flex-wrap items-center gap-2" data-adult-step="{{ $current['adult'] ? 1 : 0 }}">
                    @if ($current['adult'])
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1 text-sm font-medium text-amber-900 ring-1 ring-amber-200">
                            <x-icon name="hand" class="size-4" /> Avec un adulte{{ $current['adult_reason'] ? ' · '.$current['adult_reason'] : '' }}
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-herb-50 px-3 py-1 text-sm font-medium text-herb-800 ring-1 ring-herb-200">
                            <x-icon name="smile" class="size-4" /> L'enfant peut le faire
                        </span>
                    @endif
                    @if (auth()->user()->canEdit())
                        <button type="button" wire:click="correctAdult({{ $current['number'] }})" class="min-h-10 text-sm text-stone-500 underline hover:text-stone-800">
                            {{ $current['adult'] ? 'Pas besoin d\'un adulte' : 'Demande un adulte' }}
                        </button>
                    @endif
                </div>
            @endif

            <p class="text-xl leading-relaxed whitespace-pre-line text-stone-800" data-speak="{{ 'Étape '.$current['number'].'. '.($current['adult'] ? 'Avec un adulte. ' : '').$current['text'] }}" x-init="stepShown($el.dataset.speak)">{{ $current['text'] }}</p>

            {{-- Lot 40 (40.2) : les notes du foyer sur cette étape. --}}
            @if ($current['notes']->isNotEmpty() || auth()->user()->canEdit())
                <div class="mt-4 space-y-2" data-step-notes x-data="{ adding: false }">
                    @foreach ($current['notes'] as $stepNote)
                        <p wire:key="step-note-{{ $stepNote->id }}" class="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-base text-amber-900 ring-1 ring-amber-100">
                            <x-icon name="note" class="mt-0.5 size-5 shrink-0 text-amber-700" />
                            <span class="flex-1">{{ $stepNote->note }} <span class="text-sm text-amber-800">— {{ $stepNote->user?->name ?? 'le foyer' }}</span></span>
                            @if (auth()->user()->canEdit())
                                <button type="button" wire:click="deleteStepNote({{ $stepNote->id }})" wire:confirm="Retirer cette note ?" class="rounded p-1 text-amber-700 hover:bg-amber-100" title="Retirer la note"><x-icon name="close" class="size-4" /><span class="sr-only">Retirer la note</span></button>
                            @endif
                        </p>
                    @endforeach
                    @if (auth()->user()->canEdit())
                        <button type="button" x-show="! adding" x-on:click="adding = true; $nextTick(() => $refs.note.focus())" class="min-h-10 text-sm font-medium text-stone-600 underline hover:text-stone-900">
                            Ajouter une note à cette étape
                        </button>
                        <form x-show="adding" x-cloak wire:submit="addStepNote({{ $current['number'] }})" x-on:submit="adding = false" class="flex flex-wrap gap-2">
                            <label for="step-note-input" class="sr-only">Note sur l'étape {{ $current['number'] }}</label>
                            <input id="step-note-input" x-ref="note" type="text" wire:model="stepNote" maxlength="500" placeholder="ex. notre four chauffe fort : 170 °C" class="form-input min-w-0 flex-1 basis-56">
                            <button type="submit" class="btn btn-secondary">Ajouter</button>
                        </form>
                        @error('stepNote') <p class="form-error">{{ $message }}</p> @enderror
                    @endif
                </div>
            @endif

            @if ($current['photos']->isNotEmpty())
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($current['photos'] as $photo)
                        <figure wire:key="cook-photo-{{ $photo->id }}">
                            <img src="{{ $photo->url('large') }}" alt="{{ $photo->caption ?: 'Photo de l\'étape '.$current['number'] }}" class="max-h-64 rounded-xl object-cover">
                            @if ($photo->caption) <figcaption class="mt-1 text-sm text-stone-500">{{ $photo->caption }}</figcaption> @endif
                        </figure>
                    @endforeach
                </div>
            @endif

            @if ($current['timers'] !== [])
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($current['timers'] as $timer)
                        <button type="button" x-data data-step-timer
                                x-on:click="window.dispatchEvent(new CustomEvent('bouffe-timer', { detail: { minutes: {{ $timer['minutes'] }}, label: @js($recipe->title.' · étape '.$current['number'].' · '.$timer['label']) } }))"
                                class="btn btn-secondary text-base">
                            <x-icon name="clock" class="size-5" /> Minuteur {{ $timer['label'] }}
                        </button>
                    @endforeach
                </div>
            @endif

            @if ($current['lines']->isNotEmpty())
                <div class="mt-4 rounded-lg bg-stone-50 px-4 py-3">
                    <p class="mb-1 text-sm font-semibold tracking-wide text-stone-500 uppercase">Pour cette étape</p>
                    <ul class="text-base text-stone-700">
                        @foreach ($current['lines'] as $line)
                            <li wire:key="step-line-{{ $line['id'] }}"><span class="font-semibold tabular-nums">{{ $line['quantity'] }}</span> {{ $line['name'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>

        {{-- 33.4 · une question à l'assistant, jamais enregistrée. --}}
        @if ($this->assistantAvailable)
            <livewire:recipes.cook-question :recipe="$recipe" :step="$step" wire:key="cook-question-{{ $step }}" />
        @endif

        @if ($step < $this->steps->count())
            <p class="mt-3 px-1 text-base text-stone-500">
                <span class="font-medium text-stone-500">Ensuite :</span> {{ \Illuminate\Support\Str::limit($this->steps[$step]['text'], 90) }}
            </p>
        @endif
    @else
        {{-- ============================================================ Fin --}}
        <section class="card p-5" wire:key="step-end">
            <span hidden data-speak="C'est prêt. Bon appétit !" x-init="stepShown($el.dataset.speak)"></span>
            <h1 class="mb-1 text-2xl font-bold text-stone-900">C'est prêt !</h1>
            <p class="mb-4 text-base text-stone-500">Bon appétit. Deux choses avant de refermer :</p>

            <div class="space-y-4">
                @if ($this->meal)
                    <div class="flex flex-wrap items-center gap-3 rounded-lg bg-herb-50 px-4 py-3 ring-1 ring-herb-100">
                        <x-icon name="success" class="size-6 shrink-0 text-herb-600" />
                        <span class="min-w-0 flex-1 text-base text-herb-900">
                            {{ $this->meal->cooked_at ? 'Repas marqué comme mangé : le stock a été proposé à la mise à jour.' : 'Marquer le repas comme mangé (et mettre le stock à jour).' }}
                        </span>
                        @unless ($this->meal->cooked_at)
                            <button type="button" wire:click="markCooked" class="btn btn-primary text-base">Mangé ✓</button>
                        @endunless
                    </div>
                @endif

                <form wire:submit="saveNote" class="space-y-2">
                    <label for="cook-note" class="form-label text-base">Note de cuisine <span class="font-normal text-stone-500">— ce qu'il faudra changer la prochaine fois</span></label>
                    <textarea id="cook-note" wire:model="note" rows="2" maxlength="500" placeholder="Trop salé · +10 min de cuisson · doubler la sauce"
                              @class(['form-input text-base', 'form-input-error' => $errors->has('note')])></textarea>
                    @error('note') <p class="form-error">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="submit" class="btn btn-secondary text-base">Enregistrer la note</button>
                        @if ($saved) <span class="text-base text-herb-700">Note enregistrée ✓</span> @endif
                    </div>
                </form>

                <div class="flex flex-wrap gap-2 border-t border-stone-100 pt-4">
                    <a href="{{ route('recipes.show', ['recipe' => $recipe, 'portions' => $servings]) }}" wire:navigate class="btn btn-primary text-base">Retour à la recette</a>
                    <a href="{{ route('dashboard') }}" wire:navigate class="btn btn-secondary text-base">Accueil</a>
                </div>
            </div>
        </section>
    @endif

    {{-- ============================================================ Barre de navigation --}}
    <div class="fixed inset-x-0 bottom-0 border-t border-stone-200 bg-white/95 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur">
        <div class="mx-auto flex max-w-3xl items-center gap-3">
            <button type="button" wire:click="previous" class="btn btn-secondary text-base" @disabled($step === 0)>
                <x-icon name="chevron-left" class="size-5" /> <span class="sr-only sm:not-sr-only">Précédent</span>
            </button>
            <div class="flex min-w-0 flex-1 flex-col items-center gap-0.5">
                <button type="button" x-show="canSpeak" x-cloak x-on:click.stop="toggle()" x-bind:aria-pressed="handsFree"
                        class="btn min-h-11 px-3 text-sm" x-bind:class="handsFree ? 'btn-primary' : 'btn-secondary'" data-hands-free>
                    <x-icon name="phone" class="size-4" /> <span x-text="handsFree ? (listening ? 'Mains libres · j\'écoute' : 'Mains libres') : 'Lire à voix haute'"></span>
                </button>
                <p class="max-w-full truncate text-center text-xs text-stone-500">
                    <span x-show="handsFree" x-cloak>Touchez l'écran pour l'étape suivante<span x-show="canListen"> · dites « suivant », « répète », « minuteur »</span></span>
                    <span x-show="! handsFree && wakeSupported" x-cloak>Écran maintenu allumé</span>
                    <span x-show="! handsFree && ! wakeSupported" x-cloak>Écran non maintenu allumé (demande HTTPS)</span>
                </p>
            </div>
            <button type="button" wire:click="next" class="btn btn-primary text-base" @disabled($step >= $this->lastStep())>
                <span class="sr-only sm:not-sr-only">{{ $step === 0 ? 'Commencer' : ($step === $this->steps->count() ? 'Terminer' : 'Suivant') }}</span>
                <x-icon name="chevron-right" class="size-5" />
            </button>
        </div>
    </div>

    <livewire:stock.meal-stock-dialog />
</div>
