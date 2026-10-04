<?php

namespace Database\Seeders;

use App\Models\Aisle;
use Illuminate\Database\Seeder;

/**
 * Rayons dans un ordre de parcours « générique » de supermarché.
 * L'ordre se modifie ensuite par glisser-déposer dans Paramètres → Rayons.
 */
class AisleSeeder extends Seeder
{
    /** clé technique utilisée par IngredientSeeder => [nom, couleur] */
    public const AISLES = [
        'fruits' => ['Fruits & légumes', 'green'],
        'bakery' => ['Boulangerie', 'amber'],
        'butcher' => ['Boucherie & volaille', 'red'],
        'fish' => ['Poissonnerie', 'sky'],
        'deli' => ['Charcuterie & traiteur', 'pink'],
        'dairy' => ['Crèmerie & œufs', 'yellow'],
        'cheese' => ['Fromages', 'orange'],
        'savory' => ['Épicerie salée', 'lime'],
        'sweet' => ['Épicerie sucrée', 'violet'],
        'cans' => ['Conserves & bocaux', 'teal'],
        'spices' => ['Condiments & épices', 'cyan'],
        'frozen' => ['Surgelés', 'blue'],
        'drinks' => ['Boissons', 'indigo'],
        'home' => ['Hygiène & maison', 'stone'],
        'misc' => ['Divers', 'stone'],
    ];

    public function run(): void
    {
        $position = 1;

        foreach (self::AISLES as [$name, $color]) {
            Aisle::firstOrCreate(['name' => $name], ['color' => $color, 'sort_order' => $position++]);
        }
    }
}
