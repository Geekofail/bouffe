<?php

namespace App\Livewire\Stock;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StorageLocation;
use App\Models\Unit;
use App\Services\Stock\ProductLookup;
use App\Services\Stock\ShelfLifeLearner;
use App\Services\Stock\StockManager;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Scan d'un code-barres (16.1).
 *
 * Le scan proprement dit est fait par le navigateur (caméra + décodage), parce que l'image ne
 * doit jamais quitter le téléphone. Une fois le code lu, tout le reste se passe ici.
 *
 * Trois situations, trois écrans :
 *  1. **produit déjà connu** : on propose directement d'ajouter au stock, sans question ;
 *  2. **produit trouvé chez Open Food Facts** : on demande une seule fois à quel ingrédient de
 *     la maison il correspond, avec une proposition ;
 *  3. **produit inconnu partout** : on le nomme à la main, et il devient connu pour toujours.
 *
 * La caméra exige une origine sûre (HTTPS ou localhost) : sur le Wi-Fi en http://, la saisie
 * du code au clavier reste disponible et fait exactement la même chose.
 */
#[Title('Scanner un produit')]
class Scan extends Component
{
    /** Code-barres lu ou saisi. */
    public string $barcode = '';

    public ?int $productId = null;

    /** unknown | known | found | missing */
    public string $state = 'unknown';

    public bool $offline = false;

    /* ---------------------------------------------------------- Formulaire */

    public string $label = '';

    public ?int $ingredientId = null;

    public string $quantity = '';

    public ?int $unitId = null;

    public ?int $locationId = null;

    public string $expiresOn = '';

    public string $note = '';

    public function mount(ProductLookup $lookup): void
    {
        $this->locationId = StorageLocation::query()->ordered()->value('id');
    }

    /* ---------------------------------------------------------- Recherche */

    /** Appelé par le scanner (JavaScript) ou par la saisie manuelle du code. */
    public function lookup(?string $code = null, ?ProductLookup $lookup = null): void
    {
        $lookup ??= app(ProductLookup::class);
        $this->barcode = $lookup->clean($code ?? $this->barcode);
        $this->resetErrorBag();

        if (! $lookup->isValidBarcode($this->barcode)) {
            $this->addError('barcode', 'Code-barres invalide : uniquement des chiffres (6 à 20).');

            return;
        }

        $result = $lookup->find($this->barcode);
        $this->offline = $result['offline'];
        $product = $result['product'];

        if (! $product) {
            $this->state = 'missing';
            $this->productId = null;
            $this->label = '';
            $this->ingredientId = null;
            $this->prefillFromIngredient();

            return;
        }

        $this->productId = $product->id;
        $this->label = $product->fullName();
        $this->ingredientId = $product->ingredient_id;
        $this->quantity = $product->quantity !== null
            ? rtrim(rtrim(str_replace('.', ',', (string) $product->quantity), '0'), ',')
            : '';
        $this->unitId = $product->unit_id;
        // Un produit qui vient d'être découvert chez Open Food Facts demande confirmation de
        // l'ingrédient proposé, même quand la proposition tombe juste : c'est la seule fois.
        $this->state = $result['source'] === 'local' ? 'known' : 'found';

        $this->prefillFromIngredient();
    }

    public function updatedIngredientId(): void
    {
        $this->prefillFromIngredient();
    }

    /** Emplacement et date proposés d'après l'ingrédient, et d'après vos habitudes (16.2). */
    private function prefillFromIngredient(): void
    {
        $ingredient = $this->ingredientId ? Ingredient::find($this->ingredientId) : null;

        if (! $ingredient) {
            $this->expiresOn = '';

            return;
        }

        $defaults = app(StockManager::class)->defaults($ingredient);
        $learned = app(ShelfLifeLearner::class)->suggest($ingredient);

        $this->locationId = $learned['storage_location_id'] ?? $defaults['storage_location_id'] ?? $this->locationId;
        $this->expiresOn = (string) ($learned['expires_on'] ?? $defaults['expires_on'] ?? '');
        $this->unitId ??= $defaults['unit_id'];
    }

    public function reset_(): void
    {
        $this->reset('barcode', 'productId', 'state', 'label', 'ingredientId', 'quantity', 'unitId', 'expiresOn', 'note', 'offline');
        $this->locationId = StorageLocation::query()->ordered()->value('id');
        $this->resetErrorBag();
    }

    /* ---------------------------------------------------------- Enregistrement */

    /** Ajoute l'article au stock, et retient le produit pour les prochains scans. */
    public function store(ProductLookup $lookup, StockManager $stock): void
    {
        $this->validate([
            'label' => 'required|string|max:200',
            'ingredientId' => 'nullable|integer|exists:ingredients,id',
            'quantity' => ['nullable', 'string', 'max:20'],
            'unitId' => 'nullable|integer|exists:units,id',
            'locationId' => 'required|integer|exists:storage_locations,id',
            'expiresOn' => 'nullable|date',
            'note' => 'nullable|string|max:200',
        ], [], [
            'label' => 'nom du produit', 'ingredientId' => 'ingrédient', 'quantity' => 'quantité',
            'unitId' => 'unité', 'locationId' => 'emplacement', 'expiresOn' => 'date', 'note' => 'note',
        ]);

        $parsed = trim($this->quantity) === '' ? null : app(\App\Services\QuantityParser::class)->tryParse($this->quantity);

        if ($parsed === false) {
            $this->addError('quantity', 'Quantité invalide (ex. 1, 1,5, 500).');

            return;
        }

        $product = $this->productId ? Product::find($this->productId) : null;

        // Produit inconnu partout : il est créé ici, et connu pour toujours.
        $product ??= $lookup->createManually($this->barcode, $this->label, $this->ingredientId, $parsed, $this->unitId);

        if ($product->ingredient_id !== $this->ingredientId) {
            $product = $lookup->attach($product, $this->ingredientId);
        }

        try {
            $item = $stock->add([
                'ingredient_id' => $this->ingredientId,
                'label' => $this->ingredientId ? null : $this->label,
                'quantity' => $parsed,
                'unit_id' => $this->unitId,
                'storage_location_id' => $this->locationId,
                'expires_on' => $this->expiresOn ?: null,
                'note' => trim($this->note) ?: null,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->addError('label', $e->getMessage());

            return;
        }

        $item->forceFill(['product_id' => $product->id])->save();

        $this->dispatch('notify', message: "«\u{00A0}{$product->label}\u{00A0}» ajouté au stock."
            .($this->ingredientId ? '' : ' Associez-le à un ingrédient pour qu\'il compte dans les recettes.'));

        $this->reset_();
    }

    /* ---------------------------------------------------------- Données de la vue */

    /** @return Collection<int, Ingredient> */
    #[Computed]
    public function ingredients(): Collection
    {
        return Ingredient::query()->orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int, Unit> */
    #[Computed]
    public function units(): Collection
    {
        return Unit::query()->ordered()->get(['id', 'label']);
    }

    /** @return Collection<int, StorageLocation> */
    #[Computed]
    public function locations(): Collection
    {
        return StorageLocation::query()->ordered()->get(['id', 'name']);
    }

    /** Les derniers produits scannés : utile pour vérifier une association. */
    #[Computed]
    public function recent(): Collection
    {
        return Product::query()->with('ingredient')->orderByDesc('last_seen_at')->limit(6)->get();
    }

    /**
     * Lot 40 (40.4, R44) : allergènes du produit, d'après Open Food Facts, et ce qu'ils posent comme
     * problème à quelqu'un du foyer.
     *
     * @return array{product: Product, summary: string|null, problems: list<array>, canCheck: bool}|null
     */
    #[\Livewire\Attributes\Computed]
    public function allergens(): ?array
    {
        $product = $this->productId ? Product::find($this->productId) : null;

        if (! $product) {
            return null;
        }

        $service = app(\App\Services\Stock\ProductAllergens::class);

        return [
            'product' => $product,
            'summary' => $service->summary($product),
            'problems' => $service->problems($product, app(\App\Services\Planning\HouseholdService::class)->people()),
            'canCheck' => $product->source === Product::OPEN_FOOD_FACTS && $product->allergens === null,
        ];
    }

    /** Relit les allergènes d'un produit scanné avant le lot 40. */
    public function checkAllergens(ProductLookup $lookup): void
    {
        $product = $this->productId ? Product::find($this->productId) : null;

        if ($product && ! $lookup->refreshAllergens($product)) {
            $this->dispatch('notify', type: 'warning', message: 'Open Food Facts ne répond pas : réessayez plus tard.');
        }

        unset($this->allergens);
    }

    public function render()
    {
        return view('livewire.stock.scan');
    }
}
