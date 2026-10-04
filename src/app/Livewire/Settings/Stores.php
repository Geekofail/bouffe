<?php

namespace App\Livewire\Settings;

use App\Models\Store;
use App\Services\Shopping\StoreLayout;
use App\Support\Palette;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Paramètres → Magasins (15.3).
 *
 * Un magasin sert à une seule chose, mais elle compte : ranger la liste de courses dans l'ordre
 * où on traverse ce magasin-là. On y règle aussi les rayons qui n'existent pas ici — leurs
 * articles ne disparaissent pas, ils sont montrés à part dans la liste.
 */
#[Title('Magasins')]
class Stores extends Component
{
    #[Url(as: 'magasin', except: null)]
    public ?int $storeId = null;

    public string $newName = '';

    public string $newColor = 'green';

    public ?int $editingId = null;

    public string $editName = '';

    public string $editColor = Palette::DEFAULT;

    public string $editNote = '';

    public function mount(): void
    {
        $this->storeId ??= Store::preferred()?->id;
    }

    public function add(StoreLayout $layout): void
    {
        $this->newName = trim($this->newName);

        $this->validate([
            'newName' => ['required', 'string', 'max:100', \App\Support\HouseholdRule::unique('stores', 'name')],
            'newColor' => ['required', Rule::in(Palette::keys())],
        ], [], ['newName' => 'nom du magasin']);

        $store = Store::create([
            'name' => $this->newName,
            'color' => $this->newColor,
            'is_default' => Store::count() === 0,    // le premier magasin devient celui par défaut
        ]);

        $layout->sync($store);

        $this->reset('newName');
        $this->storeId = $store->id;
        $this->dispatch('notify', message: "Magasin « {$store->name} » ajouté. Rangez ses rayons dans l'ordre de votre parcours.");
    }

    public function select(int $id): void
    {
        $this->storeId = $id;
        $this->cancelEdit();
    }

    public function edit(Store $store): void
    {
        $this->resetErrorBag();
        $this->editingId = $store->id;
        $this->editName = $store->name;
        $this->editColor = $store->color;
        $this->editNote = (string) $store->note;
    }

    public function update(): void
    {
        $this->editName = trim($this->editName);

        $this->validate([
            'editName' => ['required', 'string', 'max:100', \App\Support\HouseholdRule::unique('stores', 'name')->ignore($this->editingId)],
            'editColor' => ['required', Rule::in(Palette::keys())],
            'editNote' => ['nullable', 'string', 'max:200'],
        ], [], ['editName' => 'nom du magasin']);

        Store::findOrFail($this->editingId)->update([
            'name' => $this->editName,
            'color' => $this->editColor,
            'note' => trim($this->editNote) ?: null,
        ]);

        $this->cancelEdit();
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editName', 'editColor', 'editNote');
        $this->resetErrorBag();
    }

    public function makeDefault(Store $store): void
    {
        Store::query()->update(['is_default' => false]);
        $store->update(['is_default' => true]);

        $this->dispatch('notify', message: "« {$store->name} » sera proposé pour les nouvelles listes.");
    }

    public function delete(Store $store): void
    {
        $lists = $store->shoppingLists()->count();
        $store->delete();

        $this->storeId = Store::preferred()?->id;
        $this->dispatch('notify', message: "Magasin « {$store->name} » supprimé."
            .($lists > 0 ? " Les {$lists} liste(s) concernées reprennent l'ordre général des rayons." : ''));
    }

    /** Glisser-déposer des rayons de ce magasin. */
    public function sort(int|string $aisleId, int $position, StoreLayout $layout): void
    {
        if ($store = $this->store()) {
            $layout->move($store, (int) $aisleId, $position);
        }
    }

    public function toggleHidden(int $aisleId, StoreLayout $layout): void
    {
        if (! $store = $this->store()) {
            return;
        }

        $hidden = $layout->toggleHidden($store, $aisleId);

        $this->dispatch('notify', message: $hidden
            ? 'Rayon masqué : ses articles seront regroupés sous « Ailleurs » dans la liste.'
            : 'Rayon de nouveau pris dans le parcours.');
    }

    public function resetOrder(StoreLayout $layout): void
    {
        if ($store = $this->store()) {
            $layout->reset($store);
            $this->dispatch('notify', message: "Ordre général des rayons repris pour « {$store->name} ».");
        }
    }

    private function store(): ?Store
    {
        return $this->storeId ? Store::find($this->storeId) : null;
    }

    public function render(StoreLayout $layout)
    {
        $store = $this->store();

        return view('livewire.settings.stores', [
            'stores' => Store::query()->ordered()->withCount('shoppingLists')->get(),
            'store' => $store,
            'rows' => $store ? $layout->rows($store) : collect(),
        ]);
    }
}
