<?php

namespace Database\Factories;

use App\Enums\Difficulty;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recipe>
 */
class RecipeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => 'Recette '.fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'servings' => 2,
            'prep_minutes' => fake()->randomElement([10, 15, 20, 30]),
            'cook_minutes' => fake()->randomElement([null, 10, 25, 45]),
            'difficulty' => fake()->randomElement(Difficulty::cases()),
        ];
    }

    public function archived(): static
    {
        return $this->state(['archived_at' => now()]);
    }
}
