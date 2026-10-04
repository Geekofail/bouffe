<?php

namespace Database\Factories;

use App\Models\MealSlot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealSlot>
 */
class MealSlotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Créneau '.fake()->unique()->word(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
