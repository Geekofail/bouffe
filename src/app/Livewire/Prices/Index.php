<?php

namespace App\Livewire\Prices;

use App\Models\Ingredient;
use App\Models\IngredientPrice;
use App\Models\Store;
use App\Models\Unit;
use App\Services\Pricing\PersonalInflation;
use App\Services\Pricing\PriceBook;
use App\Services\Pricing\PriceComparison;
use App\Support\NameNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Prix et magasins (lot 27) : où acheter moins cher (C1) et comment évoluent nos prix (C2).
 */
#[Title('Prix et magasins')]
class Index extends Component
{
    use \App\Livewire\Concerns\OffersUndo;

    public const TABS = ['magasins' => 'Comparer les magasins', 'evolution' => 'Évolution de nos prix'];

    #[Url(as: 'vue', except: 'magasins')]
    public string $tab = 'magasins';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Seulement les produits relevés dans au moins deux magasins. */
    #[Url(as: 'tous', except: false)]
    public bool $all = false;

    /** Produit dont l'historique est ouvert (« ingrédient|unité »). */
    public string $open = '';

    /* ------------------------------------------------ Noter un prix */

    public bool $showForm = false;

    public string $noteName = '';

    public ?int $noteStoreId = null;

    public string $notePrice = '';

    public string $noteQuantity = '';

    public ?int $noteUnitId = null;

    public string $noteDate = '';

    /** Lot 30 (R35) : prix en promotion. */
    public bool $notePromo = false;

    public function mount(): void
    {
        $this->tab = array_key_exists($this->tab, self::TABS) ? $this->tab : 'magasins';
    }

    public function toggle(string $key): void
    {
        $this->open = $this->open === $key ? '' : $key;
    }

    public function openForm(?int $ingredientId = null, ?int $storeId = null): void
    {
        abort_unless(auth()->user()->canEdit(), 403);

        $this->resetValidation();
        $ingredient = $ingredientId ? Ingredient::find($ingredientId) : null;
        $this->noteName = $ingredient?->name ?? '';
        $this->noteStoreId = $storeId ?? Store::preferred()?->id;
        $this->notePrice = '';
        $this->noteQuantity = '';
        $this->noteUnitId = $ingredient?->default_unit_id;
        $this->noteDate = Carbon::today()->toDateString();
        $this->notePromo = false;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function savePrice(PriceBook $prices): void
    {
        abort_unless(auth()->user()->canEdit(), 403);

        $this->notePrice = str_replace(',', '.', trim($this->notePrice));
        $this->noteQuantity = str_replace(',', '.', trim($this->noteQuantity));

        $this->validate([
            'noteName' => 'required|string|max:150',
            'noteStoreId' => 'required|integer|exists:stores,id',
            'notePrice' => 'required|numeric|min:0.01|max:9999',
            'noteQuantity' => 'nullable|numeric|min:0.001|max:99999',
            'noteUnitId' => 'nullable|integer|exists:units,id',
            'noteDate' => 'required|date|before_or_equal:today',
        ], [], [
            'noteName' => 'produit', 'noteStoreId' => 'magasin', 'notePrice' => 'prix',
            'noteQuantity' => 'quantité', 'noteUnitId' => 'unité', 'noteDate' => 'date',
        ]);

        $ingredient = Ingredient::findByName($this->noteName);

        if (! $ingredient) {
            $this->addError('noteName', 'Produit inconnu : choisissez un ingrédient de la liste.');

            return;
        }

        $store = Store::find($this->noteStoreId);
        $unit = $this->noteUnitId ? Unit::find($this->noteUnitId) : null;

        $prices->record(
            $ingredient,
            (float) $this->notePrice,
            $this->noteQuantity === '' ? null : (float) $this->noteQuantity,
            $unit,
            $store,
            Carbon::parse($this->noteDate),
            IngredientPrice::MANUAL,
            $this->notePromo,
        );

        $this->showForm = false;
        $this->dispatch('notify', message: "Prix noté pour «\u{00A0}{$ingredient->name}\u{00A0}» chez {$store->name}.");
    }

    /** Supprime un relevé erroné (prix mal lu sur un ticket, faute de frappe). */
    public function deletePrice(int $priceId, PriceBook $prices): void
    {
        abort_unless(auth()->user()->canEdit(), 403);

        $price = IngredientPrice::query()->with('ingredient')->findOrFail($priceId);
        $name = $price->ingredient?->name ?? 'produit';

        // Lot 30 (R32) : le relevé revient, et avec lui le prix de référence qu'il avait fixé.
        $this->undoable('prices.delete', "Relevé de « {$name} » supprimé", function (\App\Services\Undo\UndoRecorder $r) use ($price, $prices) {
            $r->track('ingredient_prices', [$price->id]);
            $r->track('household_ingredient_settings', fn () => \Illuminate\Support\Facades\DB::table('household_ingredient_settings')
                ->where('household_id', \App\Support\CurrentHousehold::id())->where('ingredient_id', $price->ingredient_id)->pluck('id'));
            $prices->forget($price);
        });
    }

    /** Après « Annuler » (lot 30) : prix relus. */
    #[On('bouffe-undone')]
    public function afterUndo(): void
    {
        \App\Models\HouseholdIngredientSetting::flush();
        app(PriceComparison::class)->forget();
    }

    /** @return Collection<int, string> */
    #[Computed]
    public function ingredientNames(): Collection
    {
        return Ingredient::query()->orderBy('name')->pluck('name');
    }

    #[Computed]
    public function units(): Collection
    {
        return Unit::query()->orderBy('id')->get(['id', 'code', 'label', 'type']);
    }

    /** Relevés du produit ouvert, du plus récent au plus ancien. */
    #[Computed]
    public function history(): Collection
    {
        if ($this->open === '') {
            return collect();
        }

        [$ingredientId] = explode('|', $this->open) + [0];

        return IngredientPrice::query()
            ->where('ingredient_id', (int) $ingredientId)
            ->with('store', 'unit')
            ->orderByDesc('observed_on')->orderByDesc('id')
            ->limit(30)
            ->get();
    }

    public function render(PriceComparison $comparison, PersonalInflation $inflation, PriceBook $prices)
    {
        $data = ['prices' => $prices, 'tabs' => self::TABS];

        if ($this->tab === 'evolution') {
            $data['index'] = $inflation->index();
            $data['series'] = $inflation->series();
            $data['rises'] = $inflation->rises();
        } else {
            $products = $comparison->products();
            $term = NameNormalizer::normalize($this->search);

            $data['stores'] = $comparison->stores();
            $data['total'] = $products->count();
            $data['compared'] = $products->filter(fn ($p) => $p['prices']->count() > 1)->count();
            $data['products'] = $products
                ->filter(fn ($p) => $this->all || $p['prices']->count() > 1)
                ->filter(fn ($p) => $term === '' || str_contains(NameNormalizer::normalize($p['ingredient']->name), $term))
                ->values();
        }

        return view('livewire.prices.index', $data);
    }
}
