<div>
    <x-modal :show="$show" :title="$guestId ? 'Modifier l\'invité' : 'Nouvel invité'" close="close" max-width="max-w-xl">
        <form id="guest-form" wire:submit="save" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Nom" for="guest-name" error="name">
                    <input id="guest-name" type="text" wire:model="name" maxlength="100" placeholder="ex. Julie" class="form-input" autofocus>
                </x-field>
                <x-field label="Groupe" for="guest-group" error="groupName" optional help="Pour ajouter toute la famille d'un coup.">
                    <datalist id="guest-groups">
                        @foreach ($this->groupNames as $group) <option value="{{ $group }}"></option> @endforeach
                        @foreach (['Famille', 'Amis', 'Collègues', 'Voisins'] as $group)
                            @unless (in_array($group, $this->groupNames, true)) <option value="{{ $group }}"></option> @endunless
                        @endforeach
                    </datalist>
                    <input id="guest-group" type="text" wire:model="groupName" list="guest-groups" maxlength="50" placeholder="ex. Famille" class="form-input" autocomplete="off">
                </x-field>
            </div>

            @php $appetites = app(\App\Services\Planning\Appetites::class); @endphp
            <div class="grid gap-4 sm:grid-cols-2 sm:items-end">
                <label class="flex min-h-10 items-center gap-2 text-sm text-stone-700">
                    <input type="checkbox" wire:model.live="isChild" class="form-checkbox">
                    Enfant
                </label>
                <x-field label="Appétit" for="guest-appetite" error="appetite" help="Compte pour une part dans les portions du repas.">
                    <select id="guest-appetite" wire:model="appetite" class="form-input">
                        <option value="">Selon l'âge ({{ $isChild ? 'petit' : 'normal' }} · {{ \App\Services\Planning\Appetites::formatPart($appetites->part($isChild ? 'petit' : 'normal')) }})</option>
                        @foreach (\App\Services\Planning\Appetites::LEVELS as $level => [$label, $hint])
                            <option value="{{ $level }}">{{ $label }} — {{ $hint }} ({{ \App\Services\Planning\Appetites::formatPart($appetites->part($level)) }})</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            {{-- Contraintes --}}
            <fieldset class="space-y-2">
                <legend class="form-label">Contraintes alimentaires</legend>

                <datalist id="guest-ingredients">
                    @foreach ($this->ingredientNames as $ingredientName) <option value="{{ $ingredientName }}"></option> @endforeach
                </datalist>

                @foreach ($restrictions as $i => $restriction)
                    @php $type = \App\Enums\RestrictionType::from($restriction['type']); @endphp
                    <div wire:key="restriction-{{ $i }}" class="flex flex-wrap items-center gap-2 rounded-lg bg-stone-50 p-2">
                        <x-badge :color="$type->color()" class="shrink-0">{{ $type->shortLabel() }}</x-badge>
                        @if ($type->usesIngredient())
                            <input type="text" wire:model="restrictions.{{ $i }}.ingredient" list="guest-ingredients" placeholder="Ingrédient (ex. noix)"
                                   class="form-input min-w-36 flex-1 py-1.5" aria-label="Ingrédient" autocomplete="off">
                        @else
                            <select wire:model="restrictions.{{ $i }}.tag_id" class="form-input min-w-36 flex-1 py-1.5" aria-label="Catégorie requise">
                                <option value="">Catégorie de recette…</option>
                                @foreach ($this->tags as $tag) <option value="{{ $tag->id }}">{{ $tag->name }}</option> @endforeach
                            </select>
                        @endif
                        <input type="text" wire:model="restrictions.{{ $i }}.note" maxlength="150" placeholder="Précision" class="form-input w-full py-1.5 sm:w-36" aria-label="Précision">
                        <button type="button" wire:click="removeRestriction({{ $i }})" class="btn btn-ghost px-1.5 hover:text-red-600" title="Retirer">
                            <x-icon name="close" class="size-4" /><span class="sr-only">Retirer</span>
                        </button>
                    </div>
                @endforeach

                <div class="flex flex-wrap gap-2">
                    @foreach (\App\Enums\RestrictionType::cases() as $type)
                        <button type="button" wire:click="addRestriction('{{ $type->value }}')" class="flex items-center gap-1 rounded-full px-2.5 py-1 text-sm text-stone-700 ring-1 ring-stone-200 hover:bg-stone-50">
                            <x-icon name="plus" class="size-3.5" /> {{ $type->label() }}
                        </button>
                    @endforeach
                </div>
                @error('restrictions') <p class="form-error">{{ $message }}</p> @enderror
                <p class="text-xs text-stone-500">Allergie : alerte rouge · N'aime pas : alerte orange · Régime : la recette doit avoir la catégorie (ex. Végétarien). Un ingrédient absent de la liste est créé automatiquement.</p>
            </fieldset>

            <x-field label="Notes" for="guest-notes" error="notes" optional>
                <textarea id="guest-notes" wire:model="notes" rows="2" placeholder="ex. aime le vin rouge, ne mange pas épicé" class="form-input"></textarea>
            </x-field>
        </form>

        <x-slot:footer>
            <button type="button" wire:click="close" class="btn btn-secondary">Annuler</button>
            <button type="submit" form="guest-form" class="btn btn-primary">Enregistrer</button>
        </x-slot:footer>
    </x-modal>
</div>
