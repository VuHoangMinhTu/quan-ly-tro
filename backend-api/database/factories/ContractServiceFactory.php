<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\ContractService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractService>
 */
class ContractServiceFactory extends Factory
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
            'service_name' => 'Điện', 'service_type' => 'ELECTRICITY',
            'billing_method' => 'PER_UNIT', 'unit' => 'kWh', 'base_price' => 4000,
            'vat_rate' => 8, 'pricing_snapshot' => [], 'is_active' => true,
        ];
    }
}
