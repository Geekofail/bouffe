<?php

namespace App\Livewire\Stock\Concerns;

use App\Enums\ExpiryLevel;
use App\Enums\StockMode;
use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\StockItem;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Services\QuantityFormatter;
use App\Services\Shopping\ShoppingListManager;
use App\Services\Stock\ExpiryCalculator;
use App\Services\Stock\StockMinimum;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;

/**
 * Stock — données affichées et bandeaux : emplacements, groupes, minimum, apprentissages (lot 30),
 * vérification, réservations des repas (découpé d'Index au lot 36).
 */
trait ProvidesStockData
{
    /** « Plus rien » → ajouter à la liste de courses en cours. */
    public function addToShoppingList(ShoppingListManager $manager): void
    {
        $offer = $this->restockOffer;
        $this->restockOffer = null;
        $list = ShoppingList::query()->active()->orderByDesc('period_start')->first();

        if (! $offer) {
            return;
        }

        if (! $list) {
            $this->dispatch('notify', type: 'warning', message: 'Aucune liste de courses en cours : générez-en une depuis la page Courses.');

            return;
        }

        $already = $list->items()->where('ingredient_id', $offer['ingredient_id'])->where('is_removed', false)->where('is_checked', false)->exists();

        if (! $already) {
            $manager->addManual($list, $offer['name']);
        }

        $this->dispatch('notify', message: $already ? "« {$offer['name']} » est déjà dans « {$list->name} »." : "« {$offer['name']} » ajouté à « {$list->name} ».");
    }

    public function dismissRestock(): void
    {
        $this->restockOffer = null;
    }

    /** @return Collection<int, StorageLocation> avec le nombre d'articles */
    #[Computed]
    public function locations(): Collection
    {
        return StorageLocation::query()->ordered()->withCount(['items' => fn ($q) => $q->active()])->get();
    }

    #[Computed]
    public function units(): Collection
    {
        return Unit::query()->orderBy('sort_order')->get(['id', 'code', 'label']);
    }

    #[Computed]
    public function ingredientNames(): Collection
    {
        return $this->showAdd ? Ingredient::query()->whereSetting('stock_mode', '!=', StockMode::None->value)->orderBy('name')->pluck('name') : collect();
    }

    /**
     * Articles groupés par ingrédient (ou plat préparé), les plus urgents d'abord.
     *
     * @return Collection<int, array{key: string, name: string, presence: bool, ingredient_id: int|null, items: Collection, level: ExpiryLevel, total: string}>
     */
    #[Computed]
    public function groups(): Collection
    {
        $expiry = app(ExpiryCalculator::class);
        $formatter = app(QuantityFormatter::class);
        $term = \App\Support\NameNormalizer::normalize($this->search);

        $items = StockItem::query()->active()
            ->with('ingredient.storageLocation', 'unit', 'location')
            ->when($this->location !== 'tout', fn ($q) => $q->where('storage_location_id', (int) $this->location))
            ->get()
            ->filter(fn (StockItem $item) => $term === '' || str_contains(\App\Support\NameNormalizer::normalize($item->name()), $term))
            ->filter(fn (StockItem $item) => match ($this->filter) {
                'alertes' => $expiry->level($item)->needsAttention(),
                'bientot' => in_array($expiry->level($item), [ExpiryLevel::Urgent, ExpiryLevel::Soon], true),
                'depasse' => in_array($expiry->level($item), [ExpiryLevel::Expired, ExpiryLevel::DdmPassed], true),
                'ouverts' => $item->opened_on !== null && ! $item->isFrozen(),
                'minimum' => $this->belowMinimum->contains(fn (array $row) => $row['ingredient']->id === $item->ingredient_id),
                default => true,
            });

        return $items
            ->groupBy(fn (StockItem $item) => $item->ingredient_id ? 'i'.$item->ingredient_id : 'p'.mb_strtolower((string) $item->label))
            ->map(function (Collection $group, string $key) use ($expiry, $formatter) {
                $group = $group->sortBy(fn (StockItem $i) => $expiry->effective($i)['date']?->timestamp ?? PHP_INT_MAX)->values();
                $first = $group->first();
                $level = $group->map(fn (StockItem $i) => $expiry->level($i))->sortBy(fn (ExpiryLevel $l) => $l->rank())->first();
                $units = $group->pluck('unit_id')->unique();
                $total = $units->count() === 1 && $group->every(fn (StockItem $i) => $i->quantity !== null)
                    ? $formatter->format((float) $group->sum('quantity'), $first->unit)
                    : '';

                return [
                    'key' => $key,
                    'name' => $first->name(),
                    'presence' => $first->ingredient?->stock_mode === StockMode::Presence,
                    'ingredient_id' => $first->ingredient_id,
                    'items' => $group,
                    'level' => $level,
                    'total' => $total,
                ];
            })
            ->sortBy(fn (array $g) => [$g['level']->rank(), \App\Support\NameNormalizer::normalize($g['name'])])
            ->values();
    }

    /** Ingrédients sous leur stock minimum (9.10). */
    #[Computed]
    public function belowMinimum(): Collection
    {
        return app(StockMinimum::class)->below();
    }

    /** « Sous le minimum » → ajouter ce qui manque à la liste de courses en cours. */
    public function addMinimumToShoppingList(ShoppingListManager $manager): void
    {
        $list = ShoppingList::query()->active()->orderByDesc('period_start')->first();

        if (! $list) {
            $this->dispatch('notify', type: 'warning', message: 'Aucune liste de courses en cours : générez-en une depuis la page Courses.');

            return;
        }

        $added = $manager->addRestockItems($list);

        $this->dispatch('notify', message: $added > 0
            ? $added.' article'.($added > 1 ? 's ajoutés' : ' ajouté')." à « {$list->name} »."
            : "Tout est déjà dans « {$list->name} ».");
    }

    #[On('stock-changed')]
    public function refreshStock(): void
    {
        unset($this->groups, $this->outOfStock, $this->locations, $this->belowMinimum, $this->reservations, $this->toCheck);
    }

    /** Propositions apprises (30.4, 30.5, R36) : trois au plus à la fois. */
    #[Computed]
    public function learnings(): Collection
    {
        return app(\App\Services\Stock\Learnings::class)->pending()->take(3);
    }

    public function applyLearning(string $kind, int $ingredientId): void
    {
        abort_unless(auth()->user()->canEdit(), 403);

        try {
            $message = app(\App\Services\Stock\Learnings::class)->apply($kind, $ingredientId);
            $this->dispatch('notify', message: $message);
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());
        }

        unset($this->learnings, $this->belowMinimum);
    }

    public function dismissLearning(string $kind, int $ingredientId): void
    {
        abort_unless(auth()->user()->canEdit(), 403);

        try {
            app(\App\Services\Stock\Learnings::class)->dismiss($kind, $ingredientId);
            $this->dispatch('notify', message: 'Entendu : plus de proposition pour ce produit pendant 3 mois.');
        } catch (InvalidArgumentException $e) {
            $this->dispatch('notify', type: 'warning', message: $e->getMessage());
        }

        unset($this->learnings);
    }

    /** Articles immobiles depuis 2 mois (30.8). */
    #[Computed]
    public function idleCount(): int
    {
        return app(\App\Services\Stock\StockReview::class)->items()->count();
    }

    #[Computed]
    public function toCheck(): Collection
    {
        return app(\App\Services\Stock\StockDiscrepancies::class)->toCheck();
    }

    public function closeCheck(): void
    {
        $this->showCheck = false;
    }

    /** « Toujours là » : l'article est vérifié, il ne sera pas reproposé avant deux semaines. */
    public function markChecked(int $itemId): void
    {
        if ($item = StockItem::active()->find($itemId)) {
            app(\App\Services\Stock\StockDiscrepancies::class)->markChecked($item);
        }

        unset($this->toCheck);

        if ($this->toCheck->isEmpty()) {
            $this->showCheck = false;
            $this->dispatch('notify', message: 'Vérification terminée, merci !');
        }
    }

    /** Ce que les repas des deux prochaines semaines réservent (lot 21, R24), par article. */
    /**
     * Lot 40 (40.4, R44) : articles dont le produit contient l'allergène de quelqu'un du foyer,
     * d'après Open Food Facts.
     *
     * @return array<int, string> article => message
     */
    #[Computed]
    public function allergenAlerts(): array
    {
        $people = app(\App\Services\Planning\HouseholdService::class)->people()->filter(fn ($p) => $p->restrictions->isNotEmpty());

        if ($people->isEmpty()) {
            return [];
        }

        $service = app(\App\Services\Stock\ProductAllergens::class);
        $alerts = [];

        foreach (\App\Models\StockItem::query()->active()->whereNotNull('product_id')->with('product')->get() as $item) {
            if ($item->product && ($problems = $service->problems($item->product, $people)) !== []) {
                $alerts[$item->id] = collect($problems)->pluck('message')->join(' · ').' (d\'après Open Food Facts)';
            }
        }

        return $alerts;
    }

    #[Computed]
    public function reservations(): array
    {
        return app(\App\Services\Stock\StockReservations::class)->compute()['items'];
    }

    /** Ingrédients suivis en présence qui ont été en stock mais n'y sont plus (placard à regarnir). */
    #[Computed]
    public function outOfStock(): Collection
    {
        if ($this->filter !== '' || trim($this->search) !== '') {
            return collect();
        }

        // Réglages du foyer (R30) : mode et emplacement se lisent sur l'ingrédient chargé.
        return Ingredient::query()
            ->whereSetting('stock_mode', '=', StockMode::Presence->value)
            ->whereHas('stockItems')
            ->whereDoesntHave('stockItems', fn ($q) => $q->active())
            ->orderBy('name')->get(['id', 'name', 'stock_mode', 'storage_location_id'])
            ->filter(fn (Ingredient $i) => $this->location === 'tout' || (int) $i->storage_location_id === (int) $this->location)
            ->take(40)->values();
    }
}
