<div>
    <x-page-header title="Paramètres" subtitle="Les listes de référence utilisées par les recettes et la liste de courses." />
    <x-settings-nav />

    <div class="card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3">
            <div>
                <h2 class="font-display font-semibold text-stone-900">Unités de mesure</h2>
                <p class="text-sm text-stone-500">Les unités d'une même famille (masse ou volume) sont converties automatiquement entre elles.</p>
            </div>
            <button type="button" wire:click="create" class="btn btn-primary"><x-icon name="plus" class="size-4" /> Ajouter</button>
        </div>

        <ul wire:sort="sort" class="divide-y divide-stone-100">
            @foreach ($units as $unit)
                <li wire:key="unit-{{ $unit->id }}" wire:sort:item="{{ $unit->id }}" class="flex items-center gap-3 bg-white px-4 py-2.5">
                    <span wire:sort:handle class="cursor-grab text-stone-300 hover:text-stone-500" title="Déplacer">
                        <x-icon name="grip" class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-stone-800">
                            {{ $unit->label }}
                            @if ($unit->label_plural && $unit->label_plural !== $unit->label)
                                <span class="font-normal text-stone-500">/ {{ $unit->label_plural }}</span>
                            @endif
                        </p>
                        <p class="text-xs text-stone-500">
                            <code>{{ $unit->code }}</code> · {{ $unit->type->label() }}
                            @if ($unit->factor_to_base !== null)
                                · 1 {{ $unit->label }} = {{ app(\App\Services\QuantityFormatter::class)->number((float) $unit->factor_to_base, 4) }} {{ $unit->type->baseUnitCode() }}
                            @endif
                            @if ($unit->ingredients_count)
                                · {{ $unit->ingredients_count }} ingrédient{{ $unit->ingredients_count > 1 ? 's' : '' }}
                            @endif
                        </p>
                    </div>
                    <div wire:sort:ignore class="flex">
                        <button type="button" wire:click="edit({{ $unit->id }})" class="btn btn-ghost px-2" title="Modifier">
                            <x-icon name="edit" class="size-4" /><span class="sr-only">Modifier {{ $unit->label }}</span>
                        </button>
                        @unless (in_array($unit->code, \App\Livewire\Settings\Units::PROTECTED_CODES, true))
                            <button type="button" wire:click="delete({{ $unit->id }})"
                                    wire:confirm="Supprimer l'unité « {{ $unit->label }} » ?"
                                    class="btn btn-ghost px-2 hover:text-red-600" title="Supprimer">
                                <x-icon name="delete" class="size-4" /><span class="sr-only">Supprimer {{ $unit->label }}</span>
                            </button>
                        @else
                            <span class="w-8"></span>
                        @endunless
                    </div>
                </li>
            @endforeach
        </ul>
    </div>

    <x-modal :show="$showForm" :title="$form->unit ? 'Modifier l\'unité' : 'Nouvelle unité'">
        <form id="unit-form" wire:submit="save" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Libellé" for="unit-label" error="form.label">
                    <input id="unit-label" type="text" wire:model="form.label" placeholder="ex. verre" class="form-input" autofocus>
                </x-field>
                <x-field label="Pluriel" for="unit-plural" error="form.label_plural" optional>
                    <input id="unit-plural" type="text" wire:model="form.label_plural" placeholder="ex. verres" class="form-input">
                </x-field>
            </div>

            <x-field label="Code" for="unit-code" error="form.code" help="Identifiant court, sans espace ni accent.">
                <input id="unit-code" type="text" wire:model="form.code" placeholder="ex. verre" class="form-input sm:w-40" @disabled($form->isProtected())>
            </x-field>

            <x-field label="Famille" for="unit-type" error="form.type">
                <select id="unit-type" wire:model.live="form.type" class="form-input" @disabled($form->isProtected())>
                    @foreach (\App\Enums\UnitType::cases() as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </select>
            </x-field>

            @if (\App\Enums\UnitType::tryFrom($form->type)?->isConvertible())
                <x-field label="Équivalence" for="unit-factor" error="form.factor_to_base">
                    <div class="flex items-center gap-2 text-sm text-stone-600">
                        <span>1 {{ $form->label ?: 'unité' }} =</span>
                        <input id="unit-factor" type="text" inputmode="decimal" wire:model="form.factor_to_base" class="form-input w-28" @disabled($form->isProtected())>
                        <span>{{ \App\Enums\UnitType::from($form->type)->baseUnitCode() }}</span>
                    </div>
                </x-field>

                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" wire:model="form.is_metric" class="form-checkbox mt-0.5">
                    <span>
                        <span class="font-medium text-stone-800">Unité métrique</span>
                        <span class="block text-stone-500">Affichée en g/kg ou ml/l selon la quantité (ex. 1 250 g → 1,25 kg). À décocher pour les cuillères.</span>
                    </span>
                </label>
            @endif
        </form>

        <x-slot:footer>
            <button type="button" wire:click="closeForm" class="btn btn-secondary">Annuler</button>
            <button type="submit" form="unit-form" class="btn btn-primary">Enregistrer</button>
        </x-slot:footer>
    </x-modal>
</div>
