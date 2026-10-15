<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\ContractUtilityOpening;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractUtilityOpening>
 */
class ContractUtilityOpeningFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            'service_name' => 'Điện', 'unit' => 'kWh',
            'opening_reading' => 1800, 'reading_date' => '2026-10-01',
        ];
    }
}
