<?php

namespace App\Models;

use App\Enums\UnitType;
use App\Models\Concerns\HasSortOrder;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $label
 * @property string|null $label_plural
 * @property UnitType $type
 * @property string|null $factor_to_base
 * @property bool $is_metric
 * @property int $sort_order
 */
#[Fillable(['code', 'label', 'label_plural', 'type', 'factor_to_base', 'is_metric', 'sort_order'])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory, HasSortOrder;

    protected function casts(): array
    {
        return [
            'type' => UnitType::class,
            'factor_to_base' => 'decimal:4',
            'is_metric' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<Ingredient, $this> */
    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class, 'default_unit_id');
    }

    /** Libellé accordé : « pièce » / « pièces ». En français, pluriel à partir de 2. */
    public function labelFor(?float $quantity): string
    {
        return $quantity !== null && $quantity >= 2 && $this->label_plural
            ? $this->label_plural
            : $this->label;
    }

    /** @return HasMany<RecipeIngredient, $this> */
    public function recipeLines(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    /** Nombre d'utilisations (ingrédients + lignes de recettes) empêchant la suppression. */
    public function usageCount(): int
    {
        return $this->ingredients()->count() + $this->recipeLines()->count()
            + ShoppingListItem::where('unit_id', $this->id)->count();
    }
}
