<?php

namespace App\Http\Requests\UtilityMeter;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUtilityMeterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'meter_code' => ['nullable', 'string', 'max:100'],
            'initial_reading' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
