<?php

namespace App\Livewire\Settings;

use App\Models\Aisle;
use App\Models\RecurringItem;
use App\Services\Shopping\ShoppingListManager;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Articles récurrents')]
class RecurringItems extends Component
{
    public string $label = '';

    public ?int $aisleId = null;

    public function add(ShoppingListManager $manager): void
    {
        $this->label = trim($this->label);

        $this->validate([
            'label' => ['required', 'string', 'max:200', \App\Support\HouseholdRule::unique('recurring_items', 'label')],
            'aisleId' => 'nullable|integer|exists:aisles,id',
        ], [], ['label' => 'article', 'aisleId' => 'rayon']);

        $ingredient = $manager->matchIngredient($this->label);

        RecurringItem::create([
            'label' => mb_strtoupper(mb_substr($this->label, 0, 1)).mb_substr($this->label, 1),
            'ingredient_id' => $ingredient?->id,
            'aisle_id' => $this->aisleId ?? $ingredient?->aisle_id,
        ]);

        $this->reset('label', 'aisleId');
        $this->dispatch('notify', message: 'Article récurrent ajouté : il apparaîtra dans chaque nouvelle liste.');
    }

    public function toggle(int $id): void
    {
        $item = RecurringItem::findOrFail($id);
        $item->update(['is_active' => ! $item->is_active]);
    }

    public function updateAisle(int $id, ?string $aisleId): void
    {
        RecurringItem::findOrFail($id)->update(['aisle_id' => $aisleId ?: null]);
    }

    public function delete(int $id): void
    {
        RecurringItem::findOrFail($id)->delete();
    }

    #[Computed]
    public function aisles(): Collection
    {
        return Aisle::query()->ordered()->get(['id', 'name']);
    }

    public function render()
    {
        return view('livewire.settings.recurring-items', [
            'items' => RecurringItem::query()->with('aisle')->orderBy('label')->get(),
        ]);
    }
}
