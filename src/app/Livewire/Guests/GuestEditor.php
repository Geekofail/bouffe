<?php

namespace App\Livewire\Guests;

use App\Enums\RestrictionType;
use App\Models\Guest;
use App\Models\Ingredient;
use App\Models\Tag;
use App\Services\Planning\GuestManager;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Fenêtre de création / modification d'un invité et de ses contraintes alimentaires.
 */
class GuestEditor extends Component
{
    public bool $show = false;

    public ?int $guestId = null;

    public string $name = '';

    public string $groupName = '';

    public bool $isChild = false;

    /** Appétit (lot 32) : vide = selon l'âge (petit pour un enfant, sinon normal). */
    public string $appetite = '';

    public string $notes = '';

    /** @var list<array{type: string, ingredient: string, tag_id: int|string|null, note: string}> */
    public array $restrictions = [];

    #[On('edit-guest')]
    public function open(?int $guestId = null, string $name = ''): void
    {
        $this->resetErrorBag();
        $guest = $guestId ? Guest::with('restrictions.ingredient')->findOrFail($guestId) : null;

        $this->guestId = $guest?->id;
        $this->name = $guest?->name ?? $name;
        $this->groupName = (string) $guest?->group_name;
        $this->isChild = (bool) $guest?->is_child;
        $this->appetite = (string) $guest?->appetite;
        $this->notes = (string) $guest?->notes;
        $this->restrictions = $guest
            ? $guest->restrictions->map(fn ($r) => [
                'type' => $r->type->value,
                'ingredient' => (string) $r->ingredient?->name,
                'tag_id' => $r->tag_id,
                'note' => (string) $r->note,
            ])->values()->all()
            : [];
        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
    }

    public function addRestriction(string $type): void
    {
        if (RestrictionType::tryFrom($type)) {
            $this->restrictions[] = ['type' => $type, 'ingredient' => '', 'tag_id' => null, 'note' => ''];
        }
    }

    public function removeRestriction(int $index): void
    {
        unset($this->restrictions[$index]);
        $this->restrictions = array_values($this->restrictions);
    }

    public function save(GuestManager $manager): void
    {
        $this->validate([
            'name' => 'required|string|max:100',
            'groupName' => 'nullable|string|max:50',
            'appetite' => 'nullable|in:'.implode(',', array_keys(\App\Services\Planning\Appetites::LEVELS)),
            'notes' => 'nullable|string|max:2000',
            'restrictions.*.note' => 'nullable|string|max:150',
        ], [], ['name' => 'nom', 'groupName' => 'groupe', 'restrictions.*.note' => 'précision']);

        try {
            $guest = $manager->save(
                $this->guestId ? Guest::findOrFail($this->guestId) : null,
                ['name' => $this->name, 'group_name' => $this->groupName, 'is_child' => $this->isChild, 'appetite' => $this->appetite, 'notes' => $this->notes],
                $this->restrictions,
            );
        } catch (InvalidArgumentException $e) {
            $this->addError('restrictions', $e->getMessage());

            return;
        }

        $message = $this->guestId ? "« {$guest->name} » modifié." : "« {$guest->name} » ajouté au carnet.";

        if ($manager->createdIngredients !== []) {
            $message .= ' Nouvel ingrédient créé : '.implode(', ', $manager->createdIngredients).' (rayon Divers).';
        }

        $this->show = false;
        $this->dispatch('guest-saved', guestId: $guest->id);
        $this->dispatch('notify', message: $message);
    }

    #[Computed]
    public function ingredientNames(): Collection
    {
        return $this->show ? Ingredient::query()->orderBy('name')->pluck('name') : collect();
    }

    #[Computed]
    public function tags(): Collection
    {
        return Tag::query()->ordered()->get(['id', 'name']);
    }

    #[Computed]
    public function groupNames(): array
    {
        return $this->show ? app(GuestManager::class)->groupNames() : [];
    }

    public function render()
    {
        return view('livewire.guests.guest-editor');
    }
}
