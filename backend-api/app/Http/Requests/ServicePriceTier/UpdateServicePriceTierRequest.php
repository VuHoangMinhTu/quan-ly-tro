<?php

namespace App\Http\Requests\ServicePriceTier;

use App\Http\Requests\Concerns\ValidatesServiceTierChain;
use App\Models\ServicePriceTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateServicePriceTierRequest extends FormRequest
{
    use ValidatesServiceTierChain;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $tier = $this->ownedTier();
        $tierId = $this->route('id');

        return [
            'from_quantity' => ['required', 'numeric', 'min:0'],
            'to_quantity' => ['nullable', 'numeric', 'gt:from_quantity'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'tier_order' => ['required', 'integer', 'min:1', Rule::unique('service_price_tiers', 'tier_order')->where('service_id', $tier?->service_id ?? 0)->ignore($tierId)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $tier = $this->ownedTier();

            $this->validateServiceTierChain($validator, $tier?->service, $tier?->id);
        });
    }

    private function ownedTier(): ?ServicePriceTier
    {
        return ServicePriceTier::query()
            ->whereKey($this->route('id'))
            ->whereHas('service.boardingHouse', fn ($query) => $query->where('landlord_id', $this->user()?->landlord?->id))
            ->first();
    }
}
