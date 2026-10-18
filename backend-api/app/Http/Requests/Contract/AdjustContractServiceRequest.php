<?php

namespace App\Http\Requests\Contract;

class AdjustContractServiceRequest extends SaveContractServicesRequest
{
    public function rules(): array
    {
        return [
            'contract_service_id' => ['nullable', 'integer', 'min:1', 'required_without:service_id'],
            'service_id' => ['nullable', 'integer', 'min:1', 'required_without:contract_service_id', 'prohibits:contract_service_id'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:2000'],
            ...$this->servicePricingRules(),
        ];
    }
}
