<?php

namespace Database\Factories;

use App\Models\ContractDocument;
use App\Models\ContractSignatureEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractSignatureEvent>
 */
class ContractSignatureEventFactory extends Factory
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
            'actor_type' => 'SYSTEM', 'event' => 'DOCUMENT_FINALIZED', 'created_at' => now(),
        ];
    }
}
