<?php

namespace App\Livewire\Guests;

use App\Models\Guest;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Invités')]
class Index extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'archives', except: false)]
    public bool $showArchived = false;

    public function create(): void
    {
        $this->dispatch('edit-guest', name: trim($this->search))->to(GuestEditor::class);
    }

    public function edit(int $guestId): void
    {
        $this->dispatch('edit-guest', guestId: $guestId)->to(GuestEditor::class);
    }

    #[On('guest-saved')]
    public function refresh(): void {}

    /** @return Collection<string, Collection<int, Guest>> invités par groupe (« » = sans groupe, en dernier) */
    public function groups(): Collection
    {
        $term = trim($this->search);

        return Guest::query()
            ->when(! $this->showArchived, fn ($q) => $q->active())
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('group_name', 'like', "%{$term}%")))
            ->with('restrictions.ingredient', 'restrictions.tag')
            ->withCount('occasions')
            ->withMax('occasions as last_visit', 'date')
            ->ordered()
            ->get()
            ->groupBy(fn (Guest $g) => (string) $g->group_name)
            ->sortKeysUsing(fn ($a, $b) => [$a === '', $a] <=> [$b === '', $b]);
    }

    public function render()
    {
        return view('livewire.guests.index', [
            'groups' => $this->groups(),
            'archivedCount' => Guest::query()->whereNotNull('archived_at')->count(),
        ]);
    }
}
