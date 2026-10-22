<?php

namespace App\Http\Requests\Service;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('vat_rate') && $this->input('vat_rate') === null) {
            $this->merge(['vat_rate' => 0]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function attributes(): array
    {
        return ['vat_rate' => 'thuế VAT'];
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['ELECTRICITY', 'WATER', 'INTERNET', 'PARKING', 'TRASH', 'CLEANING', 'SERVICE', 'OTHER'])],
            'billing_method' => ['required', Rule::in(['FIXED', 'PER_UNIT', 'PER_PERSON', 'TIERED'])],
            'unit' => ['nullable', 'string', 'max:50'],
            'base_price' => ['nullable', 'numeric', 'min:0', 'required_unless:billing_method,TIERED'],
            'is_active' => ['boolean'],
            'vat_rate' => ['sometimes', 'numeric', 'between:0,100', 'decimal:0,2'],
        ];
    }
}
