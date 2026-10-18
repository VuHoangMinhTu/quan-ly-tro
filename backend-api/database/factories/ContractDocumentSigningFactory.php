<?php

namespace Database\Factories;

use App\Models\ContractDocument;
use App\Models\ContractDocumentSigning;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractDocumentSigning>
 */
class ContractDocumentSigningFactory extends Factory
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
            'status' => 'NOT_STARTED',
        ];
    }
}
