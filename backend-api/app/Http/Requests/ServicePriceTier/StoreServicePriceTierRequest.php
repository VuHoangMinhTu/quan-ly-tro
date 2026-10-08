<?php

namespace App\Http\Requests\ServicePriceTier;

use App\Http\Requests\Concerns\ValidatesServiceTierChain;
use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreServicePriceTierRequest extends FormRequest
{
    use ValidatesServiceTierChain;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $serviceId = $this->ownedService()?->id ?? 0;

        return [
            'from_quantity' => ['required', 'numeric', 'min:0'],
            'to_quantity' => ['nullable', 'numeric', 'gt:from_quantity'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'tier_order' => ['required', 'integer', 'min:1', Rule::unique('service_price_tiers', 'tier_order')->where('service_id', $serviceId)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateServiceTierChain($validator, $this->ownedService()));
    }

    private function ownedService(): ?Service
    {
        return Service::query()
            ->whereKey($this->route('serviceId'))
            ->whereHas('boardingHouse', fn ($query) => $query->where('landlord_id', $this->user()?->landlord?->id))
            ->first();
    }
}
