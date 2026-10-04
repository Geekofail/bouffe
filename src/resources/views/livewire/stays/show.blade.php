@php
    $canEdit = auth()->user()->canEdit();
    $organizer = $role === \App\Services\Stays\StayCoorganizers::ORGANIZER;
    $organizerName = $householdNames[$stay->household_id] ?? '';
@endphp
<div class="mx-auto max-w-6xl" data-stay>
    <a href="{{ route('stays.index') }}" wire:navigate class="mb-3 inline-flex items-center gap-1 text-sm text-stone-500 hover:text-stone-800">
        <x-icon name="chevron-left" class="size-4" /> Séjours
    </a>

    <x-page-header :title="$stay->name"
                   :subtitle="ucfirst($stay->period()).($stay->place ? ' · '.$stay->place : '').' · '.$stay->participants->count().' personne'.($stay->participants->count() > 1 ? 's' : '')">
        <x-slot:actions>
            @if ($canEdit && $organizer)
                <button type="button" wire:click="edit" class="btn btn-secondary"><x-icon name="edit" class="size-4" /> Modifier</button>
            @elseif ($canEdit)
                <button type="button" wire:click="leave" wire:confirm="Ne plus co-organiser « {{ $stay->name }} » ? Vos dépenses restent dans les comptes." class="btn btn-ghost">Quitter le séjour</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- Lot 42 (42.1) : séjour co-organisé. --}}
    @if (! $organizer)
        <p class="-mt-3 mb-4 flex flex-wrap items-center gap-2 rounded-lg bg-sky-50 p-3 text-sm text-sky-900 ring-1 ring-sky-100" data-stay-coorganized>
            <x-icon name="users" class="size-4" />
            Séjour organisé par « {{ $organizerName }} » : vous y prévoyez des repas, notez vos dépenses et gérez vos participants.
        </p>
    @elseif (count($householdNames) > 1 && $stay->households->where('status', 'accepted')->isNotEmpty())
        <p class="-mt-3 mb-4 text-sm text-stone-600" data-stay-coorganized>
            Co-organisé avec {{ $stay->households->where('status', 'accepted')->map(fn ($row) => '« '.($householdNames[$row->household_id] ?? '').' »')->join(', ', ' et ') }}.
        </p>
    @endif

    @if ($stay->notes)
        <p class="-mt-3 mb-4 text-sm whitespace-pre-line text-stone-600">{{ $stay->notes }}</p>
    @endif

    @error('delete') <p class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200">{{ $message }}</p> @enderror

    <nav class="-mx-4 mb-5 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="Sections du séjour">
        <div class="flex min-w-max gap-1 rounded-xl bg-stone-100 p-1" role="tablist">
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="selectTab('{{ $key }}')" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        @class(['min-h-10 rounded-lg px-4 py-1.5 text-sm font-medium transition', 'bg-white text-stone-900 shadow-sm' => $tab === $key, 'text-stone-600 hover:text-stone-900' => $tab !== $key])>
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </nav>

    @include('livewire.stays.show.'.$tab, ['canEdit' => $canEdit])

    <x-modal :show="$editing" title="Modifier le séjour" close="closeEdit">
        <form id="stay-edit" wire:submit="save" class="space-y-4">
            <x-field label="Nom" for="edit-name" error="name">
                <input id="edit-name" type="text" wire:model="name" maxlength="100" class="form-input">
            </x-field>
            <x-field label="Lieu" for="edit-place" error="place">
                <input id="edit-place" type="text" wire:model="place" maxlength="150" class="form-input">
            </x-field>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Du" for="edit-from" error="startsOn">
                    <input id="edit-from" type="date" wire:model="startsOn" class="form-input">
                </x-field>
                <x-field label="Au" for="edit-to" error="endsOn">
                    <input id="edit-to" type="date" wire:model="endsOn" class="form-input">
                </x-field>
            </div>
            <x-field label="Notes" for="edit-notes" error="notes" help="Adresse, code de la boîte à clés, qui apporte quoi…">
                <textarea id="edit-notes" wire:model="notes" rows="3" maxlength="2000" class="form-input"></textarea>
            </x-field>
            {{-- Lot 40 (40.3) : « pas de four au chalet ». --}}
            <fieldset class="space-y-2" data-stay-equipment>
                <legend class="form-label">Équipement sur place</legend>
                <label class="flex items-center gap-2 text-sm text-stone-700">
                    <input type="checkbox" wire:model.live="sameEquipment" class="form-checkbox"> Comme à la maison
                </label>
                @unless ($sameEquipment)
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach (\App\Services\Recipes\KitchenEquipment::ITEMS as $key => [$label])
                            <label wire:key="stay-eq-{{ $key }}" class="flex min-h-10 cursor-pointer items-center gap-2 rounded-lg px-3 py-1.5 text-sm ring-1 ring-stone-200 has-checked:bg-herb-50 has-checked:ring-herb-300">
                                <input type="checkbox" wire:model="equipment" value="{{ $key }}" class="form-checkbox"> {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <p class="text-xs text-stone-500">Les recettes qui demandent autre chose ne sont plus proposées pour ce séjour.</p>
                @endunless
            </fieldset>
        </form>
        <x-slot:footer>
            <button type="button" wire:click="delete" wire:confirm="Supprimer le séjour « {{ $stay->name }} », ses repas, sa liste de courses et ses comptes ?" class="btn btn-ghost mr-auto text-red-700 hover:bg-red-50">Supprimer</button>
            <button type="button" wire:click="closeEdit" class="btn btn-ghost">Annuler</button>
            <button type="submit" form="stay-edit" class="btn btn-primary">Enregistrer</button>
        </x-slot:footer>
    </x-modal>
</div>
