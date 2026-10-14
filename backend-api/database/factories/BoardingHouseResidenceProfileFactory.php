<?php

namespace Database\Factories;

use App\Models\BoardingHouseResidenceProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BoardingHouseResidenceProfile>
 */
class BoardingHouseResidenceProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_head_name' => fake()->name(),
            'household_head_identity_number' => fake()->numerify('############'),
        ];
    }
}
