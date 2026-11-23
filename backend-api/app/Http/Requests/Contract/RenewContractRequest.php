<?php

namespace App\Http\Requests\Contract;

use Illuminate\Foundation\Http\FormRequest;

final class RenewContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'expected_end_date' => ['required', 'date_format:Y-m-d'],
            'new_end_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'agreement_confirmed' => ['required', 'accepted'],
        ];
    }
}
