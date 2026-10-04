<?php

namespace App\Livewire\Stock;

use App\Enums\ExpiryType;
use App\Models\Ingredient;
use App\Models\StockItem;
use App\Services\QuantityFormatter;
use App\Services\Stock\ExpiryCalculator;
use App\Services\Stock\StockManager;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Écran Stock (9.4 à 9.7) : emplacements, articles groupés par ingrédient, ajout rapide, actions.
 */
#[Title('Stock')]
class Index extends Component
{
    use \App\Support\Concerns\RequiresFullAccess;
    use Concerns\AddsStockItems;
    use Concerns\ManagesStockItem;
    use Concerns\ProvidesStockData;

    public const WASTE_REASONS = ['périmé', 'abîmé', 'oublié', 'autre'];

    /** Emplacement affiché (identifiant) ou « tout ». */
    #[Url(as: 'emplacement', except: 'tout')]
    public string $location = 'tout';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** alertes | bientot | depasse | ouverts | minimum */
    #[Url(as: 'filtre', except: '')]
    public string $filter = '';

    public string $quickText = '';

    /* ---------------------------------------------------------- Fenêtre d'ajout */

    public bool $showAdd = false;

    /** ingredient | prepared */
    public string $addMode = 'ingredient';

    /** Article ouvert directement par son adresse : c'est la cible des QR codes d'étiquettes (16.5). */
    #[Url(as: 'article', except: null)]
    public ?int $openArticle = null;

    public string $addName = '';

    public string $addQuantity = '';

    public ?int $addUnitId = null;

    public ?int $addLocationId = null;

    public string $addExpiresOn = '';

    public string $addExpiryType = 'none';

    public string $addNote = '';

    /* ---------------------------------------------------------- Fenêtre d'un article */

    public ?int $selectedId = null;

    public string $editQuantity = '';

    public ?int $editUnitId = null;

    public string $editExpiresOn = '';

    public string $editExpiryType = 'none';

    public string $editNote = '';

    public bool $showWaste = false;

    /* ---------------------------------------------------------- Bandeaux */

    /** @var array{movement_id: int, message: string}|null */
    public ?array $undoOffer = null;

    /** @var array{ingredient_id: int, name: string}|null */
    public ?array $restockOffer = null;

    public function mount(): void
    {
        // QR code d'une étiquette de congélateur : on ouvre directement l'article (16.5).
        if ($this->openArticle && StockItem::active()->whereKey($this->openArticle)->exists()) {
            $this->select($this->openArticle);
        }
    }

    private function stock(): StockManager
    {
        return app(StockManager::class);
    }

    /* ================================================================ Ajout */

    /* ================================================================ Article */

    /* ================================================================ Données */

    /** Articles dont la fiche a probablement dérivé (lot 21, 22.5). */
    /* ================================================================ Lot 30 : apprentissages, revue */

    public bool $showCheck = false;

    public function render()
    {
        return view('livewire.stock.index', [
            'expiry' => app(ExpiryCalculator::class),
            'formatter' => app(QuantityFormatter::class),
            'expiryTypes' => ExpiryType::cases(),
            'totalCount' => StockItem::active()->count(),
            'currentLocation' => $this->location !== 'tout' ? $this->locations->firstWhere('id', (int) $this->location) : null,
        ]);
    }
}
