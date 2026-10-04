<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use App\Support\CurrentHousehold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * R30 : réglages d'un ingrédient du catalogue commun propres à un foyer.
 *
 * La ligne est un **instantané complet** créé à la première modification : tant qu'elle
 * n'existe pas, le foyer voit les valeurs par défaut du catalogue.
 */
class HouseholdIngredientSetting extends Model
{
    use BelongsToHousehold;

    protected $guarded = ['id'];

    /** Réglages de ce foyer, indexés par ingrédient (mémorisés le temps de la requête). @return array<int, array<string, mixed>> */
    public static function map(): array
    {
        $household = (int) CurrentHousehold::id();
        $cache = app()->bound('bouffe.ingredient-settings') ? app('bouffe.ingredient-settings') : [];

        if (! array_key_exists($household, $cache)) {
            $cache[$household] = DB::table('household_ingredient_settings')->where('household_id', $household)->get()
                ->mapWithKeys(fn ($row) => [(int) $row->ingredient_id => (array) $row])->all();
            app()->instance('bouffe.ingredient-settings', $cache);
        }

        return $cache[$household];
    }

    public static function flush(): void
    {
        app()->forgetInstance('bouffe.ingredient-settings');
    }
}
