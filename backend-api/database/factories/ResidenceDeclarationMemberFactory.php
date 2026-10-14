<?php

namespace Database\Factories;

use App\Models\ResidenceDeclarationMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResidenceDeclarationMember>
 */
class ResidenceDeclarationMemberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'relationship_to_household_head' => null,
        ];
    }
}
