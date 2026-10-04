<?php

namespace Database\Factories;

use App\Models\Aisle;
use App\Support\Palette;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Aisle>
 */
class AisleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Rayon '.fake()->unique()->word(),
            'color' => fake()->randomElement(Palette::keys()),
        ];
    }
}
