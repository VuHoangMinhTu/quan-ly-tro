<?php

namespace App\Http\Requests\Service;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['ELECTRICITY', 'WATER', 'INTERNET', 'PARKING', 'TRASH', 'CLEANING', 'OTHER'])],
            'billing_method' => ['required', Rule::in(['FIXED', 'PER_UNIT', 'PER_PERSON', 'TIERED'])],
            'unit' => ['nullable', 'string', 'max:50'],
            'base_price' => ['nullable', 'numeric', 'min:0', 'required_unless:billing_method,TIERED'],
            'is_active' => ['boolean'],
        ];
    }
}
