<div>
    <x-modal :show="$show" title="Collections" close="close">
        <div class="space-y-4">
            @if ($collections->isEmpty())
                <p class="text-sm text-stone-600">Aucune collection pour l'instant : créez-en une ci-dessous, la recette y sera rangée.</p>
            @else
                <fieldset>
                    <legend class="form-label">Ranger « {{ $recipe->title }} » dans :</legend>
                    <ul class="divide-y divide-stone-100">
                        @foreach ($collections as $collection)
                            <li wire:key="pick-collection-{{ $collection->id }}">
                                <label class="flex min-h-11 cursor-pointer items-center gap-3 py-2">
                                    <input type="checkbox" wire:click="toggle({{ $collection->id }})" @checked(in_array($collection->id, $selected, true)) class="form-checkbox size-5">
                                    <span class="flex-1 text-stone-900">{{ $collection->name }}</span>
                                    @if ($collection->isShared()) <x-badge color="violet">Partagée</x-badge> @endif
                                </label>
                            </li>
                        @endforeach
                    </ul>
                </fieldset>
            @endif

            <form wire:submit="createAndAdd" class="flex items-end gap-2">
                <x-field label="Nouvelle collection" for="new-collection-name" error="newName" class="flex-1">
                    <input id="new-collection-name" type="text" wire:model="newName" maxlength="100" placeholder="ex. Noël" class="form-input">
                </x-field>
                <button type="submit" class="btn btn-secondary mb-0.5"><x-icon name="plus" class="size-4" /> Créer</button>
            </form>

            <a href="{{ route('recipes.collections') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">
                Toutes les collections <x-icon name="chevron-right" class="size-4" />
            </a>
        </div>
    </x-modal>
</div>
