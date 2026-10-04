<div class="max-w-3xl">
    <a href="{{ route('planner.week') }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> Planning
    </a>
    <x-page-header title="Le choix des enfants" subtitle="Vous proposez trois recettes qui conviennent à tout le monde ; l'enfant choisit sur l'écran de cuisine." />

    {{-- ============================================================ Préparer un choix --}}
    <form wire:submit="propose" class="card space-y-4 p-4 sm:p-5" data-choice-form>
        <div class="grid gap-3 sm:grid-cols-3">
            <x-field label="Jour" for="choice-date" error="date">
                <input id="choice-date" type="date" wire:model.live="date" min="{{ today()->toDateString() }}" class="form-input">
            </x-field>
            <x-field label="Repas" for="choice-slot" error="slotId">
                <select id="choice-slot" wire:model.live="slotId" class="form-input">
                    @foreach ($this->slots as $slot) <option value="{{ $slot->id }}">{{ $slot->name }}</option> @endforeach
                </select>
            </x-field>
            <x-field label="Qui choisit ?" for="choice-person">
                <select id="choice-person" wire:model="personId" class="form-input">
                    <option value="">Les enfants</option>
                    @foreach ($this->children as $child) <option value="{{ $child->id }}">{{ $child->name }}</option> @endforeach
                </select>
            </x-field>
        </div>

        <fieldset class="space-y-2">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <legend class="form-label">Les recettes proposées</legend>
                <button type="button" wire:click="suggest" class="btn btn-ghost text-sm"><x-icon name="sparkles" class="size-4" /> Proposer 3 idées</button>
            </div>
            @foreach ([0, 1, 2] as $index)
                <div wire:key="choice-recipe-{{ $index }}">
                    <label for="choice-recipe-{{ $index }}" class="sr-only">Recette {{ $index + 1 }}</label>
                    <select id="choice-recipe-{{ $index }}" wire:model.live="recipeIds.{{ $index }}" class="form-input">
                        <option value="">{{ $index === 2 ? 'Troisième recette (facultative)' : 'Choisir une recette…' }}</option>
                        @foreach ($this->recipes as $recipe) <option value="{{ $recipe->id }}">{{ $recipe->title }}</option> @endforeach
                    </select>
                    @foreach ($this->problems[$index] ?? [] as $problem)
                        <p class="mt-1 flex items-center gap-1 text-sm text-red-700"><x-icon name="warning" class="size-4 shrink-0" /> {{ $problem }}</p>
                    @endforeach
                </div>
            @endforeach
            @error('recipeIds') <p class="form-error">{{ $message }}</p> @enderror
            <p class="text-xs text-stone-500">Vérifiées pour les goûts et allergies de tous ceux qui mangent ce jour-là (personnes du foyer présentes et invités).</p>
        </fieldset>

        <label class="flex items-start gap-2 text-sm text-stone-700">
            <input type="checkbox" wire:model="allowPlan" class="form-checkbox mt-0.5">
            <span><strong>Inscrire son choix au planning</strong> — il remplace le plat prévu pour ce repas. Sinon, il devient une envie, à placer quand vous voulez.</span>
        </label>

        <div class="flex flex-wrap justify-end gap-2">
            <a href="{{ route('kitchen.choice') }}" wire:navigate class="btn btn-ghost"><x-icon name="smile" class="size-4" /> Écran du choix</a>
            <button type="submit" class="btn btn-primary">Préparer le choix</button>
        </div>
    </form>

    {{-- ============================================================ En attente --}}
    <section class="mt-6 space-y-3" aria-labelledby="open-choices">
        <h2 id="open-choices" class="font-display text-lg font-semibold text-stone-900">À choisir</h2>
        @forelse ($this->openChoices as $choice)
            <article wire:key="open-choice-{{ $choice->id }}" class="card flex flex-wrap items-center gap-3 p-4" data-open-choice>
                <div class="min-w-0 flex-1 basis-60">
                    <p class="font-medium text-stone-900">{{ $choice->person?->name ?? 'Les enfants' }} · {{ ucfirst($choice->dayLabel()) }} {{ $choice->date->locale('fr')->isoFormat('D MMMM') }}</p>
                    <p class="text-sm text-stone-600">{{ $choice->recipes()->pluck('title')->join(' · ') }}</p>
                    <p class="text-xs text-stone-500">{{ $choice->allow_plan ? 'S\'inscrit au planning.' : 'Devient une envie.' }}</p>
                </div>
                <a href="{{ route('kitchen.choice', ['choix' => $choice->id]) }}" wire:navigate class="btn btn-secondary">Ouvrir</a>
                <button type="button" wire:click="cancel({{ $choice->id }})" wire:confirm="Annuler ce choix ?" class="btn btn-ghost text-stone-600">Annuler</button>
            </article>
        @empty
            <p class="text-sm text-stone-500">Aucun choix en attente.</p>
        @endforelse
    </section>

    @if ($this->recentChoices->isNotEmpty())
        <section class="mt-6 space-y-2" aria-labelledby="recent-choices">
            <h2 id="recent-choices" class="font-display text-lg font-semibold text-stone-900">Derniers choix</h2>
            <ul class="card divide-y divide-stone-100">
                @foreach ($this->recentChoices as $choice)
                    <li wire:key="recent-choice-{{ $choice->id }}" class="px-4 py-2.5 text-sm text-stone-700">
                        <strong>{{ $choice->person?->name ?? 'Les enfants' }}</strong> : {{ $choice->chosenRecipe?->title }}
                        <span class="text-stone-500">· {{ $choice->dayLabel() }} {{ $choice->date->locale('fr')->isoFormat('D MMMM') }} · {{ $choice->planned_meal_id ? 'au planning' : 'en envie' }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
