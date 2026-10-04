<?php

namespace App\Livewire\Settings;

use App\Models\MealSlot;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Créneaux de repas')]
class MealSlots extends Component
{
    public string $newName = '';

    public ?int $editingId = null;

    public string $editName = '';

    public function add(): void
    {
        $this->newName = trim($this->newName);

        $this->validate([
            'newName' => ['required', 'string', 'max:50', \App\Support\HouseholdRule::unique('meal_slots', 'name')],
        ], [], ['newName' => 'nom du créneau']);

        $slot = MealSlot::create(['name' => $this->newName, 'is_active' => true]);

        $this->reset('newName');
        $this->dispatch('notify', message: "Créneau « {$slot->name} » ajouté.");
    }

    public function edit(MealSlot $slot): void
    {
        $this->resetErrorBag();
        $this->editingId = $slot->id;
        $this->editName = $slot->name;
    }

    public function update(): void
    {
        $this->editName = trim($this->editName);

        $this->validate([
            'editName' => ['required', 'string', 'max:50', \App\Support\HouseholdRule::unique('meal_slots', 'name')->ignore($this->editingId)],
        ], [], ['editName' => 'nom du créneau']);

        MealSlot::findOrFail($this->editingId)->update(['name' => $this->editName]);

        $this->cancelEdit();
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editName');
        $this->resetErrorBag();
    }

    public function toggle(MealSlot $slot): void
    {
        if ($slot->is_active && MealSlot::active()->count() === 1) {
            $this->dispatch('notify', type: 'warning', message: 'Il faut au moins un créneau actif dans le planning.');

            return;
        }

        $slot->update(['is_active' => ! $slot->is_active]);
    }

    public function sort(int|string $id, int $position): void
    {
        MealSlot::moveToPosition($id, $position);
    }

    public function delete(MealSlot $slot): void
    {
        if ($slot->is_active && MealSlot::active()->count() === 1) {
            $this->dispatch('notify', type: 'warning', message: 'Impossible de supprimer le dernier créneau actif.');

            return;
        }

        if (($count = $slot->plannedMeals()->count()) > 0) {
            $this->dispatch('notify', type: 'warning', message: "Impossible de supprimer «\u{00A0}{$slot->name}\u{00A0}» : {$count} repas y sont planifiés. Désactivez-le plutôt.");

            return;
        }

        if (\App\Models\MealOccasion::where('meal_slot_id', $slot->id)->exists()) {
            $this->dispatch('notify', type: 'warning', message: "Impossible de supprimer «\u{00A0}{$slot->name}\u{00A0}» : des convives y sont enregistrés. Désactivez-le plutôt.");

            return;
        }

        $slot->delete();
        $this->dispatch('notify', message: "Créneau « {$slot->name} » supprimé.");
    }

    public function render()
    {
        return view('livewire.settings.meal-slots', [
            'mealSlots' => MealSlot::query()->ordered()->get(),
        ]);
    }
}
