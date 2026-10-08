<?php

namespace App\Http\Requests\Invoice;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
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
            'contract_id' => ['nullable', 'integer', 'exists:contracts,id'], 'invoice_code' => ['required', 'string', 'max:100', 'unique:invoices,invoice_code'], 'billing_period' => ['required', 'date'], 'discount_amount' => ['nullable', 'numeric', 'min:0'], 'status' => ['required', 'in:DRAFT,UNPAID,PARTIALLY_PAID,PAID,CANCELLED'], 'issued_at' => ['nullable', 'date'], 'due_date' => ['nullable', 'date'], 'paid_at' => ['nullable', 'date'], 'note' => ['nullable', 'string'],
        ];
    }
}
