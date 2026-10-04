<div>
    <a href="{{ route('recipes.index') }}" wire:navigate
       class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> Recettes
    </a>

    <x-page-header title="Importer une recette"
                   :subtitle="$reviewing ? 'Relisez et corrigez ce que Bouffe a compris, puis enregistrez.' : 'Depuis une adresse, un texte collé ou un export Bouffe.'" />

    @if (! $reviewing)
        {{-- ============================================================ Choix de la source --}}
        <div class="mb-6 flex flex-wrap gap-2" role="tablist">
            @foreach (['url' => 'Depuis une adresse', 'texte' => 'Coller du texte', 'photo' => 'Photo d\'une page', 'json' => 'Fichier Bouffe'] as $key => $label)
                <button type="button" wire:click="selectTab('{{ $key }}')" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        @class([
                            'rounded-full px-4 py-2 text-sm font-semibold ring-1 ring-inset transition',
                            'bg-brand-600 text-white ring-brand-600' => $tab === $key,
                            'bg-white text-stone-600 ring-stone-200 hover:text-stone-900' => $tab !== $key,
                        ])>
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @if ($tab === 'url')
            <section class="card space-y-4 p-4 sm:p-6">
                <p class="text-sm text-stone-600">
                    Collez l'adresse d'une page de recette. Bouffe lit la page et en retire le titre, les portions,
                    les temps, les ingrédients et les étapes. Vous relisez tout avant d'enregistrer.
                </p>

                <form wire:submit="fetch" class="flex flex-wrap items-end gap-3">
                    <x-field label="Adresse de la recette" for="url" error="url" class="min-w-64 flex-1">
                        <input id="url" type="url" wire:model="url" placeholder="https://…" autofocus
                               @class(['form-input', 'form-input-error' => $errors->has('url')])>
                    </x-field>

                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="fetch">
                        <x-icon name="download" class="size-4" />
                        <span wire:loading.remove wire:target="fetch">Analyser la page</span>
                        <span wire:loading wire:target="fetch">Lecture en cours…</span>
                    </button>
                </form>

                <p class="text-xs text-stone-500">
                    Toutes les pages ne sont pas lisibles : si celle-ci ne l'est pas, copiez le texte de la recette
                    et utilisez « Coller du texte ».
                </p>
            </section>
        @elseif ($tab === 'texte')
            <section class="card space-y-4 p-4 sm:p-6">
                <p class="text-sm text-stone-600">
                    Collez la recette telle quelle — titre, « Ingrédients », « Préparation ». Bouffe sépare les
                    ingrédients des étapes et analyse chaque ligne.
                </p>

                <form wire:submit="analyse" class="space-y-3">
                    <x-field label="Texte de la recette" for="text" error="text">
                        <textarea id="text" wire:model="text" rows="14" class="form-input font-mono text-sm"
                                  placeholder="Gratin dauphinois&#10;Pour 6 personnes&#10;&#10;Ingrédients&#10;1,5 kg de pommes de terre&#10;50 cl de crème liquide&#10;&#10;Préparation&#10;Éplucher les pommes de terre…"></textarea>
                    </x-field>

                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="analyse">
                        <x-icon name="sparkles" class="size-4" /> Analyser le texte
                    </button>
                </form>
            </section>
        @elseif ($tab === 'photo')
            @php $ocrStatus = app(\App\Services\Receipts\OcrService::class)->status(); @endphp
            <section class="card space-y-4 p-4 sm:p-6">
                <p class="text-sm text-stone-600">
                    Une page de livre, une fiche du carnet familial, même manuscrite : le texte est lu par le service
                    des tickets de caisse, puis analysé comme un texte collé. Vous relisez tout avant d'enregistrer.
                </p>

                @if ($ocrStatus['available'])
                    <form wire:submit="readPhoto" class="space-y-3">
                        <x-field label="Photo ou PDF" for="recipe-photo" error="photo">
                            <input id="recipe-photo" type="file" accept="image/*,application/pdf" wire:model="photo" class="form-input">
                        </x-field>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="readPhoto,photo">
                            <x-icon name="camera" class="size-4" />
                            <span wire:loading.remove wire:target="readPhoto">Lire la page</span>
                            <span wire:loading wire:target="readPhoto">Lecture en cours…</span>
                        </button>
                    </form>
                    <p class="text-xs text-stone-500">Envoyé à {{ $ocrStatus['label'] }} : seulement la photo. Compte dans les lectures du mois.</p>
                @else
                    <p class="flex gap-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-900"><x-icon name="info" class="size-5 shrink-0" /> {{ $ocrStatus['reason'] }}</p>
                @endif
            </section>
        @else
            <section class="card space-y-4 p-4 sm:p-6">
                <p class="text-sm text-stone-600">
                    Reprenez un carnet exporté depuis Bouffe (fichier <code class="rounded bg-stone-100 px-1">.json</code>).
                    Les recettes déjà présentes sous le même titre sont laissées de côté. Les photos ne sont pas reprises.
                </p>

                <form wire:submit="importJson" class="flex flex-wrap items-end gap-3">
                    <x-field label="Fichier d'export" for="file" error="file" class="min-w-64 flex-1">
                        <input id="file" type="file" wire:model="file" accept="application/json,.json" class="form-input py-1.5">
                    </x-field>

                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="file,importJson">
                        <x-icon name="download" class="size-4" /> Importer le fichier
                    </button>
                </form>

                @if ($report)
                    <div class="rounded-lg bg-herb-50 p-4 text-sm text-herb-900">
                        <p class="font-semibold">{{ count($report['created']) }} recette(s) ajoutée(s).</p>
                        @if ($report['created'])
                            <p class="mt-1">{{ implode(' · ', array_slice($report['created'], 0, 20)) }}</p>
                        @endif
                        @if ($report['skipped'])
                            <p class="mt-2 text-stone-600">
                                Déjà présentes, ignorées : {{ implode(' · ', array_slice($report['skipped'], 0, 20)) }}
                            </p>
                        @endif
                        <a href="{{ route('recipes.index', ['a_tester' => 1]) }}" wire:navigate class="mt-2 inline-block font-semibold underline">
                            Voir les recettes à tester
                        </a>
                    </div>
                @endif

                <p class="border-t border-stone-200 pt-4 text-sm text-stone-600">
                    Pour emporter votre carnet ailleurs :
                    <a href="{{ route('recipes.export') }}" class="font-semibold text-brand-700 hover:underline">exporter toutes les recettes</a>.
                </p>
            </section>
        @endif
    @else
        {{-- ============================================================ Relecture --}}
        @if ($this->existing && ! $ignoreExisting)
            <div class="mb-6 flex flex-wrap items-center gap-3 rounded-xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200">
                <x-icon name="warning" class="size-5 shrink-0" />
                <span class="flex-1">« {{ $existingTitle }} » est déjà dans votre carnet.</span>
                <a href="{{ route('recipes.show', $this->existing) }}" wire:navigate class="btn btn-secondary">Ouvrir la recette existante</a>
                <button type="button" wire:click="$set('ignoreExisting', true)" class="btn btn-ghost">Créer quand même</button>
            </div>
        @endif

        @if ($assistantLabel)
            <div class="mb-6 flex items-start gap-3 rounded-xl bg-violet-50 p-4 text-sm text-violet-900 ring-1 ring-violet-200" data-assistant-draft>
                <x-icon name="sparkles" class="size-5 shrink-0" />
                <p>
                    <strong>Brouillon proposé par l'assistant</strong> ({{ $assistantLabel }}).
                    Rien n'est enregistré : relisez les quantités et les étapes, corrigez, puis enregistrez — ou annulez.
                </p>
            </div>
        @endif

        <form wire:submit="save" class="space-y-6 pb-24">
            <section class="card space-y-4 p-4 sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="font-display text-lg font-semibold text-stone-900">Informations</h2>
                    <button type="button" wire:click="restart" class="btn btn-ghost text-sm">
                        <x-icon name="undo" class="size-4" /> Recommencer
                    </button>
                </div>

                <x-field label="Titre" for="title" error="form.title">
                    <input id="title" type="text" wire:model="form.title"
                           @class(['form-input text-base', 'form-input-error' => $errors->has('form.title')])>
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
                            @foreach (\App\Enums\Difficulty::options() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>

                @if ($this->tags->isNotEmpty())
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
                @endif

                @if ($this->assistantAvailable || $suggestion)
                    @include('livewire.recipes.import.assistant-complete')
                @endif

                @if ($imageUrl)
                    <label class="flex items-start gap-3 rounded-lg bg-stone-50 p-3 text-sm text-stone-700">
                        <input type="checkbox" wire:model="downloadImage" class="form-checkbox mt-0.5">
                        <span>
                            Reprendre la photo de la page
                            <span class="block truncate text-xs text-stone-500">{{ $imageUrl }}</span>
                        </span>
                    </label>
                @endif

                <label class="flex items-center gap-2 text-sm text-stone-700">
                    <input type="checkbox" wire:model="form.is_to_test" class="form-checkbox">
                    Marquer « à tester » (jusqu'à ce qu'elle soit cuisinée)
                </label>
            </section>

            {{-- ---------------------------------------------------- Ingrédients --}}
            <section class="card p-4 sm:p-6">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="font-display text-lg font-semibold text-stone-900">
                        Ingrédients <span class="text-sm font-normal text-stone-500">({{ count($form->ingredients) }})</span>
                    </h2>
                    @if ($this->unknownCount > 0)
                        <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-900">
                            {{ $this->unknownCount }} ingrédient(s) à créer
                        </span>
                    @endif
                </div>

                <datalist id="ingredient-names">
                    @foreach ($this->ingredientNames as $name)
                        <option value="{{ $name }}"></option>
                    @endforeach
                </datalist>

                <ul class="space-y-3">
                    @foreach ($form->ingredients as $i => $row)
                        @php $status = $confidence[$row['uid']] ?? 'none'; @endphp
                        <li wire:key="ing-{{ $row['uid'] }}" class="rounded-lg border border-stone-200 p-3 sm:border-0 sm:p-0">
                            <div class="grid grid-cols-[auto_5rem_1fr_auto] items-start gap-2 sm:grid-cols-[auto_5rem_9rem_1fr_11rem_auto]">
                                <span class="pt-2" title="{{ ['high' => 'Ingrédient reconnu', 'medium' => 'Ingrédient approchant, à vérifier', 'none' => 'Ingrédient inconnu, il sera créé'][$status] }}">
                                    @if ($status === 'high')
                                        <x-icon name="check" class="size-5 text-herb-600" />
                                    @elseif ($status === 'medium')
                                        <span class="text-lg font-bold text-amber-500" aria-hidden="true">?</span>
                                    @else
                                        <x-icon name="plus" class="size-5 text-sky-600" />
                                    @endif
                                    <span class="sr-only">{{ $status }}</span>
                                </span>

                                <input type="text" inputmode="decimal" wire:model="form.ingredients.{{ $i }}.quantity" placeholder="Qté"
                                       aria-label="Quantité" @class(['form-input', 'form-input-error' => $errors->has("form.ingredients.$i.quantity")])>

                                <select wire:model="form.ingredients.{{ $i }}.unit_id" aria-label="Unité" class="form-input">
                                    <option value="">—</option>
                                    @foreach ($this->units as $unit)
                                        <option value="{{ $unit->id }}">{{ $unit->label }}</option>
                                    @endforeach
                                </select>

                                <button type="button" wire:click="removeIngredient('{{ $row['uid'] }}')"
                                        class="btn btn-ghost px-2 hover:text-red-600 sm:order-last" title="Retirer la ligne">
                                    <x-icon name="delete" class="size-4" /><span class="sr-only">Retirer</span>
                                </button>

                                <input type="text" list="ingredient-names" wire:model.blur="form.ingredients.{{ $i }}.name"
                                       placeholder="Ingrédient" aria-label="Ingrédient" autocomplete="off"
                                       @class(['form-input col-span-4 sm:col-span-1', 'form-input-error' => $errors->has("form.ingredients.$i.name")])>

                                <input type="text" wire:model="form.ingredients.{{ $i }}.preparation" placeholder="Précision (émincé…)"
                                       aria-label="Précision" class="form-input col-span-3 sm:col-span-1">
                            </div>

                            @if ($row['group_name'])
                                <p class="mt-1 text-xs text-stone-500 sm:ml-7">Groupe : {{ $row['group_name'] }}</p>
                            @endif

                            @if ($status === 'none' && trim($row['name']) !== '')
                                <div class="mt-2 flex flex-wrap items-center gap-2 rounded-md bg-sky-50 px-3 py-2 text-sm text-sky-900 sm:ml-7">
                                    <x-icon name="sparkles" class="size-4" />
                                    <span>Nouvel ingrédient, rayon</span>
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

                            @if ($row['is_optional'])
                                <p class="mt-1 text-xs text-stone-500 sm:ml-7">Ingrédient facultatif</p>
                            @endif
                        </li>
                    @endforeach
                </ul>

                <button type="button" wire:click="addIngredient" class="btn btn-secondary mt-4">
                    <x-icon name="plus" class="size-4" /> Ajouter un ingrédient
                </button>
            </section>

            {{-- ---------------------------------------------------- Étapes --}}
            <section class="card p-4 sm:p-6">
                <h2 class="font-display mb-4 text-lg font-semibold text-stone-900">
                    Préparation <span class="text-sm font-normal text-stone-500">({{ count($form->steps) }} étapes)</span>
                </h2>

                <ol class="space-y-3">
                    @foreach ($form->steps as $i => $step)
                        <li wire:key="step-{{ $step['uid'] }}" class="flex items-start gap-2">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-700">
                                {{ $loop->iteration }}
                            </span>
                            <div class="flex-1 space-y-1">
                                @if ($step['group_name'])
                                    <input type="text" wire:model="form.steps.{{ $i }}.group_name" aria-label="Section"
                                           class="form-input max-w-56 py-1 text-sm">
                                @endif
                                <textarea wire:model="form.steps.{{ $i }}.instruction" rows="2" class="form-input"
                                          aria-label="Étape {{ $loop->iteration }}"></textarea>
                                @error("form.steps.$i.instruction") <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                            <button type="button" wire:click="removeStep('{{ $step['uid'] }}')"
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

            <div class="fixed inset-x-0 bottom-16 z-20 border-t border-stone-200 bg-white/95 backdrop-blur md:bottom-0">
                <div class="mx-auto flex max-w-7xl items-center justify-end gap-2 px-4 py-3 sm:px-6 lg:px-8">
                    @if ($errors->any())
                        <p class="mr-auto text-sm text-red-600">Certains champs sont à corriger.</p>
                    @endif
                    <button type="button" wire:click="restart" class="btn btn-secondary">Annuler</button>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                        <x-icon name="check" class="size-4" />
                        <span wire:loading.remove wire:target="save">Enregistrer la recette</span>
                        <span wire:loading wire:target="save">Enregistrement…</span>
                    </button>
                </div>
            </div>
        </form>
    @endif
</div>
