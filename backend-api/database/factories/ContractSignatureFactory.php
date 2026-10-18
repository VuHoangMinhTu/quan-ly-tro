<?php

namespace Database\Factories;

use App\Models\ContractDocument;
use App\Models\ContractSignature;
use App\Models\ContractSigningRequest;
use App\Services\ContractSigningService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractSignature>
 */
class ContractSignatureFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'signing_request_id' => ContractSigningRequest::factory(),
            'contract_document_id' => fn (array $attributes) => ContractSigningRequest::findOrFail($attributes['signing_request_id'])->contract_document_id,
            'signer_role' => 'TENANT', 'signer_name_snapshot' => fake()->name(),
            'verification_method' => 'EMAIL_OTP',
            'signature_file_path' => fake()->uuid().'.png', 'signature_file_hash' => hash('sha256', 'fixture'),
            'document_hash' => fn (array $attributes) => ContractDocument::findOrFail($attributes['contract_document_id'])->document_hash,
            'consent_version' => ContractSigningService::CONSENT_VERSION,
            'consent_text' => ContractSigningService::CONSENT_TEXT, 'signed_at' => now(),
        ];
    }
}
