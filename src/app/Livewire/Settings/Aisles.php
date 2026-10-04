<?php

namespace App\Livewire\Settings;

use App\Models\Aisle;
use App\Support\Palette;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Rayons')]
class Aisles extends Component
{
    use \App\Support\Concerns\GuardsCatalog;

    public string $newName = '';

    public string $newColor = 'green';

    public ?int $editingId = null;

    public string $editName = '';

    public string $editColor = Palette::DEFAULT;

    public function add(): void
    {
        if (! $this->catalogEditable()) {
            return;
        }

        $this->newName = trim($this->newName);

        $this->validate([
            'newName' => ['required', 'string', 'max:100', Rule::unique('aisles', 'name')],
            'newColor' => ['required', Rule::in(Palette::keys())],
        ], [], ['newName' => 'nom du rayon']);

        $aisle = Aisle::create(['name' => $this->newName, 'color' => $this->newColor]);

        $this->reset('newName');
        $this->dispatch('notify', message: "Rayon « {$aisle->name} » ajouté en fin de liste.");
    }

    public function edit(Aisle $aisle): void
    {
        $this->resetErrorBag();
        $this->editingId = $aisle->id;
        $this->editName = $aisle->name;
        $this->editColor = $aisle->color;
    }

    public function update(): void
    {
        if (! $this->catalogEditable()) {
            return;
        }

        $this->editName = trim($this->editName);

        $this->validate([
            'editName' => ['required', 'string', 'max:100', Rule::unique('aisles', 'name')->ignore($this->editingId)],
            'editColor' => ['required', Rule::in(Palette::keys())],
        ], [], ['editName' => 'nom du rayon']);

        Aisle::findOrFail($this->editingId)->update(['name' => $this->editName, 'color' => $this->editColor]);

        $this->cancelEdit();
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editName', 'editColor');
        $this->resetErrorBag();
    }

    /** Appelé par wire:sort après un glisser-déposer. */
    public function sort(int|string $id, int $position): void
    {
        if (! $this->catalogEditable()) {
            return;
        }

        Aisle::moveToPosition($id, $position);
    }

    public function delete(Aisle $aisle): void
    {
        if (! $this->catalogEditable()) {
            return;
        }

        $count = $aisle->ingredients()->count();

        if ($count > 0) {
            $this->dispatch('notify', type: 'warning', message: "Impossible de supprimer « {$aisle->name} » : {$count} ingrédient".($count > 1 ? 's l\'utilisent' : ' l\'utilise').'.');

            return;
        }

        $aisle->delete();
        $this->dispatch('notify', message: "Rayon « {$aisle->name} » supprimé.");
    }

    public function render()
    {
        return view('livewire.settings.aisles', [
            'aisles' => Aisle::query()->ordered()->withCount('ingredients')->get(),
        ]);
    }
}
