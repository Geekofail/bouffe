<div>
    <x-page-header title="Paramètres" subtitle="Les listes de référence utilisées par les recettes et la liste de courses." />
    <x-settings-nav />

    <a href="{{ route('settings.ingredients') }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> Ingrédients
    </a>

    <div class="mb-4">
        <h2 class="font-display text-xl font-semibold text-stone-900">Doublons d'ingrédients</h2>
        <p class="text-sm text-stone-500">Fusionner deux ingrédients : recettes, stock, listes de courses et contraintes des invités passent sur celui que vous gardez ; l'autre nom reste reconnu (recherche, ajout rapide, saisie des recettes).</p>
    </div>

    @if ($this->lastMerge)
        <div class="mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-stone-900 px-4 py-2.5 text-sm text-white">
            <x-icon name="merge" class="size-5 shrink-0" />
            <span class="flex-1">Dernière fusion : « {{ $this->lastMerge->source_name }} » → « {{ $this->lastMerge->target->name }} », {{ $this->lastMerge->created_at->locale('fr')->diffForHumans() }}.</span>
            <button type="button" wire:click="undo" wire:confirm="Annuler la fusion ? « {{ $this->lastMerge->source_name }} » sera recréé et retrouvera ses recettes et son stock." class="flex items-center gap-1 rounded-md bg-white/15 px-2.5 py-1 font-medium hover:bg-white/25">
                <x-icon name="undo" class="size-4" /> Annuler
            </button>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-5">
        {{-- ============================================================ Doublons probables --}}
        <section class="card p-5 lg:col-span-3">
            <h3 class="mb-1 font-semibold text-stone-900">Doublons probables <span class="text-sm font-normal text-stone-500">({{ $this->duplicates->count() }})</span></h3>
            <p class="mb-4 text-sm text-stone-500">Noms très proches dans le même rayon (faute de frappe, espace…). Choisissez le nom à garder.</p>

            @if ($this->duplicates->isEmpty())
                <p class="flex items-center gap-2 rounded-lg bg-herb-50 px-3 py-3 text-sm text-herb-800"><x-icon name="success" class="size-5" /> Aucun doublon probable.</p>
            @else
                <ul class="divide-y divide-stone-100">
                    @foreach ($this->duplicates as $pair)
                        <li wire:key="pair-{{ $pair['a']->id }}-{{ $pair['b']->id }}" class="space-y-2 py-3">
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach (['a', 'b'] as $side)
                                    <div class="rounded-lg bg-stone-50 px-3 py-2">
                                        <p class="font-medium text-stone-900">{{ $pair[$side]->name }}</p>
                                        <p class="text-xs text-stone-500">{{ $pair[$side]->aisle?->name }} · {{ $this->usage($pair[$side]) }}</p>
                                    </div>
                                @endforeach
                            </div>
                            <div class="flex flex-wrap gap-1.5">
                                <button type="button" wire:click="prepare({{ $pair['b']->id }}, {{ $pair['a']->id }})" class="btn btn-secondary px-2.5 py-1 text-xs">Garder « {{ $pair['a']->name }} »</button>
                                <button type="button" wire:click="prepare({{ $pair['a']->id }}, {{ $pair['b']->id }})" class="btn btn-secondary px-2.5 py-1 text-xs">Garder « {{ $pair['b']->name }} »</button>
                                <button type="button" wire:click="ignore({{ $pair['a']->id }}, {{ $pair['b']->id }})" class="btn btn-ghost px-2.5 py-1 text-xs">Pas un doublon</button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- ============================================================ Fusion manuelle --}}
        <form wire:submit="review" class="card h-fit space-y-4 p-5 lg:col-span-2">
            <h3 class="font-semibold text-stone-900">Fusionner deux ingrédients</h3>
            <x-field label="Ingrédient à supprimer" for="merge-source">
                <select id="merge-source" wire:model="sourceId" class="form-input">
                    <option value="">—</option>
                    @foreach ($this->ingredients as $ingredient) <option value="{{ $ingredient->id }}">{{ $ingredient->name }}</option> @endforeach
                </select>
            </x-field>
            <x-field label="Ingrédient à garder" for="merge-target" error="targetId">
                <select id="merge-target" wire:model="targetId" @class(['form-input', 'form-input-error' => $errors->has('targetId')])>
                    <option value="">—</option>
                    @foreach ($this->ingredients as $ingredient) <option value="{{ $ingredient->id }}">{{ $ingredient->name }}</option> @endforeach
                </select>
            </x-field>
            <div class="flex justify-end"><button type="submit" class="btn btn-primary"><x-icon name="merge" class="size-4" /> Fusionner…</button></div>
        </form>
    </div>

    <x-modal :show="$confirming" title="Fusionner les ingrédients" close="cancel" max-width="max-w-md">
        @if ($source && $target)
            <p class="text-sm text-stone-700">
                <strong>« {{ $source->name }} »</strong> sera supprimé et remplacé par <strong>« {{ $target->name }} »</strong> dans :
            </p>
            <ul class="mt-3 space-y-1 text-sm text-stone-600">
                <li>· {{ $impact['recipes'] }} recette{{ $impact['recipes'] > 1 ? 's' : '' }}</li>
                <li>· {{ $impact['stock'] }} article{{ $impact['stock'] > 1 ? 's' : '' }} en stock</li>
                <li>· {{ $impact['lists'] }} liste{{ $impact['lists'] > 1 ? 's' : '' }} de courses</li>
                <li>· {{ $impact['restrictions'] }} contrainte{{ $impact['restrictions'] > 1 ? 's' : '' }} d'invités</li>
            </ul>
            <p class="mt-3 text-xs text-stone-500">Les réglages de stock de « {{ $target->name }} » sont conservés. « {{ $source->name }} » devient un autre nom de « {{ $target->name }} ». Une sauvegarde est faite avant, et la fusion peut être annulée.</p>
        @endif
        <x-slot:footer>
            <button type="button" wire:click="cancel" class="btn btn-secondary">Annuler</button>
            <button type="button" wire:click="merge" class="btn btn-primary">Fusionner</button>
        </x-slot:footer>
    </x-modal>
</div>
