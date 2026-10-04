<?php

namespace App\Livewire\Settings;

use App\Livewire\Forms\UnitForm;
use App\Models\Unit;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Unités')]
class Units extends Component
{
    use \App\Support\Concerns\GuardsCatalog;

    /** Unités de référence des conversions : non supprimables. */
    public const PROTECTED_CODES = ['g', 'ml', 'piece'];

    public bool $showForm = false;

    public UnitForm $form;

    public function create(): void
    {
        $this->form->reset();
        $this->form->resetErrorBag();
        $this->showForm = true;
    }

    public function edit(Unit $unit): void
    {
        $this->form->resetErrorBag();
        $this->form->setUnit($unit);
        $this->showForm = true;
    }

    public function save(): void
    {
        if (! $this->catalogEditable()) {
            return;
        }

        $isNew = $this->form->unit === null;
        $unit = $this->form->save();

        $this->closeForm();
        $this->dispatch('notify', message: $isNew ? "Unité « {$unit->label} » ajoutée." : "Unité « {$unit->label} » modifiée.");
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->form->reset();
        $this->form->resetErrorBag();
    }

    public function sort(int|string $id, int $position): void
    {
        if (! $this->catalogEditable()) {
            return;
        }

        Unit::moveToPosition($id, $position);
    }

    public function delete(Unit $unit): void
    {
        if (! $this->catalogEditable()) {
            return;
        }

        if (in_array($unit->code, self::PROTECTED_CODES, true)) {
            $this->dispatch('notify', type: 'warning', message: "« {$unit->label} » est une unité de référence : elle ne peut pas être supprimée.");

            return;
        }

        if (($count = $unit->usageCount()) > 0) {
            $this->dispatch('notify', type: 'warning', message: "Impossible de supprimer « {$unit->label} » : elle est utilisée {$count} fois (ingrédients ou recettes).");

            return;
        }

        $unit->delete();
        $this->dispatch('notify', message: "Unité « {$unit->label} » supprimée.");
    }

    public function render()
    {
        return view('livewire.settings.units', [
            'units' => Unit::query()->ordered()->withCount('ingredients')->get(),
        ]);
    }
}
