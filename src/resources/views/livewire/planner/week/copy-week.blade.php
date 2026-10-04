{{-- Planning : fenêtre « Copier la semaine » (lot 36). --}}
{{-- ============================================================ Copie de la semaine --}}
<x-modal :show="$showCopy" title="Copier la semaine" close="closeCopy">
    <form id="copy-form" wire:submit="copyWeek" class="space-y-4">
        <x-field label="Vers la semaine du" for="copy-target" error="copyTarget">
            <select id="copy-target" wire:model="copyTarget" class="form-input">
                @foreach ($this->copyTargets() as $target)
                    <option value="{{ $target->toDateString() }}">
                        {{ $target->locale('fr')->isoFormat('D MMMM') }} au {{ $target->copy()->addDays(6)->locale('fr')->isoFormat('D MMMM YYYY') }}
                    </option>
                @endforeach
            </select>
        </x-field>

        <fieldset class="space-y-2 text-sm">
            <label class="flex items-start gap-2">
                <input type="radio" wire:model="copyMode" value="add" name="copy-mode" class="mt-0.5 accent-brand-600">
                <span><strong>Ajouter</strong> aux repas déjà prévus cette semaine-là</span>
            </label>
            <label class="flex items-start gap-2">
                <input type="radio" wire:model="copyMode" value="replace" name="copy-mode" class="mt-0.5 accent-brand-600">
                <span><strong>Remplacer</strong> : la semaine de destination est vidée avant la copie</span>
            </label>
        </fieldset>
    </form>

    <x-slot:footer>
        <button type="button" wire:click="closeCopy" class="btn btn-secondary">Annuler</button>
        <button type="submit" form="copy-form" class="btn btn-primary">Copier</button>
    </x-slot:footer>
</x-modal>
