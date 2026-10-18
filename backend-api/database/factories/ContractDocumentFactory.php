<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\ContractDocument;
use App\Models\ContractTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractDocument>
 */
class ContractDocumentFactory extends Factory
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
            'contract_template_id' => ContractTemplate::factory(),
            'template_key' => fn (array $attributes) => ContractTemplate::findOrFail($attributes['contract_template_id'])->template_key,
            'template_version' => 1, 'template_name' => 'Mẫu minh họa kiểm thử',
            'rendered_html' => '<p>Tài liệu minh họa</p>', 'source_snapshot' => [],
            'document_hash' => hash('sha256', '<p>Tài liệu minh họa</p>'), 'finalized_at' => now(),
        ];
    }
}
