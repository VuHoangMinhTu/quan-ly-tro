<?php

namespace Database\Factories;

use App\Models\ContractDocument;
use App\Models\ContractSigningRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractSigningRequest>
 */
class ContractSigningRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contract_document_id' => ContractDocument::factory(),
            'signer_role' => 'TENANT', 'signer_name_snapshot' => fake()->name(),
            'delivery_destination_snapshot' => fake()->safeEmail(),
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'token_expires_at' => now()->addDays(7), 'status' => 'PENDING',
        ];
    }
}
