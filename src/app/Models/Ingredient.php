<?php

namespace App\Models;

use App\Enums\ExpiryType;
use App\Enums\StockMode;
use App\Support\NameNormalizer;
use App\Support\StockDefaults;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $name
 * @property string|null $name_plural
 * @property string $search_name
 * @property int $aisle_id
 * @property int|null $default_unit_id
 * @property string|null $piece_weight_g
 * @property bool $is_staple
 * @property-read Aisle $aisle
 * @property-read Unit|null $defaultUnit
 */
#[Fillable(['name', 'name_plural', 'aisle_id', 'default_unit_id', 'piece_weight_g', 'is_staple', 'stock_mode', 'storage_location_id', 'shelf_life_days', 'shelf_life_type', 'days_after_opening', 'freezer_months', 'min_stock_quantity', 'min_stock_unit_id', 'season_months', 'ciqual_code', 'density'])]
class Ingredient extends Model
{
    /** @use HasFactory<IngredientFactory> */
    use HasFactory;

    protected $attributes = [
        'is_staple' => false,
        'shelf_life_type' => 'none',
    ];

    protected function casts(): array
    {
        return [
            'piece_weight_g' => 'decimal:3',
            'is_staple' => 'boolean',
            'stock_mode' => StockMode::class,
            'shelf_life_type' => ExpiryType::class,
            'shelf_life_days' => 'integer',
            'days_after_opening' => 'integer',
            'freezer_months' => 'integer',
            'min_stock_quantity' => 'decimal:3',
            'reference_price' => 'decimal:6',
            'reference_price_locked' => 'boolean',
            'reference_price_on' => 'date',
            'season_months' => 'array',
            'density' => 'decimal:3',
        ];
    }

    /**
     * Réglages propres à chaque foyer (R30) : les colonnes du catalogue sont les valeurs par défaut,
     * un foyer qui les modifie obtient sa propre ligne dans household_ingredient_settings.
     */
    public const HOUSEHOLD_FIELDS = [
        'is_staple', 'aisle_id', 'stock_mode', 'storage_location_id', 'shelf_life_days', 'shelf_life_type',
        'days_after_opening', 'freezer_months', 'min_stock_quantity', 'min_stock_unit_id',
        'reference_price', 'reference_price_unit_id', 'reference_price_locked', 'reference_price_on',
    ];

    protected static function booted(): void
    {
        static::retrieved(fn (Ingredient $ingredient) => $ingredient->applyHouseholdSettings());

        static::saving(function (Ingredient $ingredient) {
            $ingredient->search_name = NameNormalizer::normalize($ingredient->name);

            if ($ingredient->exists) {
                $ingredient->divertHouseholdFields();
            }
        });

        // Réglages de stock proposés d'après le rayon (modifiables ensuite).
        static::creating(fn (Ingredient $ingredient) => StockDefaults::fill($ingredient));
    }

    /* ================================================================ R30 — réglages par foyer */

    /** Remplace, en mémoire, les valeurs du catalogue par celles du foyer actif. */
    public function applyHouseholdSettings(): void
    {
        $attributes = $this->getAttributes();
        $row = HouseholdIngredientSetting::map()[$this->id ?? 0] ?? null;

        if ($row) {
            foreach (self::HOUSEHOLD_FIELDS as $field) {
                if (array_key_exists($field, $attributes)) {
                    $attributes[$field] = $row[$field];
                }
            }
        }

        // Emplacement par défaut du catalogue : celui du foyer qui porte le même nom (ou le même type).
        if (isset($attributes['storage_location_id'])) {
            $attributes['storage_location_id'] = StorageLocation::equivalentFor((int) $attributes['storage_location_id']);
        }

        $this->setRawAttributes($attributes, true);
    }

    /** Les réglages modifiés vont dans la ligne du foyer, jamais dans le catalogue commun. */
    public function divertHouseholdFields(): void
    {
        $dirty = array_values(array_intersect(array_keys($this->getDirty()), self::HOUSEHOLD_FIELDS));
        $household = \App\Support\CurrentHousehold::id();

        if ($dirty === [] || ! $household) {
            return;
        }

        $current = $this->getAttributes();
        $missing = array_diff(self::HOUSEHOLD_FIELDS, array_keys($current));
        $effective = $missing === [] ? $current : array_merge((static::query()->find($this->id)?->getAttributes() ?? []), array_intersect_key($current, array_flip(self::HOUSEHOLD_FIELDS)));

        $values = [];

        foreach (self::HOUSEHOLD_FIELDS as $field) {
            $values[$field] = $effective[$field] ?? null;
        }

        $values['is_staple'] = (bool) $values['is_staple'];
        $values['reference_price_locked'] = (bool) $values['reference_price_locked'];
        $values['stock_mode'] ??= 'quantity';
        $values['shelf_life_type'] ??= 'none';

        \Illuminate\Support\Facades\DB::table('household_ingredient_settings')->updateOrInsert(
            ['household_id' => $household, 'ingredient_id' => $this->id],
            $values + ['updated_at' => now(), 'created_at' => now()],
        );
        HouseholdIngredientSetting::flush();

        // Rien de tout cela n'est écrit dans la ligne du catalogue.
        $this->syncOriginalAttributes($dirty);
    }

    /**
     * Expression SQL de la valeur d'un réglage pour le foyer actif (filtres, tris).
     *
     * @return array{0: string, 1: list<int>}
     */
    public static function settingSql(string $field): array
    {
        abort_unless(in_array($field, self::HOUSEHOLD_FIELDS, true), 500);
        $household = (int) \App\Support\CurrentHousehold::id();
        $table = 'household_ingredient_settings';

        return [
            "(CASE WHEN EXISTS (SELECT 1 FROM {$table} his WHERE his.ingredient_id = ingredients.id AND his.household_id = ?) "
            ."THEN (SELECT his.{$field} FROM {$table} his WHERE his.ingredient_id = ingredients.id AND his.household_id = ?) "
            ."ELSE ingredients.{$field} END)",
            [$household, $household],
        ];
    }

    /** where() sur un réglage du foyer : Ingredient::query()->whereSetting('stock_mode', '!=', 'none'). */
    public function scopeWhereSetting(Builder $query, string $field, string $operator, mixed $value = null): Builder
    {
        [$sql, $bindings] = self::settingSql($field);

        if ($value === null && in_array(strtolower($operator), ['null', 'not null'], true)) {
            return $query->whereRaw("{$sql} is {$operator}", $bindings);
        }

        $value = $value instanceof \BackedEnum ? $value->value : $value;

        return $query->whereRaw("{$sql} {$operator} ?", [...$bindings, is_bool($value) ? (int) $value : $value]);
    }

    /**
     * Catalogue commun (R30) : dès qu'il y a plusieurs foyers, le nom, l'unité, la saison ou la
     * suppression d'un ingrédient ne se changent que par l'administrateur de l'installation.
     */
    public static function catalogLocked(): bool
    {
        if (! app()->bound('bouffe.households.count')) {
            app()->instance('bouffe.households.count', Household::query()->count());
        }

        return app('bouffe.households.count') > 1 && ! auth()->user()?->isAdmin();
    }

    /** Utilisé ailleurs que dans ce foyer (stock, recettes, listes d'un autre foyer) ? */
    public function usedByOtherHouseholds(): bool
    {
        $household = (int) \App\Support\CurrentHousehold::id();
        $db = \Illuminate\Support\Facades\DB::class;

        return $db::table('stock_items')->where('ingredient_id', $this->id)->where('household_id', '!=', $household)->exists()
            || $db::table('recipe_ingredients')->join('recipes', 'recipes.id', '=', 'recipe_ingredients.recipe_id')->where('recipe_ingredients.ingredient_id', $this->id)->where('recipes.household_id', '!=', $household)->exists()
            || $db::table('shopping_list_items')->where('ingredient_id', $this->id)->where('household_id', '!=', $household)->exists();
    }

    /** @return BelongsTo<Aisle, $this> */
    public function aisle(): BelongsTo
    {
        return $this->belongsTo(Aisle::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function defaultUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'default_unit_id');
    }

    /** Unité du prix de référence : g, ml ou une unité de comptage (lot 17, R17). */
    /** @return BelongsTo<Unit, $this> */
    public function referencePriceUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'reference_price_unit_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<IngredientPrice, $this> */
    public function prices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(IngredientPrice::class);
    }

    /** @return BelongsTo<StorageLocation, $this> */
    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<StockItem, $this> */
    public function stockItems(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StockItem::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<RecipeIngredient, $this> */
    public function recipeLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<IngredientAlias, $this> */
    public function aliases(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(IngredientAlias::class)->orderBy('name');
    }

    /** Ingrédient par son nom exact (insensible aux accents et au pluriel), ou par l'un de ses alias (R22). */
    public static function findByName(?string $name): ?self
    {
        $normalized = NameNormalizer::normalize($name);

        if ($normalized === '') {
            return null;
        }

        return static::firstWhere('search_name', $normalized)
            ?? IngredientAlias::query()->where('search_name', $normalized)->first()?->ingredient;
    }

    /** Nombre de recettes utilisant cet ingrédient. */
    public function recipeCount(): int
    {
        return $this->recipeLines()->distinct()->count('recipe_id');
    }

    /** Recherche insensible aux accents, à la casse et au pluriel. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $normalized = NameNormalizer::normalize($term);

        if ($normalized === '') {
            return $query;
        }

        // search_name ne contient que [a-z0-9 ] : pas de caractère spécial LIKE à échapper
        return $query->where(fn (Builder $q) => $q
            ->where('search_name', 'like', '%'.$normalized.'%')
            ->orWhereHas('aliases', fn (Builder $a) => $a->where('search_name', 'like', '%'.$normalized.'%')));
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('search_name');
    }

    /** Nom accordé selon la quantité (« tomate » / « tomates »). */
    public function nameFor(?float $quantity): string
    {
        return $quantity !== null && $quantity >= 2 && $this->name_plural ? $this->name_plural : $this->name;
    }

    /**
     * Ingrédients dont le nom ressemble à $name (doublons probables).
     *
     * @return Collection<int, Ingredient>
     */
    public static function similarTo(string $name, ?int $ignoreId = null): Collection
    {
        $normalized = NameNormalizer::normalize($name);

        if (mb_strlen($normalized) < 3) {
            return collect();
        }

        return static::query()
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->get(['id', 'name', 'search_name'])
            ->filter(fn (Ingredient $ingredient) => NameNormalizer::isSimilar($normalized, $ingredient->search_name))
            ->values();
    }
}
