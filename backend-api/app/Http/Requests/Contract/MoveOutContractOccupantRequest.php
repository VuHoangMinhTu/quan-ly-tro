<?php

namespace App\Http\Requests\Contract;

class MoveOutContractOccupantRequest extends StoreContractRequest
{
    public function rules(): array
    {
        return ['move_out_date' => ['required', 'date_format:Y-m-d']];
    }
}
