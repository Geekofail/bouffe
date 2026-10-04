<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un produit reconnu par son code-barres (16.1).
 *
 * Le but n'est pas de tenir un catalogue : c'est de ne demander **qu'une fois** ce qu'est un
 * produit. Une fois le code-barres associé à un ingrédient de la maison, le scan suivant ajoute
 * l'article au stock sans rien demander, et sans réseau.
 *
 * @property string $barcode
 * @property int|null $ingredient_id
 * @property string $label
 * @property string|null $brand
 * @property string|null $quantity
 * @property int|null $unit_id
 * @property array|null $payload
 * @property string $source
 * @property int $times_scanned
 * @property Carbon|null $last_seen_at
 * @property list<string>|null $allergens étiquettes Open Food Facts (en:milk…), lot 40
 * @property list<string>|null $traces
 * @property Carbon|null $allergens_checked_at
 */
#[Fillable(['barcode', 'ingredient_id', 'label', 'brand', 'quantity', 'unit_id', 'image_url', 'payload', 'source', 'times_scanned', 'last_seen_at', 'allergens', 'traces', 'allergens_checked_at'])]
class Product extends Model
{
    public const OPEN_FOOD_FACTS = 'off';

    public const MANUAL = 'manual';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'payload' => 'array',
            'last_seen_at' => 'datetime',
            'times_scanned' => 'integer',
            'allergens' => 'array',               // lot 40 (R44) : d'après Open Food Facts
            'traces' => 'array',
            'allergens_checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Ingredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** « Luxlait — Lait demi-écrémé UHT » */
    public function fullName(): string
    {
        return $this->brand ? $this->brand.' — '.$this->label : $this->label;
    }

    /** Le produit est utilisable sans rien demander une fois l'ingrédient associé. */
    public function isKnown(): bool
    {
        return $this->ingredient_id !== null;
    }
}
