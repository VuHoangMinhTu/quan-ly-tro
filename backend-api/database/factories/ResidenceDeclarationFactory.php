<?php

namespace Database\Factories;

use App\Models\ResidenceDeclaration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResidenceDeclaration>
 */
class ResidenceDeclarationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => 'DRAFT',
            'request_content' => 'Đăng ký tạm trú',
            'authority_name' => null,
            'relationship_to_household_head' => null,
        ];
    }
}
