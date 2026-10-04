<?php

namespace App\Livewire\Stock;

use App\Enums\ExpiryType;
use App\Models\ShoppingList;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Services\Stock\PutAwayService;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * « Ranger les courses » (9.6) : les articles cochés d'une liste entrent dans le stock en un écran.
 */
class PutAway extends Component
{
    #[Locked]
    public int $listId;

    /** @var list<array{item_id: int, label: string, ingredient_id: int|null, presence: bool, include: bool, quantity: string, unit_id: int|null, storage_location_id: int|null, expires_on: string|null, expiry_type: string}> */
    public array $rows = [];

    public function mount(ShoppingList $shoppingList, PutAwayService $service): void
    {
        $this->listId = $shoppingList->id;
        $this->rows = $service->proposal($shoppingList);
    }

    public function shiftDate(int $index, string $shortcut): void
    {
        if (isset($this->rows[$index])) {
            $this->rows[$index]['expires_on'] = match ($shortcut) {
                '3d' => Carbon::today()->addDays(3)->toDateString(),
                '1w' => Carbon::today()->addWeek()->toDateString(),
                '1m' => Carbon::today()->addMonth()->toDateString(),
                default => null,
            };
        }
    }

    public function setAll(bool $include): void
    {
        foreach ($this->rows as $index => $row) {
            if ($row['ingredient_id']) {
                $this->rows[$index]['include'] = $include;
            }
        }
    }

    public function store(PutAwayService $service): void
    {
        $this->validate([
            'rows.*.storage_location_id' => 'nullable|integer|exists:storage_locations,id',
            'rows.*.expires_on' => 'nullable|date',
            'rows.*.expiry_type' => 'in:dlc,ddm,none',
        ], [], ['rows.*.expires_on' => 'date', 'rows.*.storage_location_id' => 'emplacement']);

        try {
            $count = $service->store(ShoppingList::findOrFail($this->listId), $this->rows);
        } catch (InvalidArgumentException $e) {
            $this->addError('rows', $e->getMessage());

            return;
        }

        session()->flash('status', $count > 0 ? "{$count} article".($count > 1 ? 's rangés' : ' rangé').' dans le stock.' : 'Courses rangées (aucun article suivi dans le stock).');
        $this->redirectRoute('stock.index', navigate: true);
    }

    public function render()
    {
        $list = ShoppingList::findOrFail($this->listId);

        return view('livewire.stock.put-away', [
            'list' => $list,
            'locations' => StorageLocation::query()->ordered()->get(['id', 'name']),
            'units' => Unit::query()->orderBy('sort_order')->get(['id', 'label']),
            'expiryTypes' => ExpiryType::cases(),
            'includedCount' => collect($this->rows)->where('include', true)->count(),
        ])->title('Ranger les courses');
    }
}
