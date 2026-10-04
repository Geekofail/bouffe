<?php

namespace Database\Factories;

use App\Enums\MealType;
use App\Models\MealSlot;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlannedMeal>
 */
class PlannedMealFactory extends Factory
{
    public function definition(): array
    {
        return [
            'date' => now()->startOfWeek()->toDateString(),
            'meal_slot_id' => MealSlot::factory(),
            'position' => 1,
            'type' => MealType::Recipe,
            'recipe_id' => Recipe::factory(),
            'servings' => 2,
        ];
    }

    public function free(string $text = 'Restaurant'): static
    {
        return $this->state(['type' => MealType::Free, 'recipe_id' => null, 'free_text' => $text]);
    }
}
