{{-- Stock : fenêtre d'ajout (lot 36). --}}
{{-- ============================================================ Fenêtre : ajout --}}
<x-modal :show="$showAdd" :title="$addMode === 'prepared' ? 'Ajouter un plat préparé' : 'Ajouter au stock'" close="closeAdd">
    <form id="stock-add-form" wire:submit="saveAdd" class="space-y-4">
        <div class="flex gap-1 rounded-xl bg-stone-100 p-1" role="tablist">
            @foreach (['ingredient' => 'Ingrédient', 'prepared' => 'Plat préparé / restes'] as $mode => $label)
                <label @class(['flex-1 cursor-pointer rounded-lg px-3 py-1.5 text-center text-sm font-medium', 'bg-white shadow-sm text-stone-900' => $addMode === $mode, 'text-stone-600' => $addMode !== $mode])>
                    <input type="radio" wire:model.live="addMode" value="{{ $mode }}" class="sr-only"> {{ $label }}
                </label>
            @endforeach
        </div>

        <datalist id="stock-ingredients">
            @foreach ($this->ingredientNames as $name) <option value="{{ $name }}"></option> @endforeach
        </datalist>
        <x-field :label="$addMode === 'prepared' ? 'Plat' : 'Ingrédient'" for="add-name" error="addName"
                 :help="$addMode === 'ingredient' ? 'Un ingrédient inconnu est créé dans le rayon Divers.' : null">
            <input id="add-name" type="text" wire:model.live.debounce.400ms="addName" maxlength="150" class="form-input" autocomplete="off"
                   @if ($addMode === 'ingredient') list="stock-ingredients" placeholder="ex. Beurre" @else placeholder="ex. Restes de lasagnes" @endif>
        </x-field>

        @php $matched = $addMode === 'ingredient' ? \App\Models\Ingredient::firstWhere('search_name', \App\Support\NameNormalizer::normalize($addName)) : null; @endphp
        @if ($matched?->stock_mode === \App\Enums\StockMode::Presence)
            <p class="rounded-lg bg-stone-50 px-3 py-2 text-sm text-stone-600">Suivi en <strong>présence</strong> : on note seulement qu'il y en a.</p>
        @else
            <div class="grid grid-cols-[1fr_auto] gap-2">
                <x-field label="Quantité" for="add-qty" error="addQuantity" optional>
                    <input id="add-qty" type="text" inputmode="decimal" wire:model="addQuantity" placeholder="ex. 250" class="form-input">
                </x-field>
                <x-field label="Unité" for="add-unit">
                    <select id="add-unit" wire:model="addUnitId" class="form-input">
                        <option value="">—</option>
                        @foreach ($this->units as $unit) <option value="{{ $unit->id }}">{{ $unit->label }}</option> @endforeach
                    </select>
                </x-field>
            </div>
        @endif

        <x-field label="Emplacement" for="add-location" error="addLocationId">
            <select id="add-location" wire:model="addLocationId" class="form-input">
                @foreach ($this->locations as $loc) <option value="{{ $loc->id }}">{{ $loc->name }}</option> @endforeach
            </select>
        </x-field>

        <div class="space-y-2">
            <div class="grid grid-cols-2 gap-2">
                <x-field label="Date limite" for="add-date" error="addExpiresOn" optional>
                    <input id="add-date" type="date" wire:model="addExpiresOn" class="form-input">
                </x-field>
                <x-field label="Type" for="add-type">
                    <select id="add-type" wire:model="addExpiryType" class="form-input">
                        @foreach ($expiryTypes as $type) <option value="{{ $type->value }}">{{ $type->label() }}</option> @endforeach
                    </select>
                </x-field>
            </div>
            <div class="flex flex-wrap gap-1.5">
                @foreach (['3d' => '+3 j', '1w' => '+1 sem.', '1m' => '+1 mois', 'none' => 'Pas de date'] as $key => $label)
                    <button type="button" wire:click="shiftDate('{{ $key }}')" class="rounded-full bg-stone-100 px-3 py-1 text-sm text-stone-700 hover:bg-stone-200">{{ $label }}</button>
                @endforeach
            </div>
        </div>

        <x-field label="Note" for="add-note" error="addNote" optional>
            <input id="add-note" type="text" wire:model="addNote" maxlength="255" placeholder="ex. pour le gâteau de dimanche" class="form-input">
        </x-field>
    </form>

    <x-slot:footer>
        <button type="button" wire:click="closeAdd" class="btn btn-secondary">Annuler</button>
        <button type="submit" form="stock-add-form" class="btn btn-primary">Ajouter</button>
    </x-slot:footer>
</x-modal>
