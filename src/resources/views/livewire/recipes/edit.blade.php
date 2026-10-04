<div>
    <a href="{{ $form->recipe ? route('recipes.show', $form->recipe) : route('recipes.index') }}" wire:navigate
       class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> {{ $form->recipe ? 'Retour à la recette' : 'Recettes' }}
    </a>

    <x-page-header :title="$form->recipe ? 'Modifier la recette' : 'Nouvelle recette'" />

    <form wire:submit="save" class="space-y-6 pb-24">
        {{-- ============================================================ Informations --}}
        <section class="card space-y-4 p-4 sm:p-6">
            <h2 class="font-display text-lg font-semibold text-stone-900">Informations</h2>

            <x-field label="Titre" for="title" error="form.title">
                <input id="title" type="text" wire:model="form.title" placeholder="ex. Gratin dauphinois"
                       @class(['form-input text-base', 'form-input-error' => $errors->has('form.title')]) autofocus>
            </x-field>

            <x-field label="Description" for="description" error="form.description" optional>
                <textarea id="description" wire:model="form.description" rows="2" class="form-input"
                          placeholder="Quelques mots pour donner envie…"></textarea>
            </x-field>

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-5">
                <x-field label="Portions" for="servings" error="form.servings">
                    <input id="servings" type="number" min="1" max="50" wire:model="form.servings" class="form-input">
                </x-field>
                <x-field label="Préparation (min)" for="prep" error="form.prep_minutes">
                    <input id="prep" type="number" min="0" wire:model="form.prep_minutes" class="form-input">
                </x-field>
                <x-field label="Cuisson (min)" for="cook" error="form.cook_minutes">
                    <input id="cook" type="number" min="0" wire:model="form.cook_minutes" class="form-input">
                </x-field>
                <x-field label="Repos (min)" for="rest" error="form.rest_minutes">
                    <input id="rest" type="number" min="0" wire:model="form.rest_minutes" class="form-input">
                </x-field>
                <x-field label="Difficulté" for="difficulty" error="form.difficulty">
                    <select id="difficulty" wire:model="form.difficulty" class="form-input">
                        <option value="">—</option>
                        @foreach (\App\Enums\Difficulty::cases() as $difficulty)
                            <option value="{{ $difficulty->value }}">{{ $difficulty->label() }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <div>
                <span class="form-label">Catégories</span>
                <div class="flex flex-wrap gap-2">
                    @foreach ($this->tags as $tag)
                        @php $selected = in_array($tag->id, $form->tagIds, true); @endphp
                        <button type="button" wire:click="toggleTag({{ $tag->id }})" wire:key="tag-{{ $tag->id }}"
                                aria-pressed="{{ $selected ? 'true' : 'false' }}"
                                @class([
                                    'rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset transition',
                                    \App\Support\Palette::badge($tag->color) => $selected,
                                    'bg-white text-stone-500 ring-stone-200 hover:text-stone-800' => ! $selected,
                                ])>
                            @if ($selected) ✓ @endif {{ $tag->name }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-6">
                <label class="flex items-center gap-2 text-sm text-stone-700">
                    <input type="checkbox" wire:model="form.is_favorite" class="form-checkbox">
                    Recette favorite
                </label>
                <label class="flex items-center gap-2 text-sm text-stone-700">
                    <input type="checkbox" wire:model="form.is_to_test" class="form-checkbox">
                    À tester <span class="text-stone-500">(jamais encore cuisinée)</span>
                </label>
                {{-- Lot 39 (39.4) --}}
                <label class="flex items-center gap-2 text-sm text-stone-700">
                    <input type="checkbox" wire:model="form.kid_friendly" class="form-checkbox">
                    Facile avec un enfant <span class="text-stone-500">(le mode cuisine signale les étapes pour un adulte)</span>
                </label>
            </div>

            {{-- Lot 40 (40.3) : ce que demande la recette. --}}
            <fieldset class="space-y-2" data-recipe-equipment>
                <legend class="form-label">Il faut</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach (\App\Services\Recipes\KitchenEquipment::ITEMS as $key => [$label])
                        <label wire:key="req-eq-{{ $key }}" class="flex min-h-10 cursor-pointer items-center gap-2 rounded-lg px-3 py-1.5 text-sm ring-1 ring-stone-200 has-checked:bg-brand-50 has-checked:ring-brand-300">
                            <input type="checkbox" wire:model="form.equipment" value="{{ $key }}" x-on:change="$wire.set('form.equipmentSet', true, false)" class="form-checkbox"> {{ $label }}
                        </label>
                    @endforeach
                </div>
                <p class="text-xs text-stone-500">
                    @if ($form->equipmentSet) Choisi pour cette recette. @else Deviné d'après les étapes ; cochez ou décochez pour corriger. @endif
                    Une recette qui demande ce que la cuisine n'a pas (Paramètres › Équipement) n'est pas proposée.
                </p>
            </fieldset>

            {{-- Partage avec les proches (26.1, Q36) --}}
            <x-field label="Visible par" for="visibility" error="form.visibility" help="Les proches la lisent, la planifient ou la copient ; ils ne peuvent pas la modifier. Vos notes restent chez vous.">
                <select id="visibility" wire:model="form.visibility" class="form-input sm:max-w-xs">
                    @foreach (\App\Models\Recipe::VISIBILITIES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </x-field>
        </section>

        {{-- ============================================================ Photo --}}
        <section class="card p-4 sm:p-6">
            <h2 class="font-display mb-4 text-lg font-semibold text-stone-900">Photo <span class="text-sm font-normal text-stone-500">(facultatif)</span></h2>

            <div class="flex flex-wrap items-start gap-4">
                @php
                    $previewUrl = null;
                    if ($photo) {
                        try { $previewUrl = $photo->temporaryUrl(); } catch (\Throwable) { $previewUrl = null; }
                    } elseif ($form->recipe?->photo_path && ! $form->removePhoto) {
                        $previewUrl = $form->recipe->photoUrl('thumb');
                    }
                @endphp

                <div class="flex aspect-[4/3] w-48 items-center justify-center overflow-hidden rounded-lg bg-stone-100 text-stone-500">
                    @if ($previewUrl)
                        <img src="{{ $previewUrl }}" alt="Aperçu de la photo" class="size-full object-cover">
                    @else
                        <x-icon name="photo" class="size-10" />
                    @endif
                </div>

                <div class="space-y-2 text-sm">
                    <label class="btn btn-secondary cursor-pointer">
                        <x-icon name="photo" class="size-4" /> {{ $previewUrl ? 'Changer la photo' : 'Choisir une photo' }}
                        <input type="file" wire:model="photo" accept="image/jpeg,image/png,image/webp" class="sr-only">
                    </label>
                    <div wire:loading wire:target="photo" class="text-stone-500">Envoi en cours…</div>
                    @if ($previewUrl)
                        <button type="button" wire:click="removePhoto" class="block text-red-600 hover:underline">Retirer la photo</button>
                    @endif
                    <p class="text-xs text-stone-500">JPG, PNG ou WebP, 10 Mo maximum. Redimensionnée automatiquement.</p>
                    @error('photo') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        {{-- ============================================================ Ingrédients --}}
        <section class="card p-4 sm:p-6">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-display text-lg font-semibold text-stone-900">Ingrédients</h2>
                <label class="flex items-center gap-2 text-sm text-stone-600">
                    <input type="checkbox" wire:model.live="form.useGroups" class="form-checkbox">
                    Regrouper (pâte, garniture…)
                </label>
            </div>

            <div class="mb-4">
                @if (! $quickOpen)
                    <button type="button" wire:click="$set('quickOpen', true)" class="btn btn-secondary">
                        <x-icon name="sparkles" class="size-4" /> Saisie rapide (coller plusieurs lignes)
                    </button>
                @else
                    <div class="rounded-lg bg-stone-50 p-3 ring-1 ring-stone-200">
                        <label for="quick-lines" class="form-label">Une ligne par ingrédient</label>
                        <textarea id="quick-lines" wire:model="quickLines" rows="6" class="form-input font-mono text-sm"
                                  placeholder="200 g de farine&#10;3 œufs&#10;1 c. à soupe d'huile d'olive"></textarea>
                        <p class="mt-1 text-xs text-stone-500">Quantité, unité et ingrédient sont reconnus automatiquement ; tout reste modifiable ensuite.</p>
                        <div class="mt-3 flex gap-2">
                            <button type="button" wire:click="parseQuickLines" class="btn btn-primary">Ajouter les lignes</button>
                            <button type="button" wire:click="$set('quickOpen', false)" class="btn btn-ghost">Annuler</button>
                        </div>
                    </div>
                @endif
            </div>

            <datalist id="ingredient-names">
                @foreach ($this->ingredientNames as $name)
                    <option value="{{ $name }}"></option>
                @endforeach
            </datalist>

            <ul wire:sort="sortIngredients" class="space-y-3">
                @foreach ($form->ingredients as $i => $row)
                    <li wire:key="ing-{{ $row['uid'] }}" wire:sort:item="{{ $row['uid'] }}"
                        class="rounded-lg border border-stone-200 bg-white p-3 sm:border-0 sm:p-0">
                        <div class="grid grid-cols-[auto_5rem_1fr_auto] items-start gap-2 sm:grid-cols-[auto_5rem_9rem_1fr_12rem_auto_auto]">
                            <span wire:sort:handle class="cursor-grab pt-2 text-stone-300 hover:text-stone-500" title="Déplacer">
                                <x-icon name="grip" class="size-5" />
                            </span>

                            <input type="text" inputmode="decimal" wire:model="form.ingredients.{{ $i }}.quantity" placeholder="Qté"
                                   aria-label="Quantité" @class(['form-input', 'form-input-error' => $errors->has("form.ingredients.$i.quantity")])>

                            <select wire:model="form.ingredients.{{ $i }}.unit_id" aria-label="Unité"
                                    class="form-input sm:order-none">
                                <option value="">—</option>
                                @foreach ($this->units as $unit)
                                    <option value="{{ $unit->id }}">{{ $unit->label }}</option>
                                @endforeach
                            </select>

                            <button type="button" wire:click="removeIngredient('{{ $row['uid'] }}')" wire:sort:ignore
                                    class="btn btn-ghost px-2 hover:text-red-600 sm:order-last" title="Retirer la ligne">
                                <x-icon name="delete" class="size-4" /><span class="sr-only">Retirer</span>
                            </button>

                            <input type="text" list="ingredient-names" wire:model.blur="form.ingredients.{{ $i }}.name"
                                   placeholder="Ingrédient" aria-label="Ingrédient" autocomplete="off"
                                   @class(['form-input col-span-4 sm:col-span-1', 'form-input-error' => $errors->has("form.ingredients.$i.name")])>

                            <input type="text" wire:model="form.ingredients.{{ $i }}.preparation" placeholder="Précision (émincé…)"
                                   aria-label="Précision" class="form-input col-span-3 sm:col-span-1">

                            <label class="flex items-center gap-1.5 pt-2 text-xs text-stone-500" title="Ingrédient facultatif">
                                <input type="checkbox" wire:model="form.ingredients.{{ $i }}.is_optional" class="form-checkbox">
                                <span>Facult.</span>
                            </label>
                        </div>

                        @if ($form->useGroups)
                            <div class="mt-2 flex items-center gap-2 sm:ml-7">
                                <span class="text-xs text-stone-500">Groupe</span>
                                <input type="text" wire:model="form.ingredients.{{ $i }}.group_name" placeholder="ex. Pâte"
                                       aria-label="Groupe" class="form-input max-w-48 py-1 text-sm">
                            </div>
                        @endif

                        @if (in_array($i, $this->newIngredientRows, true))
                            <div class="mt-2 flex flex-wrap items-center gap-2 rounded-md bg-sky-50 px-3 py-2 text-sm text-sky-900 sm:ml-7">
                                <x-icon name="sparkles" class="size-4" />
                                <span>Nouvel ingrédient, il sera ajouté dans le rayon</span>
                                <select wire:model="form.ingredients.{{ $i }}.new_aisle_id" aria-label="Rayon du nouvel ingrédient"
                                        class="form-input w-auto py-1 text-sm">
                                    <option value="">—</option>
                                    @foreach ($this->aisles as $aisle)
                                        <option value="{{ $aisle->id }}">{{ $aisle->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        @foreach (['name', 'quantity', 'new_aisle_id'] as $field)
                            @error("form.ingredients.$i.$field") <p class="form-error sm:ml-7">{{ $message }}</p> @enderror
                        @endforeach
                    </li>
                @endforeach
            </ul>

            <button type="button" wire:click="addIngredient" class="btn btn-secondary mt-4">
                <x-icon name="plus" class="size-4" /> Ajouter un ingrédient
            </button>
        </section>

        {{-- ============================================================ Sous-recettes (13.8) --}}
        <section class="card p-4 sm:p-6">
            <h2 class="font-display text-lg font-semibold text-stone-900">Sous-recettes <span class="text-sm font-normal text-stone-500">(facultatif)</span></h2>
            <p class="mb-4 mt-1 text-sm text-stone-500">
                Une pâte brisée, une béchamel déjà enregistrées ? Utilisez-les ici plutôt que de recopier leurs ingrédients :
                ils seront comptés dans la liste de courses, le coût et le stock.
            </p>

            @if ($form->components !== [])
                <ul class="space-y-3">
                    @foreach ($form->components as $i => $row)
                        <li wire:key="component-{{ $row['uid'] }}" class="rounded-lg border border-stone-200 p-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <input type="text" inputmode="decimal" wire:model="form.components.{{ $i }}.quantity"
                                       class="form-input w-16 text-center" aria-label="Combien de fois la recette">
                                <span class="text-sm text-stone-500">×</span>
                                <select wire:model="form.components.{{ $i }}.recipe_id" class="form-input min-w-0 flex-1" aria-label="Sous-recette">
                                    <option value="">Choisir une recette…</option>
                                    @foreach ($this->componentChoices as $choice)
                                        <option value="{{ $choice->id }}">{{ $choice->title }} ({{ $choice->servings }} p.)</option>
                                    @endforeach
                                </select>
                                <button type="button" wire:click="removeComponent('{{ $row['uid'] }}')" class="btn btn-ghost px-2 hover:text-red-600" title="Retirer">
                                    <x-icon name="delete" class="size-4" /><span class="sr-only">Retirer la sous-recette</span>
                                </button>
                            </div>
                            <input type="text" wire:model="form.components.{{ $i }}.note" placeholder="Précision, ex. pour le fond de tarte"
                                   class="form-input mt-2 text-sm" aria-label="Précision">
                            @foreach (['recipe_id', 'quantity', 'note'] as $field)
                                @error("form.components.$i.$field") <p class="form-error">{{ $message }}</p> @enderror
                            @endforeach
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs text-stone-500">« 1 » = la recette telle qu'elle est écrite ; « 0,5 » = la moitié.</p>
            @endif

            <button type="button" wire:click="addComponent" class="btn btn-secondary mt-4">
                <x-icon name="plus" class="size-4" /> Utiliser une autre recette
            </button>
        </section>

        {{-- ============================================================ Étapes --}}
        <section class="card p-4 sm:p-6">
            <h2 class="font-display mb-4 text-lg font-semibold text-stone-900">Préparation</h2>

            <ol wire:sort="sortSteps" class="space-y-3">
                @foreach ($form->steps as $i => $step)
                    <li wire:key="step-{{ $step['uid'] }}" wire:sort:item="{{ $step['uid'] }}" class="flex items-start gap-2 bg-white">
                        <span wire:sort:handle class="cursor-grab pt-2 text-stone-300 hover:text-stone-500" title="Déplacer">
                            <x-icon name="grip" class="size-5" />
                        </span>
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-700">
                            {{ $loop->iteration }}
                        </span>
                        <div class="flex-1 space-y-1">
                            @if ($form->useGroups)
                                <input type="text" wire:model="form.steps.{{ $i }}.group_name" placeholder="Section (ex. La pâte)"
                                       aria-label="Section de l'étape" class="form-input max-w-56 py-1 text-sm">
                            @endif
                            <textarea wire:model="form.steps.{{ $i }}.instruction" rows="2" class="form-input"
                                      aria-label="Étape {{ $loop->iteration }}" placeholder="Décrivez l'étape…"></textarea>
                            @error("form.steps.$i.instruction") <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                        <button type="button" wire:click="removeStep('{{ $step['uid'] }}')" wire:sort:ignore
                                class="btn btn-ghost px-2 hover:text-red-600" title="Retirer l'étape">
                            <x-icon name="delete" class="size-4" /><span class="sr-only">Retirer</span>
                        </button>
                    </li>
                @endforeach
            </ol>

            <button type="button" wire:click="addStep" class="btn btn-secondary mt-4">
                <x-icon name="plus" class="size-4" /> Ajouter une étape
            </button>
        </section>

        {{-- ============================================================ Notes --}}
        <section class="card space-y-4 p-4 sm:p-6">
            <h2 class="font-display text-lg font-semibold text-stone-900">Notes et source</h2>
            <x-field label="Source" for="source" error="form.source" optional help="Adresse web ou référence (« livre de mamie, p. 42 »).">
                <input id="source" type="text" wire:model="form.source" class="form-input">
            </x-field>
            <x-field label="Notes personnelles" for="notes" error="form.notes" optional>
                <textarea id="notes" wire:model="form.notes" rows="3" class="form-input"
                          placeholder="Astuces, variantes, ce qu'on changerait la prochaine fois…"></textarea>
            </x-field>
        </section>

        {{-- ============================================================ Barre d'enregistrement --}}
        <div class="fixed inset-x-0 bottom-16 z-20 border-t border-stone-200 bg-white/95 backdrop-blur md:bottom-0">
            <div class="mx-auto flex max-w-7xl items-center justify-end gap-2 px-4 py-3 sm:px-6 lg:px-8">
                @if ($errors->any())
                    <p class="mr-auto text-sm text-red-600">Certains champs sont à corriger.</p>
                @endif
                <a href="{{ $form->recipe ? route('recipes.show', $form->recipe) : route('recipes.index') }}" wire:navigate class="btn btn-secondary">Annuler</a>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save,photo">
                    <span wire:loading.remove wire:target="save">Enregistrer</span>
                    <span wire:loading wire:target="save">Enregistrement…</span>
                </button>
            </div>
        </div>
    </form>
</div>
