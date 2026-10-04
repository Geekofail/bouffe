<?php

namespace App\Livewire\Linked;

use App\Models\ShoppingList;
use App\Services\Linked\GroupLists;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Liste groupée d'un foyer relié (26.8), vue de notre côté : nos articles, ce qui est déjà acheté
 * et ce que nous devrons rembourser.
 */
#[Title('Liste groupée')]
class GroupList extends Component
{
    #[Locked]
    public int $listId;

    public string $label = '';

    public function mount(int $list, GroupLists $lists): void
    {
        $this->listId = ($lists->findOpen($list) ?? abort(404))->id;
    }

    private function list(): ShoppingList
    {
        return app(GroupLists::class)->findOpen($this->listId) ?? abort(404);
    }

    public function add(GroupLists $lists): void
    {
        abort_unless(Auth::user()?->canEdit(), 403);
        $this->validate(['label' => 'required|string|max:150'], [], ['label' => 'article']);

        try {
            $lists->addFor($this->list(), $this->label);
        } catch (\InvalidArgumentException $e) {
            $this->addError('label', $e->getMessage());

            return;
        }

        $this->reset('label');
    }

    public function remove(int $itemId, GroupLists $lists): void
    {
        abort_unless(Auth::user()?->canEdit(), 403);
        $lists->removeFor($this->list(), $itemId);
    }

    public function render(GroupLists $lists)
    {
        $list = $this->list();
        $items = $lists->itemsFor($list);

        return view('livewire.linked.group-list', [
            'list' => $list,
            'items' => $items,
            'total' => round((float) $items->sum(fn ($i) => (float) $i->paid_price), 2),
            'missing' => $items->where('is_checked', true)->whereNull('paid_price')->count(),
            'canEdit' => (bool) Auth::user()?->canEdit(),
        ]);
    }
}
