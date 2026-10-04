<?php

namespace Database\Seeders;

use App\Models\MealSlot;
use Illuminate\Database\Seeder;

class MealSlotSeeder extends Seeder
{
    public const SLOTS = [
        ['Petit-déjeuner', false],
        ['Déjeuner', true],
        ['Goûter', false],
        ['Dîner', true],
    ];

    public function run(): void
    {
        foreach (self::SLOTS as $index => [$name, $active]) {
            MealSlot::firstOrCreate(['name' => $name], ['is_active' => $active, 'sort_order' => $index + 1]);
        }
    }
}
