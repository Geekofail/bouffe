{{-- Fiche recette : fenêtre « Ajouter au planning » (lot 36). --}}
{{-- ============================================================ Ajout au planning --}}
<x-modal :show="$showPlan" title="Ajouter au planning" close="closePlan">
    <form id="plan-form" wire:submit="plan" class="grid gap-4 sm:grid-cols-3">
        <x-field label="Date" for="plan-date" error="planDate">
            <input id="plan-date" type="date" wire:model="planDate" class="form-input">
        </x-field>
        <x-field label="Créneau" for="plan-slot" error="planSlotId">
            <select id="plan-slot" wire:model="planSlotId" class="form-input">
                @foreach ($this->activeSlots as $slot)
                    <option value="{{ $slot->id }}">{{ $slot->name }}</option>
                @endforeach
            </select>
        </x-field>
        <x-field label="Portions" for="plan-servings" error="planServings">
            <input id="plan-servings" type="number" min="0.5" step="0.5" max="50" wire:model="planServings" class="form-input">
        </x-field>
    </form>
    <x-slot:footer>
        <button type="button" wire:click="closePlan" class="btn btn-secondary">Annuler</button>
        <button type="submit" form="plan-form" class="btn btn-primary">Ajouter</button>
    </x-slot:footer>
</x-modal>
