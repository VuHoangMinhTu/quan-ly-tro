<?php

namespace Database\Factories;

use App\Models\ContractTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractTemplate>
 */
class ContractTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'landlord_id' => null, 'scope_key' => 'GLOBAL',
            'template_key' => strtoupper(fake()->unique()->bothify('DEMO_########')),
            'name' => 'Mẫu minh họa kiểm thử', 'version' => 1,
            'content_html' => '<h1>Hợp đồng {{contract.code}}</h1><p>{{tenant.full_name}}</p>', 'is_active' => true,
        ];
    }
}
