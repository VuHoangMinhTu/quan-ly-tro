<?php

namespace App\Http\Requests\Contract;

use Illuminate\Validation\Rule;

class ContractStatusRequest extends StoreContractRequest
{
    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['ACTIVE', 'EXPIRED', 'TERMINATED'])],
            'move_out_date' => ['nullable', 'date_format:Y-m-d']];
    }
}
