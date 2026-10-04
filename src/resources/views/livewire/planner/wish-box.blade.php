<div class="card p-4">
    <div class="mb-3 flex items-center justify-between gap-2">
        <h2 class="font-display flex items-center gap-2 font-semibold text-stone-900">
            <x-icon name="star" class="size-5 text-amber-500" /> À planifier bientôt
        </h2>
        @if ($this->wishes->isNotEmpty())
            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-900">{{ $this->wishes->count() }}</span>
        @endif
    </div>

    <form wire:submit="add" class="mb-3 flex gap-2">
        <input type="text" wire:model="text" list="wish-recipes" placeholder="Raclette, curry de Julie…"
               aria-label="Nouvelle envie" autocomplete="off"
               @class(['form-input py-1.5 text-sm', 'form-input-error' => $errors->has('text')])>
        <button type="submit" class="btn btn-secondary px-3" title="Ajouter l'envie">
            <x-icon name="plus" class="size-4" /><span class="sr-only">Ajouter</span>
        </button>
    </form>

    <datalist id="wish-recipes">
        @foreach ($this->recipeNames as $name)
            <option value="{{ $name }}"></option>
        @endforeach
    </datalist>

    @error('text') <p class="form-error mb-2">{{ $message }}</p> @enderror

    @if ($this->wishes->isEmpty())
        <p class="text-sm text-stone-500">
            Notez ici ce dont vous avez envie. Le remplissage automatique les placera en priorité.
        </p>
    @else
        <ul class="divide-y divide-stone-100">
            @foreach ($this->wishes as $wish)
                <li wire:key="wish-{{ $wish->id }}" class="flex items-center gap-2 py-2">
                    <div class="min-w-0 flex-1">
                        @if ($wish->isRecipe())
                            <a href="{{ route('recipes.show', $wish->recipe) }}" wire:navigate
                               class="block truncate text-sm font-medium text-stone-900 hover:text-brand-700">{{ $wish->label() }}</a>
                        @else
                            <p class="truncate text-sm font-medium text-stone-900">{{ $wish->label() }}</p>
                        @endif
                        @if (! $compact && $wish->user)
                            <p class="text-xs text-stone-500">noté par {{ $wish->user->name }}</p>
                        @endif
                    </div>

                    <button type="button" wire:click="done({{ $wish->id }})" class="btn btn-ghost px-1.5" title="C'est fait">
                        <x-icon name="check" class="size-4" /><span class="sr-only">Fait</span>
                    </button>
                    <button type="button" wire:click="remove({{ $wish->id }})" class="btn btn-ghost px-1.5 hover:text-red-600" title="Retirer">
                        <x-icon name="close" class="size-4" /><span class="sr-only">Retirer</span>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</div>
