<div>
    <x-page-header title="Remplacements" subtitle="« Pas de crème ? » : ce qui peut remplacer un ingrédient, proposé en cuisine, dans « Que cuisiner ? » et sur la liste de courses." />
    <x-settings-nav />

    @php $canEdit = auth()->user()->canEdit(); @endphp

    @if ($canEdit)
        <form wire:submit="add" class="card mb-6 space-y-4 p-4 sm:p-5" data-substitution-form>
            <h2 class="font-display font-semibold text-stone-900">Ajouter un remplacement</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <x-field label="Ingrédient" for="sub-ingredient" error="ingredientId">
                    <select id="sub-ingredient" wire:model="ingredientId" class="form-input">
                        <option value="">Choisir…</option>
                        @foreach ($this->ingredients as $ingredient) <option value="{{ $ingredient->id }}">{{ $ingredient->name }}</option> @endforeach
                    </select>
                </x-field>
                <x-field label="Remplacé par" for="sub-substitute" error="substituteId">
                    <select id="sub-substitute" wire:model="substituteId" class="form-input">
                        <option value="">Choisir…</option>
                        @foreach ($this->ingredients as $ingredient) <option value="{{ $ingredient->id }}">{{ $ingredient->name }}</option> @endforeach
                    </select>
                </x-field>
                <x-field label="Quantité" for="sub-ratio" error="ratio" help="1 = même quantité ; 0,8 = un peu moins.">
                    <input id="sub-ratio" type="text" inputmode="decimal" wire:model="ratio" class="form-input">
                </x-field>
                <x-field label="Pour la recette" for="sub-recipe" optional help="Vide : pour toutes les recettes.">
                    <select id="sub-recipe" wire:model="recipeId" class="form-input">
                        <option value="">Toutes les recettes</option>
                        @foreach ($this->recipes as $recipe) <option value="{{ $recipe->id }}">{{ $recipe->title }}</option> @endforeach
                    </select>
                </x-field>
            </div>
            <x-field label="Note" for="sub-note" error="note" optional>
                <input id="sub-note" type="text" wire:model="note" maxlength="150" placeholder="ex. à ajouter hors du feu" class="form-input">
            </x-field>
            <div class="flex justify-end"><button type="submit" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Ajouter</button></div>
        </form>
    @endif

    <div class="mb-3 flex flex-wrap items-center gap-3">
        <div class="relative min-w-0 flex-1 basis-60">
            <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-stone-400" />
            <input type="search" wire:model.live.debounce.250ms="search" placeholder="Chercher un ingrédient…" class="form-input pl-10" aria-label="Chercher un remplacement">
        </div>
        <p class="text-sm text-stone-500">{{ $this->rows->count() }} remplacement{{ $this->rows->count() > 1 ? 's' : '' }}</p>
    </div>

    <ul class="card divide-y divide-stone-100" data-substitutions>
        @forelse ($this->rows as $row)
            <li wire:key="sub-{{ $row->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5">
                <span class="min-w-0 flex-1 basis-64 text-sm text-stone-800">
                    <strong>{{ $row->ingredient->name }}</strong> → {{ mb_strtolower($row->substitute->name) }}
                    <span class="text-stone-500">· {{ $row->ratioLabel() }}@if ($row->note) · {{ $row->note }}@endif</span>
                </span>
                <span class="text-xs text-stone-500">
                    @if ($row->recipe) pour « {{ $row->recipe->title }} »
                    @elseif ($row->isCommon()) liste commune
                    @else notre foyer @endif
                    @if ($row->source === 'variant') · d'une variante @elseif ($row->source === 'assistant') · de l'assistant @endif
                </span>
                @if ($canEdit)
                    <button type="button" wire:click="remove({{ $row->id }})" class="btn btn-ghost min-h-10 px-2 text-sm text-stone-500 hover:text-red-700" title="{{ $row->isCommon() ? 'Masquer' : 'Retirer' }} {{ $row->ingredient->name }} → {{ $row->substitute->name }}">
                        {{ $row->isCommon() ? 'Masquer' : 'Retirer' }}
                    </button>
                @endif
            </li>
        @empty
            <li class="px-4 py-6 text-center text-sm text-stone-500">Aucun remplacement{{ $search !== '' ? ' pour « '.$search.' »' : '' }}.</li>
        @endforelse
    </ul>

    @if ($this->hidden->isNotEmpty())
        <details class="mt-4 text-sm">
            <summary class="cursor-pointer font-medium text-stone-700">Remplacements communs masqués ({{ $this->hidden->count() }})</summary>
            <ul class="mt-2 space-y-1">
                @foreach ($this->hidden as $row)
                    <li wire:key="hidden-{{ $row->id }}" class="flex items-center gap-2">
                        <span class="flex-1 text-stone-600">{{ $row->ingredient?->name }} → {{ mb_strtolower((string) $row->substitute?->name) }}</span>
                        @if ($canEdit) <button type="button" wire:click="restore({{ $row->id }})" class="btn btn-ghost min-h-10 text-sm">Remettre</button> @endif
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    <p class="mt-4 text-xs text-stone-500">
        Un remplacement ne modifie jamais la recette. Il n'est pas proposé quand il contient l'allergène d'une personne à table
        (une allergie au lait écarte aussi le beurre et la crème).
    </p>
</div>
