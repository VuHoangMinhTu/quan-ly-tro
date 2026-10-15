<?php

namespace App\Http\Requests\Contract;

class ChangeContractRoomRequest extends StoreContractRequest
{
    public function rules(): array
    {
        return ['room_id' => ['required', 'integer', 'min:1']];
    }
}
