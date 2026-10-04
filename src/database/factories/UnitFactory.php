<?php

namespace Database\Factories;

use App\Enums\UnitType;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    public function definition(): array
    {
        $code = fake()->unique()->lexify('u???');

        return [
            'code' => $code,
            'label' => $code,
            'label_plural' => null,
            'type' => UnitType::Other,
            'factor_to_base' => null,
            'is_metric' => false,
        ];
    }

    public function gram(): static
    {
        return $this->state(['code' => 'g', 'label' => 'g', 'type' => UnitType::Mass, 'factor_to_base' => 1, 'is_metric' => true]);
    }

    public function kilogram(): static
    {
        return $this->state(['code' => 'kg', 'label' => 'kg', 'type' => UnitType::Mass, 'factor_to_base' => 1000, 'is_metric' => true]);
    }

    public function millilitre(): static
    {
        return $this->state(['code' => 'ml', 'label' => 'ml', 'type' => UnitType::Volume, 'factor_to_base' => 1, 'is_metric' => true]);
    }

    public function piece(): static
    {
        return $this->state(['code' => 'piece', 'label' => 'pièce', 'label_plural' => 'pièces', 'type' => UnitType::Piece]);
    }
}
