<?php

namespace Database\Factories;

use App\Models\Guest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guest>
 */
class GuestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->firstName(),
            'group_name' => null,
            'is_child' => false,
        ];
    }

    public function child(): static
    {
        return $this->state(['is_child' => true]);
    }
}
