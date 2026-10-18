<?php

namespace App\Http\Requests\Contract;

use App\Http\Requests\Concerns\ContractServicePricingRules;
use App\Services\ContractService;
use Illuminate\Foundation\Http\FormRequest;

class SaveContractServicesRequest extends FormRequest
{
    use ContractServicePricingRules;

    public function authorize(): bool
    {
        app(ContractService::class)->find($this->user(), (int) $this->route('id'));

        return true;
    }

    public function rules(): array
    {
        return [
            'sync_from_room' => ['sometimes', 'boolean'],
            'services' => ['present_unless:sync_from_room,true', 'array', 'max:100'],
            'services.*.service_id' => ['required', 'integer', 'min:1', 'distinct'],
            ...$this->servicePricingRules('services.*.'),
        ];
    }
}
