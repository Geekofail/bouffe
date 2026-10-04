<div>
    <x-page-header title="Paramètres" subtitle="Les listes de référence utilisées par les recettes et la liste de courses." />
    <x-settings-nav />

    <form wire:submit="save" class="grid gap-6 lg:grid-cols-2">
        {{-- ============================================================ Temps par créneau --}}
        <section class="card space-y-4 p-5">
            <div>
                <h2 class="font-display font-semibold text-stone-900">Temps maximum par créneau</h2>
                <p class="text-sm text-stone-500">
                    Utilisé par le remplissage automatique : une recette plus longue n'est pas proposée sur ce créneau.
                    Vous pouvez toujours la planifier à la main.
                </p>
            </div>

            @foreach ($this->activeSlots as $slot)
                <div wire:key="slot-{{ $slot->id }}" class="flex flex-wrap items-center gap-3 border-t border-stone-100 pt-3 first:border-0 first:pt-0">
                    <span class="w-28 text-sm font-medium text-stone-700">{{ $slot->name }}</span>

                    <div class="flex items-center gap-2">
                        <input type="number" min="5" max="600" step="5" wire:model="slotRules.{{ $slot->id }}.max_minutes"
                               placeholder="—" aria-label="Temps maximum pour {{ $slot->name }}"
                               @class(['form-input w-24', 'form-input-error' => $errors->has("slotRules.{$slot->id}.max_minutes")])>
                        <span class="text-sm text-stone-500">min</span>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-stone-600">
                        <input type="checkbox" wire:model="slotRules.{{ $slot->id }}.weeknights_only" class="form-checkbox">
                        du lundi au jeudi seulement
                    </label>

                    @error("slotRules.{$slot->id}.max_minutes") <p class="form-error w-full">{{ $message }}</p> @enderror
                </div>
            @endforeach

            <p class="text-xs text-stone-500">Laissez vide pour ne pas limiter.</p>
        </section>

        {{-- ============================================================ Quotas de catégories --}}
        <section class="card space-y-4 p-5">
            <div>
                <h2 class="font-display font-semibold text-stone-900">Catégories dans la semaine</h2>
                <p class="text-sm text-stone-500">
                    Par exemple : du poisson au plus une fois, du végétarien au moins deux fois.
                    Le bilan s'affiche en haut du planning.
                </p>
            </div>

            @if ($this->tags->isEmpty())
                <p class="text-sm text-stone-500">
                    Créez d'abord des <a href="{{ route('settings.tags') }}" wire:navigate class="underline">catégories</a>.
                </p>
            @else
                @forelse ($quotas as $i => $quota)
                    <div wire:key="quota-{{ $i }}" class="flex flex-wrap items-end gap-2 border-t border-stone-100 pt-3 first:border-0 first:pt-0">
                        <div class="min-w-40 flex-1">
                            <label class="form-label" for="quota-tag-{{ $i }}">Catégorie</label>
                            <select id="quota-tag-{{ $i }}" wire:model="quotas.{{ $i }}.tag_id" class="form-input">
                                <option value="">—</option>
                                @foreach ($this->tags as $tag)
                                    <option value="{{ $tag->id }}">{{ $tag->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="w-24">
                            <label class="form-label" for="quota-min-{{ $i }}">Au moins</label>
                            <input id="quota-min-{{ $i }}" type="number" min="0" max="21" wire:model="quotas.{{ $i }}.min" class="form-input" placeholder="—">
                        </div>
                        <div class="w-24">
                            <label class="form-label" for="quota-max-{{ $i }}">Au plus</label>
                            <input id="quota-max-{{ $i }}" type="number" min="0" max="21" wire:model="quotas.{{ $i }}.max" class="form-input" placeholder="—">
                        </div>
                        <button type="button" wire:click="removeQuota({{ $i }})" class="btn btn-ghost px-2 pb-2 hover:text-red-600" title="Retirer">
                            <x-icon name="delete" class="size-4" /><span class="sr-only">Retirer</span>
                        </button>
                    </div>
                @empty
                    <p class="text-sm text-stone-500">Aucune règle de catégorie.</p>
                @endforelse

                <button type="button" wire:click="addQuota" class="btn btn-secondary">
                    <x-icon name="plus" class="size-4" /> Ajouter une règle
                </button>
            @endif

            <label class="flex items-center gap-2 border-t border-stone-100 pt-3 text-sm text-stone-700">
                <input type="checkbox" wire:model="avoidRepeatCategory" class="form-checkbox">
                Éviter la même catégorie deux jours de suite
            </label>
        </section>

        {{-- ============================================================ Rappels --}}
        <section class="card space-y-4 p-5">
            <div>
                <h2 class="font-display font-semibold text-stone-900">Préparation anticipée</h2>
                <p class="text-sm text-stone-500">
                    Bouffe calcule tout seul ce qu'il faut sortir du congélateur, faire tremper, mariner ou préparer la veille.
                    Les rappels apparaissent dans la cloche du menu et sur le planning.
                </p>
            </div>

            <x-field label="Heure des rappels de la veille" for="reminder-hour" error="reminderHour"
                     help="Par exemple 18 h : « sortir le poulet du congélateur » s'affiche la veille à 18 h.">
                <div class="flex items-center gap-2">
                    <input id="reminder-hour" type="number" min="6" max="22" wire:model="reminderHour" class="form-input w-24">
                    <span class="text-sm text-stone-500">h</span>
                </div>
            </x-field>
        </section>

        <div class="lg:col-span-2">
            <button type="submit" class="btn btn-primary">Enregistrer</button>
        </div>
    </form>
</div>
