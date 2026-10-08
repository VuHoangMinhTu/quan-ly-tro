<?php

namespace App\Http\Requests\InvoiceItem;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceItemRequest extends FormRequest
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
            'type' => ['required', 'string', 'max:50'], 'description' => ['required', 'string', 'max:255'], 'quantity' => ['required', 'numeric', 'min:0'], 'unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
