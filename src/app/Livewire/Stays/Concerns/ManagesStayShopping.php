<?php

namespace App\Livewire\Stays\Concerns;

use App\Models\StockItem;
use App\Services\Stays\StayPacking;
use App\Services\Stays\StayShopping;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;

/**
 * Séjour — courses (34.2) et « à emporter » (34.4).
 */
trait ManagesStayShopping
{
    public string $packSearch = '';

    /** Quantités à emporter saisies, par article du stock. @var array<int, string> */
    public array $packQuantities = [];

    /** Ce qui revient, par article emporté. @var array<int, string|bool> */
    public array $returned = [];

    public function createList(StayShopping $shopping): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        // Lot 42 (42.1) : la liste du séjour est rangée chez le foyer qui organise.
        $this->asOrganizer(fn () => $shopping->create($this->stay));
        $this->stay->unsetRelation('shoppingList');
        $this->dispatch('notify', message: 'Liste de courses du séjour prête.');
    }

    public function refreshList(StayShopping $shopping): void
    {
        if (! $this->allowedToEdit() || ! $this->stay->shoppingList) {
            return;
        }

        $this->asOrganizer(fn () => $shopping->sync($this->stay->shoppingList));
        $this->dispatch('notify', message: 'Liste mise à jour d\'après les repas du séjour.');
    }

    public function pack(int $stockItemId, StayPacking $packing, StayShopping $shopping): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        try {
            $packing->pack($this->stay, StockItem::query()->findOrFail($stockItemId), $this->packQuantities[$stockItemId] ?? null);
        } catch (InvalidArgumentException $e) {
            $this->addError('pack', $e->getMessage());

            return;
        }

        unset($this->packQuantities[$stockItemId], $this->candidates, $this->packed);
        $this->resyncList($shopping);
    }

    public function unpack(int $packedId, StayPacking $packing, StayShopping $shopping): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        try {
            $packing->unpack($packing->mine($this->stay)->findOrFail($packedId));
        } catch (InvalidArgumentException $e) {
            $this->addError('pack', $e->getMessage());

            return;
        }

        unset($this->candidates, $this->packed);
        $this->resyncList($shopping);
    }

    public function depart(StayPacking $packing): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $count = $packing->depart($this->stay);
        $this->stay->refresh();
        unset($this->packingState, $this->packed);
        $this->dispatch('notify', message: $count.' article'.($count > 1 ? 's' : '').' retiré'.($count > 1 ? 's' : '').' du stock. Bon séjour !');
    }

    public function returnHome(StayPacking $packing, StayShopping $shopping): void
    {
        if (! $this->allowedToEdit()) {
            return;
        }

        $count = $packing->returnHome($this->stay, $this->returned);
        $this->returned = [];
        $this->stay->refresh();
        unset($this->packingState, $this->packed);
        $this->resyncList($shopping);
        $this->dispatch('notify', message: $count ? $count.' article'.($count > 1 ? 's' : '').' remis dans le stock.' : 'Rien n\'est revenu : le stock ne change pas.');
    }

    private function resyncList(StayShopping $shopping): void
    {
        if ($list = $this->stay->shoppingList()->first()) {
            $this->asOrganizer(fn () => $shopping->sync($list));
        }
    }

    private function asOrganizer(\Closure $callback): mixed
    {
        return app(\App\Services\Stays\StayCoorganizers::class)->asOrganizer($this->stay, $callback);
    }

    /** Résumé de la liste du séjour, lu chez le foyer qui organise. */
    #[Computed]
    public function shoppingSummary(): array
    {
        return $this->asOrganizer(fn () => app(StayShopping::class)->summary($this->stay));
    }

    /** Nos articles emportés (lot 42 : chaque foyer, de son stock). */
    #[Computed]
    public function packed(): Collection
    {
        return app(StayPacking::class)->mine($this->stay)->with('unit')->get();
    }

    /** Notre départ et notre retour. */
    #[Computed]
    public function packingState(): object
    {
        return app(StayPacking::class)->state($this->stay);
    }

    /** @return Collection<int, StockItem> */
    #[Computed]
    public function candidates(): Collection
    {
        return app(StayPacking::class)->candidates($this->stay, $this->packSearch);
    }

    public function updatedPackSearch(): void
    {
        unset($this->candidates);
    }
}
