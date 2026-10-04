<?php

namespace App\Livewire\Forms;

use App\Models\Ingredient;
use App\Services\QuantityParser;
use App\Support\NameNormalizer;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Form;

class IngredientForm extends Form
{
    public ?Ingredient $ingredient = null;

    public string $name = '';

    public string $name_plural = '';

    public ?int $aisle_id = null;

    public ?int $default_unit_id = null;

    /** Saisie libre (« 150 », « 7,5 ») convertie à l'enregistrement. */
    public string $piece_weight_g = '';

    public bool $is_staple = false;

    /* Stock (lot 7) */
    public string $stock_mode = 'quantity';

    public ?int $storage_location_id = null;

    public string $shelf_life_days = '';

    public string $shelf_life_type = 'none';

    public string $days_after_opening = '';

    public string $freezer_months = '';

    public string $min_stock_quantity = '';

    public ?int $min_stock_unit_id = null;

    /** Prix de référence (lot 17, R17) : « 4,98 » pour l'unité choisie ci-dessous. */
    public string $reference_price = '';

    /** Unité du prix saisi : kg, l, pièce… (converti vers l'unité de base à l'enregistrement). */
    public ?int $reference_price_unit_id = null;

    /** Réglages de stock proposés d'après le rayon (nouvel ingrédient). */
    public function applyStockDefaults(): void
    {
        $aisle = $this->aisle_id ? \App\Models\Aisle::find($this->aisle_id) : null;
        $profile = \App\Support\StockDefaults::profileFor($aisle?->name, trim($this->name), $this->is_staple);

        $this->stock_mode = $profile['stock_mode'];
        $this->storage_location_id = $profile['storage_location_id'];
        $this->shelf_life_days = (string) ($profile['shelf_life_days'] ?? '');
        $this->shelf_life_type = $profile['shelf_life_type'];
        $this->days_after_opening = (string) ($profile['days_after_opening'] ?? '');
        $this->freezer_months = (string) ($profile['freezer_months'] ?? '');
    }

    public function setIngredient(Ingredient $ingredient): void
    {
        $this->ingredient = $ingredient;
        $this->name = $ingredient->name;
        $this->name_plural = (string) $ingredient->name_plural;
        $this->aisle_id = $ingredient->aisle_id;
        $this->default_unit_id = $ingredient->default_unit_id;
        $this->piece_weight_g = $ingredient->piece_weight_g !== null
            ? rtrim(rtrim(str_replace('.', ',', (string) $ingredient->piece_weight_g), '0'), ',')
            : '';
        $this->is_staple = $ingredient->is_staple;
        $this->stock_mode = $ingredient->stock_mode->value;
        $this->storage_location_id = $ingredient->storage_location_id;
        $this->shelf_life_days = (string) ($ingredient->shelf_life_days ?? '');
        $this->shelf_life_type = $ingredient->shelf_life_type->value;
        $this->days_after_opening = (string) ($ingredient->days_after_opening ?? '');
        $this->freezer_months = (string) ($ingredient->freezer_months ?? '');
        $this->min_stock_quantity = $ingredient->min_stock_quantity !== null ? rtrim(rtrim(str_replace('.', ',', (string) $ingredient->min_stock_quantity), '0'), ',') : '';
        $this->min_stock_unit_id = $ingredient->min_stock_unit_id;

        // Le prix est gardé par unité de base (€/g) : on le réaffiche dans une unité lisible (€/kg).
        [$price, $unitId] = $this->readablePrice($ingredient);
        $this->reference_price = $price;
        $this->reference_price_unit_id = $unitId;
    }

    /**
     * Prix de référence remis dans une unité courante : 0,00498 €/g → « 4,98 » et l'unité « kg ».
     *
     * @return array{0: string, 1: int|null}
     */
    private function readablePrice(Ingredient $ingredient): array
    {
        if ($ingredient->reference_price === null) {
            return ['', $ingredient->default_unit_id];
        }

        $unit = $ingredient->referencePriceUnit;
        $price = (float) $ingredient->reference_price;

        $display = match ($unit?->code) {
            'g' => [$price * 1000, \App\Models\Unit::firstWhere('code', 'kg')?->id ?? $unit->id],
            'ml' => [$price * 1000, \App\Models\Unit::firstWhere('code', 'l')?->id ?? $unit->id],
            default => [$price, $unit?->id],
        };

        return [rtrim(rtrim(number_format($display[0], 4, ',', ''), '0'), ','), $display[1]];
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'name_plural' => ['nullable', 'string', 'max:150'],
            'aisle_id' => ['required', 'integer', Rule::exists('aisles', 'id')],
            'default_unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
            'piece_weight_g' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
                $parsed = app(QuantityParser::class)->tryParse($value);
                if ($parsed === false || $parsed === 0.0) {
                    $fail('Le poids doit être un nombre positif (ex. 150 ou 7,5).');
                }
            }],
            'is_staple' => ['boolean'],
            'stock_mode' => ['required', Rule::in(array_column(\App\Enums\StockMode::cases(), 'value'))],
            'storage_location_id' => ['nullable', 'integer', Rule::exists('storage_locations', 'id')],
            'shelf_life_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'shelf_life_type' => ['required', Rule::in(array_column(\App\Enums\ExpiryType::cases(), 'value'))],
            'days_after_opening' => ['nullable', 'integer', 'min:0', 'max:365'],
            'freezer_months' => ['nullable', 'integer', 'min:1', 'max:36'],
            'min_stock_quantity' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
                if (app(QuantityParser::class)->tryParse($value) === false) {
                    $fail('Le stock minimum doit être un nombre (ex. 1 ou 500).');
                }
            }],
            'min_stock_unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
            'reference_price' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== '' && app(QuantityParser::class)->tryParse($value) === false) {
                    $fail('Le prix doit être un nombre (ex. 4,98).');
                }
            }],
            'reference_price_unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'name' => 'nom',
            'name_plural' => 'pluriel',
            'aisle_id' => 'rayon',
            'default_unit_id' => 'unité par défaut',
            'piece_weight_g' => 'poids moyen',
            'shelf_life_days' => 'conservation',
            'days_after_opening' => 'conservation après ouverture',
            'freezer_months' => 'durée au congélateur',
            'storage_location_id' => 'emplacement',
            'reference_price' => 'prix de référence',
            'reference_price_unit_id' => 'unité du prix',
        ];
    }

    public function save(): Ingredient
    {
        $this->validate();

        $duplicate = Ingredient::query()
            ->where('search_name', NameNormalizer::normalize($this->name))
            ->when($this->ingredient, fn ($query) => $query->whereKeyNot($this->ingredient->id))
            ->first();

        $alias = $duplicate ? null : \App\Models\IngredientAlias::query()
            ->where('search_name', NameNormalizer::normalize($this->name))
            ->when($this->ingredient, fn ($query) => $query->where('ingredient_id', '!=', $this->ingredient->id))
            ->with('ingredient')->first();

        if ($alias) {
            throw ValidationException::withMessages([
                $this->getPropertyName().'.name' => "« {$alias->name} » est un autre nom de « {$alias->ingredient->name} ».",
            ]);
        }

        if ($duplicate) {
            throw ValidationException::withMessages([
                $this->getPropertyName().'.name' => "Cet ingrédient existe déjà : « {$duplicate->name} ».",
            ]);
        }

        $ingredient = $this->ingredient ?? new Ingredient;

        // R30 : nom, pluriel, unité et poids d'une pièce sont communs à tous les foyers.
        if ($ingredient->exists && Ingredient::catalogLocked()) {
            $changed = trim($this->name) !== $ingredient->name
                || (trim($this->name_plural) ?: null) !== $ingredient->name_plural
                || (int) $this->default_unit_id !== (int) $ingredient->default_unit_id;

            if ($changed) {
                throw ValidationException::withMessages([
                    $this->getPropertyName().'.name' => 'Le nom et l\'unité sont communs à tous les foyers : seul l\'administrateur de l\'installation peut les changer. Les réglages de stock et de prix, eux, sont à vous.',
                ]);
            }
        }

        $ingredient->fill([
            'name' => trim($this->name),
            'name_plural' => trim($this->name_plural) ?: null,
            'aisle_id' => $this->aisle_id,
            'default_unit_id' => $this->default_unit_id,
            'piece_weight_g' => app(QuantityParser::class)->parse($this->piece_weight_g),
            'is_staple' => $this->is_staple,
            'stock_mode' => $this->stock_mode,
            'storage_location_id' => $this->storage_location_id,
            'shelf_life_days' => $this->shelf_life_days === '' ? null : (int) $this->shelf_life_days,
            'shelf_life_type' => $this->shelf_life_type,
            'days_after_opening' => $this->days_after_opening === '' ? null : (int) $this->days_after_opening,
            'freezer_months' => $this->freezer_months === '' ? null : (int) $this->freezer_months,
            'min_stock_quantity' => app(QuantityParser::class)->parse($this->min_stock_quantity),
            'min_stock_unit_id' => $this->min_stock_unit_id,
        ])->save();

        // Prix saisi à la main : il devient le prix de référence et n'est plus écrasé par un relevé (R17).
        $price = app(QuantityParser::class)->parse($this->reference_price);
        $unit = $this->reference_price_unit_id ? \App\Models\Unit::find($this->reference_price_unit_id) : null;

        if ($price !== null || $ingredient->reference_price_locked) {
            app(\App\Services\Pricing\PriceBook::class)->setReference($ingredient, $price, $unit);
        }

        return $ingredient;
    }
}
