<?php

namespace Database\Factories;

use App\Models\Aisle;
use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Ingrédient '.fake()->unique()->bothify('??##'),
            'aisle_id' => Aisle::factory(),
            'default_unit_id' => null,
            'piece_weight_g' => null,
            'is_staple' => false,
        ];
    }

    public function staple(): static
    {
        return $this->state(['is_staple' => true]);
    }
}
