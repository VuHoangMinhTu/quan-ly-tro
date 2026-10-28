<?php

namespace App\Http\Requests\Contract;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveHandoverItemsRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'max:100'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'items.*.condition' => ['nullable', 'in:GOOD,DAMAGED,MISSING,OTHER'],
            'items.*.note' => ['nullable', 'string', 'max:1000'],
            'items.*.key_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'items.*.remote_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
