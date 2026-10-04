<div class="mx-auto max-w-5xl">
    <a href="{{ route('planner.week') }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> Planning
    </a>

    @if (! $inSession)
        {{-- ======================================================= Étape 1 : choisir les repas --}}
        <x-page-header title="Cuisiner en avance"
                       subtitle="Choisissez les repas à préparer en une seule session : ingrédients regroupés, plats rangés au réfrigérateur ou au congélateur." />

        @if ($this->candidates->isEmpty())
            <div class="card">
                <x-empty-state icon="calendar" title="Aucun repas à avancer">
                    Il n'y a pas de recette planifiée dans les dix prochains jours qui ne soit ni mangée ni déjà préparée.
                </x-empty-state>
            </div>
        @else
            <form wire:submit="start" class="card overflow-hidden">
                <ul class="divide-y divide-stone-100">
                    @foreach ($this->candidates as $meal)
                        <li wire:key="candidate-{{ $meal->id }}">
                            <label class="flex cursor-pointer items-center gap-3 px-4 py-3 hover:bg-stone-50">
                                <input type="checkbox" wire:model="selected" value="{{ $meal->id }}" class="form-checkbox size-5">
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium text-stone-900">{{ $meal->recipe->title }}</span>
                                    <span class="block text-sm text-stone-500">
                                        {{ ucfirst($meal->date->locale('fr')->isoFormat('dddd D MMMM')) }} · {{ $meal->slot?->name }} · {{ \App\Services\Planning\Appetites::label($meal->servings) }}
                                    </span>
                                </span>
                                @if ($meal->recipe->total_minutes)
                                    <span class="text-sm whitespace-nowrap text-stone-500">{{ \App\Support\Duration::format($meal->recipe->total_minutes) }}</span>
                                @endif
                            </label>
                        </li>
                    @endforeach
                </ul>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-stone-200 bg-stone-50 px-4 py-3">
                    @error('selected') <p class="form-error">{{ $message }}</p> @else <span class="text-sm text-stone-500">Astuce : les plats qui mijotent longtemps se prêtent bien au batch cooking.</span> @enderror
                    <button type="submit" class="btn btn-primary"><x-icon name="fire" class="size-4" /> Commencer la session</button>
                </div>
            </form>
        @endif
    @else
        {{-- ======================================================= Étape 2 : la session --}}
        @php $session = $this->session; @endphp
        <x-page-header title="Session « cuisiner en avance »"
                       :subtitle="$session['recipes']->count().' plat'.($session['recipes']->count() > 1 ? 's' : '').' à préparer'.($session['minutes'] ? ' · environ '.\App\Support\Duration::format($session['minutes']) : '').($session['done']->isNotEmpty() ? ' · '.$session['done']->count().' déjà rangé'.($session['done']->count() > 1 ? 's' : '') : '')">
            <x-slot:actions>
                <button type="button" wire:click="back" class="btn btn-secondary">Changer les repas</button>
            </x-slot:actions>
        </x-page-header>

        <div class="grid gap-6 lg:grid-cols-5">
            {{-- Ingrédients regroupés --}}
            <section class="card h-fit p-5 lg:col-span-2">
                <h2 class="font-display mb-1 text-lg font-semibold text-stone-900">Tout sortir d'un coup</h2>
                <p class="mb-3 text-sm text-stone-500">Ingrédients de tous les plats restant à préparer, additionnés.</p>

                @forelse ($session['texts'] as $aisle => $lines)
                    <h3 class="mt-3 mb-1 text-xs font-semibold tracking-wide text-brand-700 uppercase">{{ $aisle }}</h3>
                    <ul class="space-y-0.5 text-sm text-stone-700">
                        @foreach ($lines as $line)
                            <li>{{ $line['text'] }} @if ($line['optional']) <span class="text-xs text-stone-500">(facultatif)</span> @endif</li>
                        @endforeach
                    </ul>
                @empty
                    <p class="text-sm text-stone-500">Tout est préparé. 🎉</p>
                @endforelse

                <label class="mt-4 flex items-start gap-2 border-t border-stone-200 pt-3 text-sm text-stone-700">
                    <input type="checkbox" wire:model.live="deduct" class="form-checkbox mt-0.5">
                    <span>Retirer les ingrédients du stock en rangeant chaque plat</span>
                </label>
            </section>

            {{-- Recettes dans l'ordre --}}
            <div class="space-y-4 lg:col-span-3">
                @foreach ($session['recipes'] as $row)
                    @php $meal = $row['meal']; @endphp
                    <section wire:key="batch-{{ $meal->id }}" class="card p-5">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold tracking-wide text-stone-500 uppercase">{{ $loop->iteration }}. {{ $row['minutes'] ? \App\Support\Duration::format($row['minutes']) : 'durée non renseignée' }}</p>
                                <h2 class="font-display text-lg font-semibold text-stone-900">
                                    <a href="{{ route('recipes.show', ['recipe' => $meal->recipe, 'portions' => $meal->servings]) }}" wire:navigate class="hover:underline">{{ $meal->recipe->title }}</a>
                                </h2>
                                <p class="text-sm text-stone-500">Pour {{ ucfirst($meal->date->locale('fr')->isoFormat('dddd D MMMM')) }} · {{ \App\Services\Planning\Appetites::label($meal->servings) }} · {{ $row['reason'] }}</p>
                            </div>
                        </div>

                        @if ($meal->recipe->steps->isNotEmpty())
                            <details class="mt-3 text-sm">
                                <summary class="cursor-pointer font-medium text-stone-700">Étapes ({{ $meal->recipe->steps->count() }})</summary>
                                <ol class="mt-2 list-decimal space-y-1.5 pl-5 text-stone-700">
                                    @foreach ($meal->recipe->steps as $step)
                                        <li>{{ $step->instruction }}</li>
                                    @endforeach
                                </ol>
                            </details>
                        @endif

                        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-stone-100 pt-3">
                            <span class="text-sm text-stone-600">Prêt ? Ranger</span>
                            <input type="number" min="0.5" step="0.5" max="50" wire:model="portions.{{ $meal->id }}" class="form-input w-20 py-1.5" aria-label="Portions rangées">
                            <span class="text-sm text-stone-600">portions</span>
                            <select wire:model="storage.{{ $meal->id }}" class="form-input w-auto py-1.5" aria-label="Où ranger">
                                <option value="fridge">au réfrigérateur</option>
                                <option value="freezer">au congélateur</option>
                            </select>
                            <button type="button" wire:click="prepare({{ $meal->id }})" class="btn btn-primary ml-auto">
                                <x-icon name="check" class="size-4" /> C'est prêt
                            </button>
                        </div>
                    </section>
                @endforeach

                @if ($session['done']->isNotEmpty())
                    <section class="card p-5">
                        <h2 class="font-display mb-2 font-semibold text-stone-900">Déjà rangés</h2>
                        <ul class="divide-y divide-stone-100 text-sm">
                            @foreach ($session['done'] as $meal)
                                <li wire:key="done-{{ $meal->id }}" class="flex flex-wrap items-center gap-2 py-2">
                                    <x-icon name="success" class="size-4 text-herb-600" />
                                    <span class="min-w-0 flex-1">
                                        <span class="font-medium text-stone-800">{{ $meal->recipe->title }}</span>
                                        <span class="text-stone-500">· {{ $meal->preparedDish?->location?->name ?? 'stock' }} · pour {{ $meal->date->locale('fr')->isoFormat('dddd D') }}</span>
                                    </span>
                                    <button type="button" wire:click="unprepare({{ $meal->id }})" wire:confirm="Retirer ce plat du stock ? Les ingrédients déjà retirés ne sont pas remis."
                                            class="btn btn-ghost px-2 text-xs">Annuler</button>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </div>
        </div>
    @endif
</div>
