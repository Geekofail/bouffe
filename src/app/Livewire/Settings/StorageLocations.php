<?php

namespace App\Livewire\Settings;

use App\Enums\LocationType;
use App\Models\StorageLocation;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Emplacements')]
class StorageLocations extends Component
{
    public string $newName = '';

    public string $newType = 'ambient';

    public ?int $editingId = null;

    public string $editName = '';

    public string $editType = 'ambient';

    public function add(): void
    {
        $this->newName = trim($this->newName);
        $this->validate([
            'newName' => ['required', 'string', 'max:60', \App\Support\HouseholdRule::unique('storage_locations', 'name')],
            'newType' => ['required', Rule::in(array_column(LocationType::cases(), 'value'))],
        ], [], ['newName' => 'nom']);

        $location = StorageLocation::create(['name' => $this->newName, 'type' => $this->newType]);
        $this->reset('newName');
        $this->dispatch('notify', message: "Emplacement « {$location->name} » ajouté.");
    }

    public function edit(int $id): void
    {
        $location = StorageLocation::findOrFail($id);
        $this->resetErrorBag();
        $this->editingId = $location->id;
        $this->editName = $location->name;
        $this->editType = $location->type->value;
    }

    public function update(): void
    {
        $this->editName = trim($this->editName);
        $this->validate([
            'editName' => ['required', 'string', 'max:60', \App\Support\HouseholdRule::unique('storage_locations', 'name')->ignore($this->editingId)],
            'editType' => ['required', Rule::in(array_column(LocationType::cases(), 'value'))],
        ], [], ['editName' => 'nom']);

        StorageLocation::findOrFail($this->editingId)->update(['name' => $this->editName, 'type' => $this->editType]);
        $this->cancelEdit();
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editName', 'editType');
        $this->resetErrorBag();
    }

    public function sort(int|string $id, int $position): void
    {
        StorageLocation::moveToPosition($id, $position);
    }

    public function delete(int $id): void
    {
        $location = StorageLocation::findOrFail($id);
        $count = $location->items()->active()->count();

        if ($count > 0) {
            $this->dispatch('notify', type: 'warning', message: "Impossible de supprimer « {$location->name} » : {$count} article".($count > 1 ? 's y sont' : ' y est').' rangé'.($count > 1 ? 's' : '').'. Déplacez-les d\'abord.');

            return;
        }

        if ($location->items()->exists()) {
            $this->dispatch('notify', type: 'warning', message: "« {$location->name} » figure dans l'historique du stock : renommez-le plutôt.");

            return;
        }

        $location->delete();
        $this->dispatch('notify', message: "Emplacement « {$location->name} » supprimé.");
    }

    public function render()
    {
        return view('livewire.settings.storage-locations', [
            'locations' => StorageLocation::query()->ordered()->withCount(['items' => fn ($q) => $q->active()])->get(),
            'types' => LocationType::cases(),
        ]);
    }
}
