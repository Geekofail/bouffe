<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    public const TAGS = [
        ['Petit-déjeuner', 'amber'],
        ['Entrée', 'lime'],
        ['Plat', 'orange'],
        ['Accompagnement', 'yellow'],
        ['Dessert', 'pink'],
        ['Apéritif', 'violet'],
        ['Soupe', 'teal'],
        ['Salade', 'green'],
        ['Végétarien', 'green'],
        ['Poisson', 'sky'],
        ['Viande', 'red'],
        ['Rapide', 'cyan'],
        ['Batch cooking', 'indigo'],
        ['Été', 'yellow'],
        ['Hiver', 'blue'],
        ['Fait maison', 'stone'],
    ];

    public function run(): void
    {
        foreach (self::TAGS as [$name, $color]) {
            Tag::firstOrCreate(['name' => $name], ['color' => $color]);
        }
    }
}
