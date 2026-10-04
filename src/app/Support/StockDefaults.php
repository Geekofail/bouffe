<?php

namespace App\Support;

use App\Enums\ExpiryType;
use App\Enums\LocationType;
use App\Enums\StockMode;
use App\Models\Ingredient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Valeurs de départ du stock : emplacements et réglages de conservation des ingrédients (9.1, 9.2).
 *
 * Réglages déduits du rayon, puis quelques exceptions par nom d'ingrédient. Tout reste modifiable dans
 * Paramètres. Les durées sont des ordres de grandeur prudents, pas des règles sanitaires.
 */
final class StockDefaults
{
    /** clé => [nom, type, ordre] */
    public const LOCATIONS = [
        'fridge' => ['Réfrigérateur', LocationType::Fresh, 1],
        'freezer' => ['Congélateur', LocationType::Freezer, 2],
        'pantry' => ['Placard', LocationType::Ambient, 3],
        'cellar' => ['Cave / cellier', LocationType::Ambient, 4],
        'fruit_bowl' => ['Corbeille à fruits', LocationType::Ambient, 5],
    ];

    /**
     * Nom du rayon => [mode, emplacement, conservation (jours), type de date, après ouverture (jours), congélation (mois)]
     */
    public const AISLE_PROFILES = [
        'Fruits & légumes' => [StockMode::Quantity, 'fridge', 7, ExpiryType::None, null, null],
        'Boulangerie' => [StockMode::Quantity, 'pantry', 3, ExpiryType::None, null, 3],
        'Boucherie & volaille' => [StockMode::Quantity, 'fridge', 3, ExpiryType::Dlc, null, 6],
        'Poissonnerie' => [StockMode::Quantity, 'fridge', 2, ExpiryType::Dlc, null, 3],
        'Charcuterie & traiteur' => [StockMode::Quantity, 'fridge', 10, ExpiryType::Dlc, 3, 2],
        'Crèmerie & œufs' => [StockMode::Quantity, 'fridge', 14, ExpiryType::Dlc, 3, null],
        'Fromages' => [StockMode::Quantity, 'fridge', 21, ExpiryType::Dlc, 7, 3],
        'Épicerie salée' => [StockMode::Presence, 'pantry', 365, ExpiryType::Ddm, null, null],
        'Épicerie sucrée' => [StockMode::Presence, 'pantry', 365, ExpiryType::Ddm, null, null],
        'Conserves & bocaux' => [StockMode::Presence, 'pantry', 730, ExpiryType::Ddm, 3, null],
        'Condiments & épices' => [StockMode::Presence, 'pantry', 365, ExpiryType::Ddm, null, null],
        'Surgelés' => [StockMode::Quantity, 'freezer', 180, ExpiryType::Ddm, null, 6],
        'Boissons' => [StockMode::Presence, 'cellar', 365, ExpiryType::Ddm, 3, null],
        'Hygiène & maison' => [StockMode::None, null, null, ExpiryType::None, null, null],
        'Divers' => [StockMode::Quantity, 'pantry', null, ExpiryType::None, null, null],
    ];

    /** Exceptions par nom d'ingrédient (clés facultatives : mode, location, days, type, opened, freezer). */
    public const INGREDIENT_OVERRIDES = [
        'Ail' => ['location' => 'pantry', 'days' => 30],
        'Oignon' => ['location' => 'pantry', 'days' => 30],
        'Oignon rouge' => ['location' => 'pantry', 'days' => 30],
        'Échalote' => ['location' => 'pantry', 'days' => 30],
        'Pomme de terre' => ['location' => 'pantry', 'days' => 30],
        'Patate douce' => ['location' => 'pantry', 'days' => 21],
        'Butternut' => ['location' => 'pantry', 'days' => 60],
        'Potiron' => ['location' => 'pantry', 'days' => 30],
        'Tomate' => ['location' => 'fruit_bowl', 'days' => 5],
        'Avocat' => ['location' => 'fruit_bowl', 'days' => 4],
        'Banane' => ['location' => 'fruit_bowl', 'days' => 5],
        'Citron' => ['location' => 'fruit_bowl', 'days' => 14],
        'Citron vert' => ['location' => 'fruit_bowl', 'days' => 10],
        'Orange' => ['location' => 'fruit_bowl', 'days' => 14],
        'Pomme' => ['location' => 'fruit_bowl', 'days' => 21],
        'Poire' => ['location' => 'fruit_bowl', 'days' => 7],
        'Fraise' => ['days' => 2], 'Framboise' => ['days' => 2],
        'Persil' => ['days' => 5], 'Coriandre' => ['days' => 5], 'Ciboulette' => ['days' => 5], 'Basilic' => ['days' => 4], 'Menthe' => ['days' => 5],
        'Gingembre frais' => ['days' => 21, 'freezer' => 6],
        'Baguette' => ['days' => 1, 'freezer' => 3],
        'Pain de mie' => ['days' => 10, 'type' => ExpiryType::Ddm, 'opened' => 5],
        'Tortilla' => ['days' => 30, 'type' => ExpiryType::Ddm, 'opened' => 5],
        'Saumon fumé' => ['days' => 14],
        'Moules' => ['days' => 1, 'freezer' => null],
        'Pâte feuilletée' => ['days' => 21, 'opened' => 1, 'freezer' => 3],
        'Pâte brisée' => ['days' => 21, 'opened' => 1, 'freezer' => 3],
        'Pâte à pizza' => ['days' => 21, 'opened' => 1, 'freezer' => 3],
        'Gnocchis' => ['days' => 30, 'opened' => 3],
        'Tofu' => ['days' => 30, 'opened' => 3],
        'Œuf' => ['days' => 28, 'opened' => null],
        'Lait demi-écrémé' => ['days' => 90, 'type' => ExpiryType::Ddm, 'opened' => 3],
        'Beurre' => ['days' => 60, 'opened' => 21, 'freezer' => 6],
        'Yaourt nature' => ['days' => 21, 'opened' => null],
        'Lait de coco' => ['mode' => StockMode::Presence, 'location' => 'pantry', 'days' => 365, 'type' => ExpiryType::Ddm, 'opened' => 3],
        'Emmental râpé' => ['days' => 30, 'opened' => 5, 'freezer' => 3],
        'Parmesan' => ['days' => 60, 'opened' => 21],
        'Confiture' => ['opened' => 30],
        'Mayonnaise' => ['opened' => 60], 'Ketchup' => ['opened' => 90], 'Moutarde' => ['opened' => 90],
        'Concentré de tomate' => ['opened' => 5],
        'Vin blanc' => ['opened' => 5], 'Vin rouge' => ['opened' => 5], 'Bière' => ['opened' => 1],
        'Vanille (gousse)' => ['mode' => StockMode::Presence],
    ];

    /** Durée par défaut d'un plat préparé (restes, batch cooking) au réfrigérateur. */
    public const PREPARED_FRIDGE_DAYS = 3;

    public const PREPARED_FREEZER_MONTHS = 3;

    /** Emplacements de départ, pour un foyer (lot 24) — ou pour l'installation avant les foyers. */
    public static function seedLocations(?int $householdId = null): void
    {
        $perHousehold = \Illuminate\Support\Facades\Schema::hasColumn('storage_locations', 'household_id');
        $householdId ??= $perHousehold ? CurrentHousehold::id() : null;

        foreach (self::LOCATIONS as [$name, $type, $order]) {
            $query = DB::table('storage_locations')->where('name', $name)->when($perHousehold, fn ($q) => $q->where('household_id', $householdId));

            if (! $query->exists()) {
                DB::table('storage_locations')->insert(array_merge([
                    'name' => $name, 'type' => $type->value, 'sort_order' => $order,
                    'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
                ], $perHousehold ? ['household_id' => $householdId] : []));
            }
        }
    }

    /**
     * Réglages proposés pour un ingrédient d'après son rayon, son nom et « produit de base ».
     *
     * @return array{stock_mode: string, storage_location_id: int|null, shelf_life_days: int|null, shelf_life_type: string, days_after_opening: int|null, freezer_months: int|null}
     */
    public static function profileFor(?string $aisleName, string $ingredientName, bool $isStaple): array
    {
        [$mode, $location, $days, $type, $opened, $freezer] = self::AISLE_PROFILES[$aisleName ?? ''] ?? self::AISLE_PROFILES['Divers'];
        $override = self::INGREDIENT_OVERRIDES[$ingredientName] ?? [];

        $mode = $override['mode'] ?? $mode;

        if ($isStaple && $mode !== StockMode::None) {
            $mode = StockMode::Presence;
        }

        $location = array_key_exists('location', $override) ? $override['location'] : $location;

        return [
            'stock_mode' => $mode->value,
            'storage_location_id' => $location ? self::locationId($location) : null,
            'shelf_life_days' => array_key_exists('days', $override) ? $override['days'] : $days,
            'shelf_life_type' => ($override['type'] ?? $type)->value,
            'days_after_opening' => array_key_exists('opened', $override) ? $override['opened'] : $opened,
            'freezer_months' => array_key_exists('freezer', $override) ? $override['freezer'] : $freezer,
        ];
    }

    /** Ingrédients jamais réglés (aucun emplacement ni durée) : réglages de départ. */
    public static function applyToUnconfiguredIngredients(): int
    {
        $rows = DB::table('ingredients')
            ->leftJoin('aisles', 'aisles.id', '=', 'ingredients.aisle_id')
            ->whereNull('ingredients.storage_location_id')
            ->whereNull('ingredients.shelf_life_days')
            ->get(['ingredients.id', 'ingredients.name', 'ingredients.is_staple', 'aisles.name as aisle_name']);

        foreach ($rows as $row) {
            DB::table('ingredients')->where('id', $row->id)
                ->update(self::profileFor($row->aisle_name, $row->name, (bool) $row->is_staple));
        }

        return $rows->count();
    }

    /** Complète un nouvel ingrédient avant sa création (sauf réglages déjà saisis). */
    public static function fill(Ingredient $ingredient): void
    {
        if ($ingredient->storage_location_id !== null || $ingredient->shelf_life_days !== null || $ingredient->isDirty('stock_mode')) {
            return;
        }

        $aisleName = $ingredient->aisle_id ? DB::table('aisles')->where('id', $ingredient->aisle_id)->value('name') : null;
        $ingredient->forceFill(self::profileFor($aisleName, (string) $ingredient->name, (bool) $ingredient->is_staple));
    }

    private static function locationId(string $key): ?int
    {
        return DB::table('storage_locations')->where('name', self::LOCATIONS[$key][0])
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('storage_locations', 'household_id'), fn ($q) => $q->where('household_id', CurrentHousehold::id()))
            ->value('id');
    }
}
