<?php

namespace App\Http\Requests\Payment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'gt:0'], 'payment_method' => ['required', 'in:CASH,BANK_TRANSFER,CARD,OTHER'], 'paid_at' => ['required', 'date'], 'reference_code' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
