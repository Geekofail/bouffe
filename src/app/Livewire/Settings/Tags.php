<?php

namespace App\Livewire\Settings;

use App\Models\Tag;
use App\Support\Palette;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Catégories')]
class Tags extends Component
{
    public string $newName = '';

    public string $newColor = 'orange';

    public ?int $editingId = null;

    public string $editName = '';

    public string $editColor = Palette::DEFAULT;

    public function add(): void
    {
        $this->newName = trim($this->newName);

        $this->validate([
            'newName' => ['required', 'string', 'max:50', \App\Support\HouseholdRule::unique('tags', 'name'), $this->uniqueSlug()],
            'newColor' => ['required', Rule::in(Palette::keys())],
        ], [], ['newName' => 'nom de la catégorie']);

        $tag = Tag::create(['name' => $this->newName, 'color' => $this->newColor]);

        $this->reset('newName');
        $this->dispatch('notify', message: "Catégorie « {$tag->name} » ajoutée.");
    }

    public function edit(Tag $tag): void
    {
        $this->resetErrorBag();
        $this->editingId = $tag->id;
        $this->editName = $tag->name;
        $this->editColor = $tag->color;
    }

    public function update(): void
    {
        $this->editName = trim($this->editName);

        $this->validate([
            'editName' => ['required', 'string', 'max:50', \App\Support\HouseholdRule::unique('tags', 'name')->ignore($this->editingId), $this->uniqueSlug($this->editingId)],
            'editColor' => ['required', Rule::in(Palette::keys())],
        ], [], ['editName' => 'nom de la catégorie']);

        Tag::findOrFail($this->editingId)->update(['name' => $this->editName, 'color' => $this->editColor]);

        $this->cancelEdit();
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editName', 'editColor');
        $this->resetErrorBag();
    }

    public function delete(Tag $tag): void
    {
        // Les recettes associées perdent simplement cette catégorie (suppression en cascade du lien).
        $guests = \App\Models\GuestRestriction::query()->where('tag_id', $tag->id)->whereHas('guest')->with('guest')->get()->pluck('guest.name')->unique();

        if ($guests->isNotEmpty()) {
            $this->dispatch('notify', type: 'warning', message: "Impossible de supprimer «\u{00A0}{$tag->name}\u{00A0}» : régime de ".$guests->join(', ', ' et ').'.');

            return;
        }

        $tag->delete();
        $this->dispatch('notify', message: "Catégorie « {$tag->name} » supprimée.");
    }

    /** « Végétarien » et « vegetarien » donneraient le même slug : on refuse. */
    private function uniqueSlug(?int $ignoreId = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($ignoreId) {
            $exists = Tag::query()
                ->where('slug', Str::slug((string) $value))
                ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists();

            if ($exists) {
                $fail('Une catégorie très proche existe déjà.');
            }
        };
    }

    public function render()
    {
        return view('livewire.settings.tags', [
            'tags' => Tag::query()->ordered()->get(),
        ]);
    }
}
