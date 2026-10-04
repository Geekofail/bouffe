<?php

namespace Database\Factories;

use App\Models\Tag;
use App\Support\Palette;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word()),
            'color' => fake()->randomElement(Palette::keys()),
        ];
    }
}
