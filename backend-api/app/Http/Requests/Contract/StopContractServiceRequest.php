<?php

namespace App\Http\Requests\Contract;

class StopContractServiceRequest extends SaveContractServicesRequest
{
    public function rules(): array
    {
        return ['effective_from' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000']];
    }
}
