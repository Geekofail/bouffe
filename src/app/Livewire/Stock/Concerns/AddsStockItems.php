<?php

namespace App\Livewire\Stock\Concerns;

use App\Models\Aisle;
use App\Models\Ingredient;
use App\Services\QuantityFormatter;
use App\Services\QuantityParser;
use App\Services\Stock\QuickAddParser;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Stock — fenêtre d'ajout : saisie rapide, produit maison, dates proposées (découpé d'Index au lot 36).
 */
trait AddsStockItems
{
    /** « 2 kg de pommes de terre » → fenêtre pré-remplie. */
    public function prepareQuickAdd(QuickAddParser $parser): void
    {
        $text = trim($this->quickText);

        if ($text === '') {
            $this->addError('quickText', 'Tapez un produit, par exemple « 6 œufs ».');

            return;
        }

        $parsed = $parser->parse($text);
        $this->openAdd($parsed['ingredient'] ? 'ingredient' : 'prepared', $parsed['name'], $parsed['ingredient']);

        if ($parsed['quantity'] !== null) {
            $this->addQuantity = app(QuantityFormatter::class)->number($parsed['quantity'], 3);
            $this->addUnitId = $parsed['unit']?->id ?? $this->addUnitId;
        }
    }

    public function newPrepared(): void
    {
        $this->openAdd('prepared', '');
    }

    private function openAdd(string $mode, string $name, ?Ingredient $ingredient = null): void
    {
        $this->resetErrorBag();
        $this->reset('addQuantity', 'addNote');
        $this->addMode = $mode;
        $this->addName = $name;
        $this->applyDefaults($ingredient);
        $this->showAdd = true;
    }

    /** Emplacement, date et unité proposés quand le nom ou le mode change. */
    public function updatedAddName(): void
    {
        if ($this->addMode === 'ingredient') {
            $this->applyDefaults($this->matchedIngredient());
        }
    }

    public function updatedAddMode(): void
    {
        $this->applyDefaults($this->addMode === 'ingredient' ? $this->matchedIngredient() : null);
    }

    private function applyDefaults(?Ingredient $ingredient): void
    {
        $defaults = $this->stock()->defaults($ingredient);
        $this->addLocationId = $this->location !== 'tout' && ! $ingredient ? (int) $this->location : $defaults['storage_location_id'];
        $this->addExpiresOn = (string) $defaults['expires_on'];
        $this->addExpiryType = $defaults['expiry_type'];
        $this->addUnitId = $defaults['unit_id'];
    }

    public function shiftDate(string $shortcut): void
    {
        $this->addExpiresOn = match ($shortcut) {
            '3d' => Carbon::today()->addDays(3)->toDateString(),
            '1w' => Carbon::today()->addWeek()->toDateString(),
            '1m' => Carbon::today()->addMonth()->toDateString(),
            default => '',
        };
    }

    public function closeAdd(): void
    {
        $this->showAdd = false;
    }

    public function saveAdd(QuantityParser $parser): void
    {
        $this->addName = trim($this->addName);
        $this->validate([
            'addName' => 'required|string|max:150',
            'addLocationId' => 'required|integer|exists:storage_locations,id',
            'addExpiresOn' => 'nullable|date',
            'addExpiryType' => 'in:dlc,ddm,none',
            'addNote' => 'nullable|string|max:255',
        ], [], ['addName' => 'nom', 'addLocationId' => 'emplacement', 'addExpiresOn' => 'date']);

        $quantity = $parser->tryParse($this->addQuantity);

        if ($quantity === false) {
            $this->addError('addQuantity', 'Quantité invalide (ex. 2, 1,5 ou 1/2).');

            return;
        }

        $created = null;
        $ingredient = null;

        if ($this->addMode === 'ingredient') {
            $ingredient = $this->matchedIngredient();

            if (! $ingredient) {
                $aisleId = Aisle::where('name', 'Divers')->value('id') ?? Aisle::query()->orderBy('sort_order')->value('id');
                $ingredient = Ingredient::create(['name' => mb_strtoupper(mb_substr($this->addName, 0, 1)).mb_substr($this->addName, 1), 'aisle_id' => $aisleId]);
                $created = $ingredient->name;
            }
        }

        try {
            $item = $this->stock()->add([
                'ingredient_id' => $ingredient?->id,
                'label' => $ingredient ? null : $this->addName,
                'quantity' => $quantity,
                'unit_id' => $this->addUnitId,
                'storage_location_id' => $this->addLocationId,
                'expires_on' => $this->addExpiresOn ?: null,
                'expiry_type' => $this->addExpiryType,
                'note' => $this->addNote,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->addError('addName', $e->getMessage());

            return;
        }

        $this->showAdd = false;
        $this->quickText = '';
        $this->offerUndo($item->movements()->latest('id')->first(), "« {$item->name()} » ajouté ({$item->location->name})."
            .($created ? ' Nouvel ingrédient créé dans le rayon Divers.' : ''));
    }

    private function matchedIngredient(): ?Ingredient
    {
        $name = trim($this->addName);

        return $name === '' ? null : Ingredient::findByName($name);
    }
}
