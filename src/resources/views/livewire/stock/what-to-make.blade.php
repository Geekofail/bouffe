<section class="card mt-2 space-y-4 p-4 sm:p-5" data-what-to-make>
    <div>
        <h2 class="font-display text-lg font-semibold text-stone-900">Que faire avec… ?</h2>
        <p class="text-sm text-stone-500">Quelques ingrédients à finir, hors stock ou pas : Bouffe cherche d'abord dans votre carnet.</p>
    </div>

    <form wire:submit="search" class="flex flex-wrap items-end gap-2">
        <x-field label="Ingrédients" for="what-text" error="text" class="min-w-56 flex-1">
            <input id="what-text" type="text" wire:model="text" maxlength="300" placeholder="courgettes, feta et riz" class="form-input">
        </x-field>
        <button type="submit" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="search">
            <x-icon name="search" class="size-4" /> Chercher
        </button>
        @if ($terms !== [])
            <button type="button" wire:click="clear" class="btn btn-ghost">Effacer</button>
        @endif
    </form>

    @if ($terms !== [])
        <div>
            <h3 class="form-label">Dans votre carnet</h3>
            @if ($this->matches->isEmpty())
                <p class="text-sm text-stone-500">Aucune recette du carnet n'utilise {{ count($terms) > 1 ? 'ces ingrédients' : 'cet ingrédient' }}.</p>
            @else
                <ul class="divide-y divide-stone-100">
                    @foreach ($this->matches as $row)
                        <li wire:key="match-{{ $row['recipe']->id }}" class="flex items-center gap-3 py-2">
                            <x-dish-illustration :recipe="$row['recipe']" class="size-10 shrink-0 rounded-xl" />
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('recipes.show', $row['recipe']) }}" wire:navigate class="font-medium text-stone-900 hover:text-brand-700">{{ $row['recipe']->title }}</a>
                                <p class="text-xs text-stone-500">Avec {{ implode(', ', $row['found']) }} ({{ count($row['found']) }}/{{ count($terms) }})</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($this->assistantAvailable)
            <div class="border-t border-stone-200 pt-4">
                @if ($ideas === null)
                    <div class="flex flex-wrap items-center gap-3">
                        <p class="flex-1 text-sm text-stone-600">Envie d'autre chose ? L'assistant propose trois idées. Seule votre liste lui est envoyée.</p>
                        <button type="button" wire:click="askIdeas" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="askIdeas">
                            <x-icon name="sparkles" class="size-4 text-violet-600" />
                            <span wire:loading.remove wire:target="askIdeas">Des idées nouvelles</span>
                            <span wire:loading wire:target="askIdeas">L'assistant réfléchit…</span>
                        </button>
                    </div>
                @else
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <x-assistant-mark text="Idées de l'assistant" />
                        <span class="text-xs text-stone-500">À relire : quantités et temps sont des estimations.</span>
                    </div>
                    <div class="grid gap-3 lg:grid-cols-3">
                        @foreach ($ideas as $i => $idea)
                            <article wire:key="idea-{{ $i }}" class="flex flex-col gap-2 rounded-xl bg-violet-50 p-3 text-sm ring-1 ring-violet-200">
                                <h4 class="font-semibold text-stone-900">{{ $idea['title'] }}</h4>
                                @if ($idea['description'])
                                    <p class="text-stone-600">{{ $idea['description'] }}</p>
                                @endif
                                <p class="text-xs text-stone-500">
                                    {{ $idea['servings'] }} portion{{ $idea['servings'] > 1 ? 's' : '' }}
                                    @if ($idea['prep_minutes'] || $idea['cook_minutes'])
                                        · {{ \App\Support\Duration::format((int) $idea['prep_minutes'] + (int) $idea['cook_minutes']) }}
                                    @endif
                                    · {{ count($idea['lines']) }} ingrédients · {{ count($idea['steps']) }} étapes
                                </p>
                                <details class="text-stone-700">
                                    <summary class="cursor-pointer text-xs font-medium text-violet-800">Ingrédients</summary>
                                    <ul class="mt-1 list-inside list-disc text-xs">
                                        @foreach ($idea['lines'] as $line) <li>{{ $line }}</li> @endforeach
                                    </ul>
                                </details>
                                <button type="button" wire:click="import({{ $i }})" class="btn btn-secondary mt-auto self-start text-sm">
                                    <x-icon name="download" class="size-4" /> Importer comme brouillon
                                </button>
                            </article>
                        @endforeach
                    </div>
                @endif
                @error('assistant') <p class="form-error mt-2">{{ $message }}</p> @enderror
            </div>
        @endif
    @endif
</section>
