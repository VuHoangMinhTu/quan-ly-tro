<?php

namespace App\Http\Requests\UtilityReading;

use Illuminate\Foundation\Http\FormRequest;

class StoreUtilityReadingRequest extends FormRequest
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
            'reading_date' => ['required', 'date'],
            'reading_value' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
