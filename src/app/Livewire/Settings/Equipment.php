<?php

namespace App\Livewire\Settings;

use App\Models\Recipe;
use App\Services\Recipes\KitchenEquipment;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Paramètres → Équipement (lot 40, 40.3, Q57) : ce que la cuisine de la maison permet.
 * « Remplir la semaine » et « Autre idée » écartent les recettes qui demandent ce qu'on n'a pas.
 */
#[Title('Équipement')]
class Equipment extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;

    /** @var list<string> */
    public array $items = [];

    public function mount(KitchenEquipment $equipment): void
    {
        $this->items = $equipment->available();
    }

    public function save(KitchenEquipment $equipment): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $equipment->saveHome($this->items);
        unset($this->impossible);
        $this->dispatch('notify', message: 'Équipement enregistré.');
    }

    /** Recettes impossibles avec l'équipement enregistré. */
    #[Computed]
    public function impossible(): \Illuminate\Support\Collection
    {
        $equipment = app(KitchenEquipment::class);

        return Recipe::query()->active()->with('steps')->orderBy('title')->get()
            ->map(fn (Recipe $recipe) => ['recipe' => $recipe, 'missing' => $equipment->missing($recipe)])
            ->filter(fn (array $row) => $row['missing'] !== [])
            ->values();
    }

    public function render()
    {
        return view('livewire.settings.equipment', ['labels' => KitchenEquipment::ITEMS]);
    }
}
